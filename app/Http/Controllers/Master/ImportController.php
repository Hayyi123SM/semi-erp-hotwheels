<?php

namespace App\Http\Controllers\Master;

use App\Enums\ConsignorStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Import\PreviewImportRequest;
use App\Http\Requests\Import\UploadImportRequest;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\Rack;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Import\HeaderMatcher;
use App\Services\Import\ImportManager;
use App\Services\Import\ImportResult;
use App\Services\Import\ImportTemplateService;
use App\Services\Master\ConsignorCodeService;
use App\Services\Spreadsheet\SpreadsheetService;
use App\Support\ImportSchemas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function __construct(
        private readonly ImportManager $import,
        private readonly SpreadsheetService $spreadsheet,
        private readonly ConsignorCodeService $codeService,
        private readonly AuditLogger $audit,
        private readonly ImportTemplateService $templates,
        private readonly HeaderMatcher $matcher,
    ) {}

    /**
     * Unduh berkas .xlsx siap isi untuk modul ini.
     *
     * Route-nya harus terdaftar sebelum `/{token}` -- lihat catatan di
     * `routes/web.php`.
     */
    public function template(string $module): StreamedResponse
    {
        $this->ensureModule($module);

        return $this->templates->download($module);
    }

    public function upload(string $module, UploadImportRequest $request)
    {
        $this->ensureModule($module);
        $file = $request->file('import_file');

        try {
            $rows = $this->spreadsheet->rows($file->getPathname());
        } catch (\Throwable) {
            return back()->with('toast', ['type' => 'error', 'message' => 'Berkas tidak dapat dibaca, pastikan format .xlsx/.xls/.csv valid.']);
        }

        if ($rows === []) {
            return back()->with('toast', ['type' => 'error', 'message' => 'Berkas tampak kosong.']);
        }

        $token = $this->import->begin($file, $module, $rows);

        return Redirect::route('master.import.mapping', [$module, $token])
            ->with('toast', ['type' => 'info', 'message' => 'Berkas dimuat ('.count($rows).' baris). Tentukan baris header lalu petakan kolom.']);
    }

    public function mapping(string $module, string $token, Request $request)
    {
        $this->ensureModule($module);
        $payload = $this->payload($module, $token);

        $schema = ImportSchemas::for($module);
        $rows = $payload['rows'];
        $detected = $this->spreadsheet->detectHeaderRow($rows);
        $headerLine = max(0, (int) ($request->query('header') ?? ($payload['header_line'] ?? $detected ?? 0)));
        $lastIndex = count($rows) - 1;
        $headerLine = min($headerLine, max($lastIndex, 0));
        $header = $rows[$headerLine] ?? [];

        // Setiap kali halaman ini dibuka, persetujuan lama dibuang. Buka
        // pemetaan berarti sedang mengedit, dan pengeditan membatalkan
        // persetujuan apa pun yang pernah diberikan -- termasuk ketika
        // pemetaan kolom berubah tanpa baris header ikut berubah.
        $this->import->rememberHeaderRow($token, $headerLine);

        return $this->page('pages.import.mapping', [
            'module' => $module,
            'token' => $token,
            'schema' => $schema,
            'filename' => $payload['filename'],
            'rows' => $rows,
            'header' => $header,
            'headerLine' => $headerLine,
            'detected' => $detected,
            'firstRows' => array_slice($rows, $headerLine, 6),
            'columns' => ImportSchemas::columns(count($header)),
            // Saran pemetaan dihitung ulang setiap kali baris header berubah,
            // karena header yang berbeda berarti daftar kolom yang berbeda juga.
            // Murni saran: view menampilkannya sebagai nilai terpilih awal dan
            // setiap dropdown tetap bisa diganti.
            'suggested' => $this->matcher->suggest($module, $header),
        ], $schema['title']);
    }

    /**
     * Langkah Validasi: baca dan periksa seluruh baris, tapi jangan tulis.
     *
     * Yang dikembalikan di sini bukan "sudah diproses": tidak ada satu pun
     * baris yang menyentuh database. Yang penting, mapping yang dipakai
     * `buildResult()` disimpan ke sesi lebih dulu, sehingga `commit` menulis
     * data yang persis sama dengan yang barusan ditunjukkan ke orang.
     */
    public function preview(string $module, string $token, PreviewImportRequest $request)
    {
        $this->ensureModule($module);
        $payload = $this->payload($module, $token);
        $mapping = $request->validated();

        $result = $this->import->buildResult($module, $payload, $mapping);
        $this->import->rememberMapping($token, $mapping);

        $schema = ImportSchemas::for($module);

        return $this->page('pages.import.validate', [
            'module' => $module,
            'token' => $token,
            'schema' => $schema,
            'filename' => $payload['filename'],
            'result' => $result,
        ], $schema['title'].' — Validasi');
    }

    /**
     * Langkah Selesai: tulis baris yang lolos, lalu buang sesi.
     *
     * `buildResult()` dijalankan ulang, bukan memakai hasil yang sudah
     * dihitung. Alasannya bukan soal kecepatan: pemeriksaan di dalamnya
     * membaca database (kunci unik, referensi seri dan penitip), dan antara
     * pratinjau dan konfirmasi bisa ada yang menambah baris. Menjalankan
     * ulang pemeriksaan terhadap keadaan saat menulis membuat angka di
     * halaman hasil selalu benar tentang apa yang benar-benar tersimpan.
     */
    public function commit(string $module, string $token)
    {
        $this->ensureModule($module);
        $payload = $this->payload($module, $token);
        $mapping = $this->import->mapping($token);

        abort_if(
            $mapping === null,
            409,
            'Peta kolom belum divalidasi. Kembali ke langkah Pemetaan dan jalankan validasi lebih dulu.'
        );

        $result = $this->import->buildResult($module, $payload, $mapping);

        // Tidak ada satu pun baris yang tersimpan berarti tidak ada yang
        // disetujui: halaman Validasi memang tidak pernah menampilkan tombol
        // nya, jadi commit di sini hanya bisa datang dari permintaan yang dibuat
        // langsung. Ditolak sebelum `destroy()`, supaya sesinya masih utuh dan
        // orang bisa memperbaiki pemetaan kolomnya lalu mencoba lagi.
        abort_if(
            $result->successCount() === 0,
            422,
            'Tidak ada baris yang bisa disimpan dari berkas ini. Perbaiki pemetaan kolom atau datanya, lalu validasi lagi.'
        );

        $summary = $this->persistRows($module, $result);

        $this->import->destroy($token);
        $this->audit->imported(ImportSchemas::for($module)['title'], $summary);

        $schema = ImportSchemas::for($module);

        return $this->page('pages.import.result', [
            'module' => $module,
            'schema' => $schema,
            'filename' => $payload['filename'],
            'result' => $result,
            'summary' => $summary,
        ], $schema['title'].' — Selesai');
    }

    /**
     * Sesi impor milik modul ini, atau 404 kalau hilang atau dipakai untuk
     * modul lain.
     *
     * Token acak empat puluh karakter sangat sulit ditebak, tapi pemeriksaan
     * ini tetap mengikat token dengan modulnya: tanpa itu, menukar dua modul
     * pada URL hanya soal menebak token milik orang lain.
     *
     * @return array<string, mixed>
     */
    private function payload(string $module, string $token): array
    {
        $payload = $this->import->find($token) ?: abort(404, 'Sesi impor tidak ditemukan atau telah kedaluwarsa.');
        abort_if($payload['module'] !== $module, 404);

        return $payload;
    }

    public function cancel(string $module, string $token)
    {
        $this->ensureModule($module);
        $this->import->destroy($token);

        return back()->with('toast', ['type' => 'info', 'message' => 'Impor dibatalkan.']);
    }

    private function ensureModule(string $module): void
    {
        abort_unless(in_array($module, ImportSchemas::all(), true), 404, 'Modul impor tidak dikenal.');
    }

    private function persistRows(string $module, ImportResult $result): array
    {
        $success = 0;

        foreach ($result->rows as $entry) {
            try {
                DB::transaction(fn () => $this->createRow($module, $entry['data']));
                $success++;
            } catch (\Throwable $throwable) {
                $result->addFailure($entry['row'], [$this->friendlyError($throwable)]);
            }
        }

        return [
            'module' => $module,
            'total' => $result->total(),
            'success' => $success,
            'failed' => $result->failureCount(),
        ];
    }

    private function createRow(string $module, array $data): void
    {
        $model = match ($module) {
            'penitip' => $this->createConsignor($data),
            'katalog' => Product::create($data),
            'rak' => Rack::create($this->normalizeRack($data)),
            'pengguna' => User::create($data),
            default => throw new \LogicException('Modul tidak didukung.'),
        };

        $this->audit->created($model);
    }

    private function createConsignor(array $data): Consignor
    {
        $data['consignor_code'] = $this->codeService->next();
        $data['status'] ??= ConsignorStatus::Active->value;

        return Consignor::create($data);
    }

    private function normalizeRack(array $data): array
    {
        $data['code'] = strtoupper(trim($data['code']));

        return $data;
    }

    private function friendlyError(\Throwable $throwable): string
    {
        $message = mb_strtolower($throwable->getMessage());

        if (str_contains($message, 'duplicate') || str_contains($message, 'unique')) {
            return 'Data duplikat di database (mis. username, kode, atau WhatsApp yang sama).';
        }

        return 'Gagal disimpan: terjadi kesalahan saat menyimpan baris ini.';
    }
}
