<?php

namespace App\Services\Import;

use App\Enums\ConsignorStatus;
use App\Enums\Role;
use App\Enums\SchemeType;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\Rack;
use App\Services\Master\ConsignorCodeService;
use App\Support\AuditLogger;
use App\Support\Enums;
use App\Support\ImportSchemas;
use App\Support\Numbers;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ImportManager
{
    private const STORAGE_DISK = 'local';

    private const DIRECTORY = 'imports';

    /**
     * Skema bawaan untuk penitip yang dibuat otomatis oleh impor.
     *
     * "Default DB" tidak cukup: consignor dengan skema setengah jadi
     * (PERCENTAGE tanpa rate) akan ditolak `ReceiveStock` saat commit, jadi
     * barisnya mustahil disimpan. Angka ini dipilih agar dokumennya bisa
     * disimpan, dan `notes` menandai owner untuk menyesuaikannya.
     */
    private const AUTO_CREATED_CONSIGNOR_SCHEME_TYPE = SchemeType::Percentage;

    private const AUTO_CREATED_CONSIGNOR_SCHEME_RATE = 20.0;

    /**
     * Kolom yang tidak ikut menentukan apakah dua baris produk sama.
     * Sisanya -- termasuk harga, foto, dan status -- wajib seragam sebelum
     * sebuah duplikat boleh dianggap aman dipakai sebagai wakil.
     */
    private const PRODUCT_IGNORED_COLUMNS = ['id', 'created_at', 'updated_at'];

    private ?array $seriesLookup = null;

    private ?array $consignorLookup = null;

    private ?array $rackLookup = null;

    /**
     * Nama penitip yang dibuat otomatis pada berkas ini, dipetakan ke id-nya.
     * Menjaga satu nama yang sama di banyak baris hanya menghasilkan satu
     * penitip, tanpa perlu menanyakan ulang ke database.
     *
     * @var array<string, int>
     */
    private array $autoCreatedConsignors = [];

    /**
     * Peta pencarian produk. Nama produk tidak unik di tabel `products`, jadi
     * setiap peta memetakan sebuah kunci ke DAFTAR id -- bukan satu id -- dan
     * barulah pemanggil memutuskan apakah kandidatnya tunggal.
     */
    private ?array $productByName = null;

    private ?array $productByAlnum = null;

    private ?array $productBySeriesName = null;

    private ?array $productByCasting = null;

    private ?array $productByColor = null;

    /**
     * Atribut lengkap per id, diisi saat ada kandidat yang perlu diperiksa
     * kemiripannya. Disimpan sebagai array mentah bukan model, supaya tidak
     * ada objek Eloquent yang dibawa di antarapemanggilan.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $productAttributes = [];

    public function __construct(
        private readonly InboundRowMerger $inboundRowMerger,
        private readonly ConsignorCodeService $consignorCodes,
    ) {}

    /**
     * Simpan file & metadata ke session, kembalikan token.
     */
    public function begin(UploadedFile $file, string $module, array $rows): string
    {
        $token = Str::random(40);

        Storage::disk(self::STORAGE_DISK)->putFileAs(
            self::DIRECTORY,
            $file,
            $token.'.'.$file->getClientOriginalExtension(),
        );

        session()->put("import.{$token}", [
            'module' => $module,
            'filename' => $file->getClientOriginalName(),
            'rows' => $rows,
            'header_line' => null,
            'preview' => null,
            'created_at' => now()->timestamp,
        ]);

        return $token;
    }

    /**
     * Simpan mapping yang baru saja ditampilkan ke layar validasi.
     *
     * Disimpan, bukan dikirim lagi lewat formulir, karena dua alasan. Pertama,
     * `commit` lalu menulis persis apa yang sudah dilihat dan disetujui orang;
     * kalau mapping ikut lagi di formulir, isi POST bisa berbeda dari yang
     * ditampilkan tanpa jejak. Kedua, `header_row` bisa saja berbeda dari
     * yang terakhir dilihat -- pindah ke halaman pemetaan lalu kembali tidak
     * mengubah apa pun, dan mapping lama harus ikut terbuang.
     */
    public function rememberMapping(string $token, array $mapping): void
    {
        $payload = $this->find($token);

        if ($payload === null) {
            return;
        }

        $payload['preview'] = [
            'mapping' => [
                'header_row' => (int) ($mapping['header_row'] ?? 0),
                'map' => $mapping['map'] ?? [],
                'defaults' => $mapping['defaults'] ?? [],
            ],
            'at' => now()->timestamp,
        ];

        session()->put("import.{$token}", $payload);
    }

    /**
     * Catat baris header yang dipilih, sekalian buang persetujuan yang lama.
     *
     * Dipanggil setiap kali halaman pemetaan dibuka -- bukan hanya ketika
     * baris header berubah -- karena halaman itu adalah tempat semua yang bisa
     * membuat persetujuan basi diubah: baris header, pemetaan kolom, nilai
     * default. Membuang preview hanya saat `header_row` berubah tidak cukup;
     * memetakan ulang kolom tanpa menyentuh baris header sama saja membuat
     * layar validasi yang disetujui orang tidak lagi cocok dengan isinya.
     *
     * Baris headernya ikut disimpan supaya pilihan itu bertahan. Tanpa ini,
     * mundur dari Validasi lewat stepper -- yang menuju URL tanpa query --
     * akan kembali ke baris hasil deteksi, seolah-olah pilihannya tidak pernah
     * ada.
     */
    public function rememberHeaderRow(string $token, int $headerLine): void
    {
        $payload = $this->find($token);

        if ($payload === null) {
            return;
        }

        $payload['header_line'] = $headerLine;
        $payload['preview'] = null;

        session()->put("import.{$token}", $payload);
    }

    /**
     * Mapping yang terakhir kali ditampilkan dan disetujui, kalau ada.
     */
    public function mapping(string $token): ?array
    {
        $mapping = $this->find($token)['preview']['mapping'] ?? null;

        return is_array($mapping) ? $mapping : null;
    }

    public function find(string $token): ?array
    {
        $payload = session()->get("import.{$token}");

        return is_array($payload) ? $payload : null;
    }

    public function destroy(string $token): void
    {
        foreach (['xlsx', 'xls', 'csv'] as $extension) {
            Storage::disk(self::STORAGE_DISK)->delete(self::DIRECTORY.'/'.$token.'.'.$extension);
        }
        session()->forget("import.{$token}");
    }

    /**
     * Bangun hasil normalisasi & validasi seluruh baris dari payload & mapping.
     */
    public function buildResult(string $module, array $payload, array $mapping): ImportResult
    {
        $schema = ImportSchemas::for($module);
        $rows = $payload['rows'];
        $headerIndex = (int) ($mapping['header_row'] ?? 0);

        if ($headerIndex < 0 || $headerIndex >= count($rows)) {
            throw ValidationException::withMessages([
                'header_row' => 'Baris header yang dipilih tidak valid. File mungkin tidak memiliki cukup baris.',
            ]);
        }

        $columnMap = $this->columnIndexes($mapping['map'] ?? [], $rows[$headerIndex]);
        $defaults = array_merge($this->schemaDefaults($schema), $mapping['defaults'] ?? []);
        $allowSeriesAutoCreate = $module === 'katalog' && $this->autoCreateSeriesEnabled($mapping);
        $result = new ImportResult;

        $dataRows = array_slice($rows, $headerIndex + 1);
        $firstExcelRow = $headerIndex + 2; // baris 1-based pada dokumen asli
        $result->sourceRowCount = count($dataRows);

        [$logicalRows, $mergeFailures] = $this->logicalRows($module, $dataRows, $firstExcelRow, $columnMap, $schema);

        foreach ($mergeFailures as $failure) {
            $result->addFailure($failure['row'], $failure['errors']);
        }

        foreach ($logicalRows as $logical) {
            $excelRow = $logical['excelRow'];
            $storeRow = $this->normalizeRow($schema, $logical['cells'], $columnMap, $defaults);

            [$preErrors, $storeRow, $preWarnings] = $this->resolveReferences($schema, $storeRow, $mapping, $allowSeriesAutoCreate);

            foreach ($preWarnings as $preWarning) {
                $result->addWarning([$excelRow], [$preWarning], ImportResult::KIND_DUPLICATE);
            }

            if ($preErrors !== []) {
                $result->addFailure($excelRow, $preErrors);

                continue;
            }

            $validator = Validator::make($storeRow, $this->rulesFor($schema));

            if ($validator->fails()) {
                $result->addFailure($excelRow, array_values($validator->errors()->all()));

                continue;
            }

            $result->addSuccess(
                $storeRow,
                $excelRow,
                [$excelRow],
                $logical['anchorRow'],
                $this->rowLabel($logical['cells'], $columnMap, $schema),
            );
        }

        if (ImportSchemas::isInbound($module)) {
            $this->inboundRowMerger->consolidate($result, $schema['items']);
        }

        return $result;
    }

    /**
     * Baris-baris yang akan divalidasi, beserta baris induknya.
     *
     * Modul master bersifat baris-per-baris: baris induk selalu baris itu
     * sendiri. Modul Inbound bisa memuat baris lanjutan dari sel merge
     * Excel, yang dikenali dan diwarisi oleh `InboundRowMerger`.
     *
     * @param  array<int, array<int, mixed>>  $dataRows
     * @param  array<string, int>  $columnMap
     * @return array{0: list<array{cells: array<int, mixed>, excelRow: int, anchorRow: int}>, 1: list<array{row: int, errors: list<string>}>}
     */
    private function logicalRows(string $module, array $dataRows, int $firstExcelRow, array $columnMap, array $schema): array
    {
        if (! ImportSchemas::isInbound($module)) {
            $rows = [];

            foreach (array_values($dataRows) as $offset => $cells) {
                $excelRow = $firstExcelRow + $offset;
                $rows[] = ['cells' => $cells, 'excelRow' => $excelRow, 'anchorRow' => $excelRow];
            }

            return [$rows, []];
        }

        $merged = $this->inboundRowMerger->fillDown($dataRows, $firstExcelRow, $columnMap, $schema['items']);

        return [$merged['rows'], $merged['failures']];
    }

    /**
     * Ubah satu baris sel mentah menjadi nilai siap validasi per store.
     *
     * @param  array<int, mixed>  $cells
     * @param  array<string, int>  $columnMap
     * @return array<string, mixed>
     */
    private function normalizeRow(array $schema, array $cells, array $columnMap, array $defaults): array
    {
        $storeRow = [];
        $combined = [];

        foreach ($schema['items'] as $item) {
            $store = $item['store'];
            $typed = $this->typedValue($item, $this->cellValue($cells, $item['key'], $columnMap, $defaults));

            if (($item['combineOrder'] ?? null) !== null) {
                if ($typed !== null) {
                    $combined[$store][] = $typed;
                }

                continue;
            }

            $storeRow[$store] = $typed;
        }

        foreach ($combined as $store => $parts) {
            $storeRow[$store] = trim(implode(' ', $parts));
        }

        return $storeRow;
    }

    /**
     * Nama yang dipakai untuk menunjukkan baris ini ke orang, diambil dari
     * sel mentah sebelum nilainya dipetakan ke id. Setelah `resolveReferences`
     * berjalan, `product_id` sudah berupa angka dan namanya lenyap -- padahal
     * halaman Validasi butuh nama produk untuk baris yang digabung.
     *
     * @param  array<int, mixed>  $cells
     * @param  array<string, int>  $columnMap
     */
    private function rowLabel(array $cells, array $columnMap, array $schema): ?string
    {
        $fallback = null;

        foreach ($schema['items'] as $item) {
            if (($item['identity'] ?? false) !== true) {
                continue;
            }

            $column = $columnMap[$item['key']] ?? null;
            $value = $column === null ? null : trim((string) ($cells[$column] ?? ''));

            if ($value === '') {
                continue;
            }

            if (($item['lookup'] ?? null) === 'product') {
                return $value;
            }

            $fallback ??= $value;
        }

        return $fallback;
    }

    /**
     * Nilai bawaan per kolom (mis. scheme_type = PERCENTAGE) dari skema.
     */
    private function schemaDefaults(array $schema): array
    {
        $defaults = [];

        foreach ($schema['items'] as $item) {
            if (array_key_exists('default', $item)) {
                $defaults[$item['key']] = is_bool($item['default'])
                    ? ($item['default'] ? 'YA' : 'TIDAK')
                    : (string) $item['default'];
            }
        }

        return $defaults;
    }

    private function columnIndexes(array $map, array $headerRow): array
    {
        $indexes = [];
        foreach ($map as $key => $column) {
            if ($column === null || $column === '') {
                continue;
            }

            $index = Coordinate::columnIndexFromString((string) $column) - 1;

            if (array_key_exists($index, $headerRow)) {
                $indexes[$key] = $index;
            }
        }

        return $indexes;
    }

    private function cellValue(array $row, string $key, array $columnMap, array $defaults): ?string
    {
        $value = null;

        if (isset($columnMap[$key])) {
            $raw = $row[$columnMap[$key]] ?? null;

            if (is_scalar($raw) && trim((string) $raw) !== '') {
                $value = (string) $raw;
            }
        }

        $default = $defaults[$key] ?? null;

        if ((($value ?? '') === '') && $default !== null && $default !== '') {
            return (string) $default;
        }

        return $value;
    }

    private function typedValue(array $item, ?string $value): mixed
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return match ($item['type']) {
            'money' => Numbers::integer($value),
            // Read the same way the form is, so a spreadsheet cell holding
            // `1.000` is a thousand here as well. Casting it instead --
            // `(int) '1.000'` is `1` -- is the under-count this replaced,
            // and for a capacity or a year it would be a silent one.
            'integer' => Numbers::integer($value),
            'percentage' => Numbers::rate($value),
            'boolean' => $this->normalizeBoolean($value),
            'tags' => $this->normalizeTags($value),
            'date' => $this->normalizeDate($value),
            'enum' => Enums::fromAny($item['enum'], $value),
            default => trim($value),
        };
    }

    /**
     * Ganti nilai referensi (produk, seri, penitip, rak) dengan id-nya.
     *
     * @return array{0: list<string>, 1: array<string, mixed>, 2: list<string>} Kesalahan memblokir baris, baris yang sudah dinormalisasi, peringatan yang tidak memblokir.
     */
    private function resolveReferences(array $schema, array $storeRow, array $mapping, bool $allowSeriesAutoCreate = false): array
    {
        $errors = [];
        $warnings = [];

        foreach ($schema['items'] as $item) {
            if (($item['lookup'] ?? null) === null) {
                continue;
            }

            $value = $storeRow[$item['store']] ?? null;

            // Nama produk boleh berupa angka ("911"), jadi hanya lookup product
            // yang dikecualikan dari penjagaan nilai numerik di bawah.
            if ($value === null || $value === '' || (is_numeric($value) && $item['lookup'] !== 'product')) {
                continue;
            }

            $rawValue = (string) $value;
            $displayName = trim($rawValue);
            $lookupValue = $displayName === '' ? '' : mb_strtolower(preg_replace('/\s+/', ' ', $displayName));
            if ($lookupValue === '') {
                $lookupValue = mb_strtolower($displayName);
            }

            if ($item['lookup'] === 'product') {
                $resolved = $this->resolveProductId(
                    (string) ($storeRow['series_name'] ?? ''),
                    $displayName,
                    (string) ($storeRow['casting_code'] ?? ''),
                    (string) ($storeRow['color'] ?? ''),
                    (string) $item['label'],
                    $errors,
                    $warnings,
                );

                if ($resolved !== null) {
                    $storeRow[$item['store']] = $resolved;
                }

                continue;
            }

            $resolved = match ($item['lookup']) {
                'series' => $this->seriesLookup()[$lookupValue] ?? $this->seriesLookup()[mb_strtolower($displayName)] ?? $this->findSeriesByExactName($rawValue) ?? $this->findSeriesFuzzy($rawValue),
                'consignor' => $this->consignorLookup()[$lookupValue] ?? $this->consignorLookup()[mb_strtolower($displayName)] ?? null,
                'rack' => $this->rackLookup()[$lookupValue] ?? $this->rackLookup()[mb_strtolower($displayName)] ?? null,
                default => null,
            };

            if ($resolved === null && $item['lookup'] === 'series' && $allowSeriesAutoCreate) {
                $resolved = $this->resolveSeriesWithAutoCreate($rawValue, $displayName, $lookupValue, $errors, $item);
                if ($resolved !== null) {
                    $storeRow[$item['store']] = $resolved;

                    continue;
                }
            }

            if ($resolved === null && ($item['autoCreate'] ?? null) === 'consignor' && $this->autoCreateConsignorEnabled($mapping)) {
                $resolved = $this->resolveConsignorWithAutoCreate($rawValue, $displayName, $lookupValue, $errors, $item);
                if ($resolved !== null) {
                    $storeRow[$item['store']] = $resolved;

                    continue;
                }
            }

            if ($resolved === null) {
                $errors[] = "Kolom {$item['label']}: \"{$value}\" tidak ditemukan.";

                continue;
            }

            // Cast to int so the `integer` validation rule passes for ID lookups.
            $storeRow[$item['store']] = (int) $resolved;
        }

        return [$errors, $storeRow, $warnings];
    }

    /**
     * Apakah pembuatan Seri baru diaktifkan untuk impor ini.
     *
     * Dibaca dari mapping tervalidasi (bukan dari request) supaya langkah
     * "commit" memakai setelan yang sama dengan pratinjau: mapping itu sudah
     * disimpan ke sesi saat validasi.
     */
    private function autoCreateSeriesEnabled(array $mapping): bool
    {
        return $this->flagEnabled(
            ($mapping['defaults'] ?? [])['auto_create_series']
                ?? ($mapping['options'] ?? [])['auto_create_series']
                ?? null
        );
    }

    private function seriesLookup(): array
    {
        if ($this->seriesLookup === null) {
            $this->seriesLookup = [];
            foreach (ProductSeries::select('id', 'name', 'code')->get() as $series) {
                $code = $series->code;
                if ($code !== null && $code !== '') {
                    $this->seriesLookup[mb_strtolower(trim($code))] = $series->id;
                }
                $name = $series->name;
                if ($name !== null && $name !== '') {
                    $this->seriesLookup[mb_strtolower(preg_replace('/\s+/', ' ', trim($name)))] = $series->id;
                }
            }
        }

        return $this->seriesLookup;
    }

    private function consignorLookup(): array
    {
        if ($this->consignorLookup === null) {
            $this->consignorLookup = [];
            foreach (Consignor::select('id', 'name', 'consignor_code')->get() as $consignor) {
                $code = $consignor->consignor_code;
                if ($code !== null && $code !== '') {
                    $this->consignorLookup[mb_strtolower(trim($code))] = $consignor->id;
                }
                $name = $consignor->name;
                if ($name !== null && $name !== '') {
                    $this->consignorLookup[mb_strtolower(preg_replace('/\s+/', ' ', trim($name)))] = $consignor->id;
                }
            }
        }

        return $this->consignorLookup;
    }

    /**
     * Apakah pembuatan Penitip baru diaktifkan untuk impor ini.
     *
     * Dibaca dari mapping tervalidasi (bukan dari request) supaya langkah
     * "commit" memakai setelan yang sama dengan pratinjau: mapping itu sudah
     * disimpan ke sesi saat validasi.
     */
    private function autoCreateConsignorEnabled(array $mapping): bool
    {
        return $this->flagEnabled(
            ($mapping['defaults'] ?? [])['auto_create_consignor']
                ?? ($mapping['options'] ?? [])['auto_create_consignor']
                ?? null
        );
    }

    /**
     * Buat Penitip baru dari nilai kolom, lalu daftarkan ke cache lookup.
     *
     * Kolom pada berkas berisi nama penitip, bukan kodenya. Pemanggil sudah
     * memastikan modul Consignment In dan opsi auto-create aktif, jadi di sini
     * hanya tersisa pemeriksaan izin dan pembuatan barisnya. Nilai yang
     * bentuknya seperti kode penitip (CNxx) tidak pernah dibuatkan: nama
     * "CN99" bukan nama orang, dan menolaknya membuat salah ketik tetap
     * terbaca sebagai kesalahan, bukan diam-diam jadi master data baru.
     */
    private function resolveConsignorWithAutoCreate(string $rawValue, string $displayName, string $lookupValue, array &$errors, array $item): ?int
    {
        if (! auth()->check() || auth()->user()->role !== Role::Owner) {
            $errors[] = "Kolom {$item['label']}: \"{$displayName}\" tidak ditemukan. Hanya Owner yang dapat membuat Penitip baru melalui impor.";

            return null;
        }

        if ($displayName === '' || $this->looksLikeConsignorCode($displayName)) {
            return null;
        }

        $targetName = $this->normName($rawValue);
        if ($targetName === '') {
            return null;
        }

        // Nama yang sama sudah dibuat pada baris sebelumnya dalam berkas ini.
        if (isset($this->autoCreatedConsignors[$targetName])) {
            return $this->rememberConsignor($lookupValue, $targetName, $this->autoCreatedConsignors[$targetName]);
        }

        try {
            $consignor = Consignor::create($this->newConsignorAttributes($displayName));
            AuditLogger::created($consignor);
        } catch (\Throwable) {
            $errors[] = "Kolom {$item['label']}: \"{$displayName}\" tidak ditemukan dan gagal dibuat otomatis.";

            return null;
        }

        return $this->rememberConsignor($lookupValue, $targetName, $consignor->id, $consignor->name, $consignor->consignor_code);
    }

    /**
     * Atribut penitip yang dibuat otomatis oleh impor.
     *
     * Skemanya sengaja lengkap, bukan sekadar default DB: consignor dengan
     * PERCENTAGE tanpa rate akan ditolak `ReceiveStock` saat commit, sehingga
     * baris impornya mustahil disimpan. `notes` menandai agar owner sempat
     * menyesuaikan bagi hasilnya.
     *
     * @return array<string, mixed>
     */
    private function newConsignorAttributes(string $displayName): array
    {
        return [
            'consignor_code' => $this->consignorCodes->next(),
            'name' => $displayName,
            'scheme_type' => self::AUTO_CREATED_CONSIGNOR_SCHEME_TYPE,
            'scheme_rate' => self::AUTO_CREATED_CONSIGNOR_SCHEME_RATE,
            'status' => ConsignorStatus::Active,
            'notes' => 'Dibuat otomatis via impor Consignment In. Skema komisi masih bawaan (20%) - sesuaikan bila perlu.',
        ];
    }

    /**
     * Simpan hasil resolve ke cache lookup agar tidak dihitung ulang.
     */
    private function rememberConsignor(string $lookupValue, string $targetName, int $id, ?string $name = null, ?string $code = null): int
    {
        $this->consignorLookup();

        $this->consignorLookup[$lookupValue] = $id;
        $this->consignorLookup[$targetName] = $id;

        if ($name !== null && $name !== '') {
            $this->consignorLookup[$this->normName($name)] = $id;
        }

        if ($code !== null && $code !== '') {
            $this->consignorLookup[mb_strtolower(trim($code))] = $id;
        }

        $this->autoCreatedConsignors[$targetName] = $id;

        return $id;
    }

    /**
     * Bentuk nilai yang menyerupai kode penitip (CN01, CN99, ...). Nilai
     * seperti ini adalah rujukan, bukan nama, sehingga tidak pernah dibuat.
     */
    private function looksLikeConsignorCode(string $value): bool
    {
        return preg_match('/^CN\d+$/i', trim($value)) === 1;
    }

    /**
     * Bendera opsi yang datang dari formulir sebagai teks ("YA"/"1"/"true").
     */
    private function flagEnabled(mixed $flag): bool
    {
        if (is_bool($flag)) {
            return $flag;
        }

        if (is_numeric($flag)) {
            return (int) $flag === 1;
        }

        if (is_string($flag)) {
            return in_array(mb_strtolower(trim($flag)), ['ya', 'true', '1', 'yes', 'on'], true);
        }

        return false;
    }

    private function rackLookup(): array
    {
        if ($this->rackLookup === null) {
            $this->rackLookup = [];
            foreach (Rack::select('id', 'code')->get() as $rack) {
                $code = $rack->code;
                if ($code !== null && $code !== '') {
                    $this->rackLookup[mb_strtolower(trim((string) $code))] = $rack->id;
                }
            }
        }

        return $this->rackLookup;
    }

    /**
     * Kumpulkan peta pencarian produk sekali jalan.
     *
     * Nama produk sengaja dicocokkan pada dua bentuk -- spasi dinormalkan dan
     * hanya alfanumerik -- karena berkas datang dengan "Honda NSX" dan
     * "Honda-NSX" yang keduanya menunjuk produk yang sama. Kode casting hanya
     * dipakai sebagai pemutus, bukan pencari utama: itu memang perannya.
     */
    private function loadProductLookups(): void
    {
        if ($this->productByName !== null) {
            return;
        }

        $this->productByName = [];
        $this->productByAlnum = [];
        $this->productBySeriesName = [];
        $this->productByCasting = [];
        $this->productByColor = [];

        foreach (Product::select('id', 'name', 'series_id', 'casting_code', 'color')->cursor() as $product) {
            $nameKey = $this->normName((string) $product->name);
            $alnumKey = $this->normalizeAlnum((string) $product->name);

            if ($nameKey !== '') {
                $this->productByName[$nameKey][] = $product->id;
            }

            if ($alnumKey !== '') {
                $this->productByAlnum[$alnumKey][] = $product->id;
            }

            if ($product->series_id !== null && $nameKey !== '') {
                $this->productBySeriesName[$product->series_id.'|'.$nameKey][] = $product->id;
            }

            $castingKey = $this->normalizeAlnum((string) $product->casting_code);
            if ($castingKey !== '') {
                $this->productByCasting[$castingKey][] = $product->id;
            }

            $colorKey = $this->normalizeAlnum((string) $product->color);
            if ($colorKey !== '') {
                $this->productByColor[$colorKey][] = $product->id;
            }
        }
    }

    private function resolveSeriesId(string $raw): ?int
    {
        $name = trim($raw);

        if ($name === '') {
            return null;
        }

        $normalized = mb_strtolower(preg_replace('/\s+/', ' ', $name));

        return $this->seriesLookup()[$normalized]
            ?? $this->seriesLookup()[mb_strtolower($name)]
            ?? $this->findSeriesByExactName($name)
            ?? $this->findSeriesFuzzy($name);
    }

    /**
     * Cocokkan nama produk ke satu id, dengan kategori, kode casting, dan
     * warna sebagai penentu. Mengembalikan `null` sekaligus menuliskan
     * alasannya bila gagal.
     *
     * Urutan pencocokan: nama di dalam kategori yang cocok lebih dulu, lalu
     * nama saja, lalu bentuk alfanumerik. Kandidat yang masih lebih dari satu
     * dipersempit oleh kode casting dan warna.
     *
     * Sisa lebih dari satu bisa berarti dua hal: baris master yang memang
     * berbeda, atau baris yang identik semua karena pernah terbuat dua kali.
     * Keduanya aman dipilihkan satu wakil disertai peringatan: menebak tidak
     * pernah menjegal impor, dan peringatannya menyebut id yang dipakai
     * supaya kejadiannya tetap terbaca. Kandidat nol tetap sebuah kegagalan --
     * tidak ada produk yang bisa mewakili baris itu sama sekali.
     *
     * @param  array<int, string>  $errors
     * @param  array<int, string>  $warnings
     */
    private function resolveProductId(string $seriesRaw, string $nameRaw, string $castingRaw, string $colorRaw, string $label, array &$errors, array &$warnings): ?int
    {
        $name = trim($nameRaw);

        if ($name === '') {
            $errors[] = "Kolom {$label}: nama produk kosong.";

            return null;
        }

        $this->loadProductLookups();

        $seriesId = $this->resolveSeriesId($seriesRaw);
        $nameKey = $this->normName($name);
        $alnumKey = $this->normalizeAlnum($name);

        $candidates = [];
        if ($seriesId !== null && isset($this->productBySeriesName[$seriesId.'|'.$nameKey])) {
            $candidates = $this->productBySeriesName[$seriesId.'|'.$nameKey];
        }

        if ($candidates === []) {
            $candidates = $this->productByName[$nameKey] ?? $this->productByAlnum[$alnumKey] ?? [];
        }

        $candidates = $this->narrowBy($candidates, $this->normalizeAlnum($castingRaw), $this->productByCasting);
        $candidates = $this->narrowBy($candidates, $this->normalizeAlnum($colorRaw), $this->productByColor);

        if ($candidates === []) {
            $seriesNote = $seriesRaw !== '' ? " pada kategori \"{$seriesRaw}\"" : '';
            $errors[] = "Kolom {$label}: produk \"{$name}\"{$seriesNote} tidak ditemukan.";

            return null;
        }

        if (count($candidates) > 1) {
            $sorted = array_values(array_unique($candidates));
            sort($sorted);

            if ($this->identicalProducts($sorted)) {
                $warnings[] = sprintf(
                    '%s: "%s" cocok dengan %d produk yang isinya identik; dipakai id %d.',
                    $label,
                    $name,
                    count($sorted),
                    $sorted[0],
                );
            } else {
                $warnings[] = $this->ambiguousProductMessage($sorted, $name, $label);
            }

            return (int) $sorted[0];
        }

        return (int) $candidates[0];
    }

    /**
     * Persempit daftar kandidat dengan satu peta pencarian. Bila tidak ada
     * kandidat yang cocok, daftar asal dikembalikan -- kuncinya bisa saja
     * berbeda penulisan dari nilai di berkas, dan mengosongkan kandidat karena
     * beda ejaan justru menutup peluang produknya ditemukan.
     *
     * @param  array<int, int>  $candidates
     * @param  array<string, array<int, int>>|null  $lookup
     * @return array<int, int>
     */
    private function narrowBy(array $candidates, string $key, ?array $lookup): array
    {
        if (count($candidates) <= 1 || $key === '' || ! isset($lookup[$key])) {
            return $candidates;
        }

        $narrowed = array_values(array_intersect($candidates, $lookup[$key]));

        return $narrowed !== [] ? $narrowed : $candidates;
    }

    /**
     * Benarkah semua kandidat berisi hal yang sama? Dua baris hanya dianggap
     * identik bila seluruh kolomnya seragam, kecuali yang memang penanda baris
     * (id dan timestamp pembuatan).
     *
     * @param  array<int, int>  $ids
     */
    private function identicalProducts(array $ids): bool
    {
        $attributes = $this->productAttributes($ids);

        if (count($attributes) !== count($ids)) {
            return false;
        }

        $signatures = [];

        foreach ($attributes as $product) {
            foreach (self::PRODUCT_IGNORED_COLUMNS as $column) {
                unset($product[$column]);
            }

            ksort($product);
            $signatures[] = serialize($product);
        }

        return count(array_unique($signatures)) === 1;
    }

    /**
     * Atribut lengkap beberapa produk, dengan cache per id supaya kandidat
     * yang ambigu di banyak baris tidak menarik query yang sama berulang.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function productAttributes(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $missing = array_values(array_diff($ids, array_keys($this->productAttributes)));

        if ($missing !== []) {
            foreach (Product::query()->whereIn('id', $missing)->get() as $product) {
                $this->productAttributes[$product->id] = $product->getAttributes();
            }
        }

        return array_intersect_key($this->productAttributes, array_flip($ids));
    }

    /**
     * Pesan peringatan untuk kandidat yang berbeda-beda: sebutkan warna yang
     * memisahkan mereka dan id terkecil yang dipakai sebagai wakil, plus
     * dorongan memeriksa katalog. Kode Casting tidak disarankan karena kolom
     * itu boleh kosong di hampir seluruh baris produk.
     *
     * @param  array<int, int>  $candidates
     */
    private function ambiguousProductMessage(array $candidates, string $name, string $label): string
    {
        $colors = [];

        foreach ($this->productAttributes($candidates) as $product) {
            $color = trim((string) ($product['color'] ?? ''));

            if ($color !== '') {
                $colors[$color] = true;
            }
        }

        $colorNote = $colors === [] ? '' : ' (Warna: '.implode(', ', array_keys($colors)).')';

        return sprintf(
            'Kolom %s: "%s" cocok dengan %d produk berbeda%s; dipakai id %d. Periksa katalog bila produknya keliru.',
            $label,
            $name,
            count($candidates),
            $colorNote,
            $candidates[0],
        );
    }

    private function rulesFor(array $schema): array
    {
        $rules = [];

        foreach ($schema['items'] as $item) {
            foreach ($item['rules'] ?? [] as $store => $fieldRules) {
                $rules[$store] = $fieldRules;
            }
        }

        return $rules;
    }

    private function normalizeBoolean(string $value): ?bool
    {
        $lower = mb_strtolower(trim($value));

        if (in_array($lower, ['1', 'ya', 'y', 'yes', 'true', 'aktif', 'active', 'on'], true)) {
            return true;
        }

        if (in_array($lower, ['0', 'tidak', 't', 'no', 'false', 'nonaktif', 'inactive', 'off', '-'], true)) {
            return false;
        }

        return true;
    }

    private function normalizeTags(string $value): array
    {
        return collect(preg_split('/[;,]/', $value))
            ->map(fn ($tag) => trim((string) $tag))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeDate(string $value): ?string
    {
        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        $parsed = date_parse($value);

        if (($parsed['error_count'] ?? 1) > 0 || ! ($parsed['year'] ?? null)) {
            return $value; // biarkan rule 'date' yang menolak
        }

        return sprintf('%04d-%02d-%02d', $parsed['year'], $parsed['month'] ?: 1, $parsed['day'] ?: 1);
    }

    private function normalizeAlnum(string $value): string
    {
        return mb_strtolower(preg_replace('/[^a-z0-9]+/', '', trim($value)));
    }

    private function tokenSet(string $value): array
    {
        $tokens = preg_split('/\s+/', mb_strtolower(trim($value)));
        $tokens = array_filter($tokens ?? [], fn ($t) => $t !== '');

        return array_values(array_unique($tokens));
    }

    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $intersect = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : $intersect / $union;
    }

    private function findSeriesFuzzy(string $value): ?int
    {
        $lookup = $this->seriesLookup();
        $keys = array_keys($lookup);
        if ($keys === []) {
            return null;
        }

        $targetAlnum = $this->normalizeAlnum($value);
        $targetTokens = $this->tokenSet($value);
        $bestId = null;
        $bestScore = 0.0;
        $hasAmbiguity = false;

        foreach (ProductSeries::select('id', 'name', 'code')->get() as $series) {
            $cands = [];
            if ($series->code !== null && $series->code !== '') {
                $cands[] = (string) $series->code;
            }
            if ($series->name !== null && $series->name !== '') {
                $cands[] = (string) $series->name;
            }
            foreach ($cands as $cand) {
                $score = 0.0;
                if ($this->normalizeAlnum($cand) === $targetAlnum) {
                    $score = 1.0;
                } else {
                    $j = $this->jaccard($this->tokenSet($cand), $targetTokens);
                    if ($j > $score) {
                        $score = $j;
                    }
                }
                if ($score >= 0.9 && $bestScore < 0.9) {
                    $bestScore = $score;
                    $bestId = $series->id;
                    $hasAmbiguity = false;
                } elseif ($score >= 0.85) {
                    if ($bestId !== null && $bestId !== $series->id) {
                        $hasAmbiguity = true;
                    } elseif ($bestScore < $score) {
                        $bestScore = $score;
                        $bestId = $series->id;
                    }
                }
            }
        }

        if ($hasAmbiguity) {
            return null;
        }

        return $bestScore >= 0.9 ? $bestId : null;
    }

    private array $autoCreatedSeries = [];

    private function normName(string $v): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($v)));
    }

    private function findSeriesByExactName(string $value): ?int
    {
        $target = $this->normName($value);
        if ($target === '') {
            return null;
        }
        foreach (ProductSeries::select('id', 'name')->get() as $series) {
            if ($this->normName((string) $series->name) === $target) {
                return $series->id;
            }
        }

        return null;
    }

    private function generateSeriesCode(string $name): string
    {
        $base = preg_replace('/[^a-z0-9]+/i', ' ', trim($name));
        $base = trim(mb_strtoupper(preg_replace('/\s+/', ' ', (string) $base)));
        if ($base === '') {
            $base = 'SERIES';
        }
        $parts = preg_split('/\s+/', $base);
        $parts = array_filter($parts ?? [], fn ($p) => $p !== '');
        $candidate = '';
        if (count($parts) === 1) {
            $candidate = mb_substr($parts[0], 0, 10);
        } else {
            foreach ($parts as $p) {
                $candidate .= mb_substr($p, 0, 3);
                if (mb_strlen($candidate) >= 12) {
                    break;
                }
            }
        }
        if ($candidate === '') {
            $candidate = 'SERIES';
        }
        $candidate = mb_substr($candidate, 0, 12);
        $code = $candidate;
        $suffix = 0;
        while (ProductSeries::where('code', $code)->exists()) {
            $suffix++;
            $code = $candidate.'-'.str_pad((string) $suffix, 2, '0', STR_PAD_LEFT);
            if ($suffix > 99) {
                $code = $candidate.'-'.substr(str_shuffle('0123456789'), 0, 4);
                if (! ProductSeries::where('code', $code)->exists()) {
                    break;
                }
            }
        }

        return $code;
    }

    /**
     * Buat Seri baru dari nilai kolom, lalu daftarkan ke cache lookup.
     *
     * Pemanggil sudah memastikan modul Katalog dan opsi auto-create aktif
     * (`$allowSeriesAutoCreate`), jadi di sini hanya tersisa pemeriksaan
     * izin dan pembuatan barisnya.
     */
    private function resolveSeriesWithAutoCreate(string $rawValue, string $displayName, string $lookupValue, array &$errors, array $item): ?int
    {
        if (! auth()->check() || auth()->user()->role !== Role::Owner) {
            $errors[] = "Kolom {$item['label']}: \"{$displayName}\" tidak ditemukan. Hanya Owner yang dapat membuat Seri baru melalui impor.";

            return null;
        }

        if ($displayName === '') {
            return null;
        }

        $targetName = $this->normName($rawValue);
        if ($targetName === '') {
            return null;
        }

        // Nilai yang sama sudah dibuat pada baris sebelumnya dalam berkas ini.
        if (isset($this->autoCreatedSeries[$targetName])) {
            return $this->rememberSeries($lookupValue, $targetName, $this->autoCreatedSeries[$targetName]);
        }

        try {
            $series = ProductSeries::create([
                'name' => $displayName,
                'code' => $this->generateSeriesCode($displayName),
            ]);
            AuditLogger::created($series);
        } catch (\Throwable) {
            $errors[] = "Kolom {$item['label']}: \"{$displayName}\" tidak ditemukan dan gagal dibuat otomatis.";

            return null;
        }

        return $this->rememberSeries($lookupValue, $targetName, $series->id, $series->name, $series->code);
    }

    /**
     * Simpan hasil resolve ke cache lookup agar tidak dihitung ulang.
     */
    private function rememberSeries(string $lookupValue, string $targetName, int $id, ?string $name = null, ?string $code = null): int
    {
        $this->seriesLookup();

        $this->seriesLookup[$lookupValue] = $id;
        $this->seriesLookup[$targetName] = $id;

        if ($name !== null && $name !== '') {
            $this->seriesLookup[$this->normName($name)] = $id;
        }

        if ($code !== null && $code !== '') {
            $this->seriesLookup[mb_strtolower(trim($code))] = $id;
        }

        $this->autoCreatedSeries[$targetName] = $id;

        return $id;
    }

    public function preCreateSeries(string $module, array $payload, array $mapping): void
    {
        if ($module !== 'katalog' || ! $this->autoCreateSeriesEnabled($mapping)) {
            return;
        }

        if (! auth()->check() || auth()->user()->role !== Role::Owner) {
            return;
        }

        $schema = ImportSchemas::for($module);
        $seriesKey = null;
        foreach ($schema['items'] as $item) {
            if (($item['lookup'] ?? null) === 'series') {
                $seriesKey = $item['store'];
                break;
            }
        }

        if ($seriesKey === null) {
            return;
        }

        $col = ($mapping['map'] ?? [])[$seriesKey] ?? null;
        if ($col === null || $col === '') {
            return;
        }

        $rows = $payload['rows'] ?? [];
        $headerLine = (int) ($mapping['header_row'] ?? $payload['header_line'] ?? 0);
        if ($headerLine < 0 || $headerLine >= count($rows)) {
            $headerLine = 0;
        }

        $index = Coordinate::columnIndexFromString((string) $col) - 1;

        // Preload nama seri yang sudah ada agar pemeriksaan tidak query per baris.
        $existing = [];
        foreach (ProductSeries::select('name')->get() as $series) {
            $existing[$this->normName((string) $series->name)] = true;
        }

        $toCreate = [];
        for ($i = $headerLine + 1; $i < count($rows); $i++) {
            $display = trim((string) ($rows[$i][$index] ?? ''));
            if ($display === '') {
                continue;
            }

            $target = $this->normName($display);
            if ($target === '' || isset($existing[$target]) || isset($toCreate[$target])) {
                continue;
            }

            $toCreate[$target] = $display;
        }

        foreach ($toCreate as $display) {
            try {
                $series = ProductSeries::create([
                    'name' => $display,
                    'code' => $this->generateSeriesCode($display),
                ]);
                AuditLogger::created($series);
            } catch (\Throwable) {
                // Biarkan baris gagal terlaporkan pada tahap validasi.
            }
        }

        $this->seriesLookup = null;
        $this->autoCreatedSeries = [];
    }

    /**
     * Buat lebih dulu setiap Penitip baru yang disebut berkas, sebelum
     * validasi berjalan.
     *
     * Sama alasannya dengan `preCreateSeries`: pemeriksaan referensi menulis
     * ke database, dan halaman Validasi harus memperlihatkan baris yang
     * benar-benar bisa disimpan. Membuat di sini membuat baris penitip baru
     * lolos pada pratinjau, bukan tampak gagal padahal datanya benar.
     *
     * Nama yang sudah terdaftar (berdasarkan kode maupun nama) dilewati, dan
     * nilai yang bentuknya seperti kode penitip tidak pernah dibuatkan.
     */
    public function preCreateConsignors(string $module, array $payload, array $mapping): void
    {
        if ($module !== 'consignment_in' || ! $this->autoCreateConsignorEnabled($mapping)) {
            return;
        }

        if (! auth()->check() || auth()->user()->role !== Role::Owner) {
            return;
        }

        $schema = ImportSchemas::for($module);
        $consignorKey = null;
        foreach ($schema['items'] as $item) {
            if (($item['autoCreate'] ?? null) === 'consignor') {
                $consignorKey = $item['store'];
                break;
            }
        }

        if ($consignorKey === null) {
            return;
        }

        $col = ($mapping['map'] ?? [])[$consignorKey] ?? null;
        if ($col === null || $col === '') {
            return;
        }

        $rows = $payload['rows'] ?? [];
        $headerLine = (int) ($mapping['header_row'] ?? $payload['header_line'] ?? 0);
        if ($headerLine < 0 || $headerLine >= count($rows)) {
            $headerLine = 0;
        }

        $index = Coordinate::columnIndexFromString((string) $col) - 1;

        // Preload kode & nama penitip yang sudah ada agar tidak query per baris.
        $existing = $this->consignorLookup();
        $known = [];
        foreach (Consignor::select('name')->get() as $consignor) {
            $known[$this->normName((string) $consignor->name)] = true;
        }

        $toCreate = [];
        for ($i = $headerLine + 1; $i < count($rows); $i++) {
            $display = trim((string) ($rows[$i][$index] ?? ''));
            if ($display === '' || $this->looksLikeConsignorCode($display)) {
                continue;
            }

            $lookup = mb_strtolower(preg_replace('/\s+/', ' ', $display));
            $target = $this->normName($display);
            if ($target === '' || isset($existing[$lookup]) || isset($known[$target]) || isset($toCreate[$target])) {
                continue;
            }

            $toCreate[$target] = $display;
        }

        foreach ($toCreate as $display) {
            try {
                $consignor = Consignor::create($this->newConsignorAttributes($display));
                AuditLogger::created($consignor);
            } catch (\Throwable) {
                // Biarkan baris gagal terlaporkan pada tahap validasi.
            }
        }

        $this->consignorLookup = null;
        $this->autoCreatedConsignors = [];
    }
}
