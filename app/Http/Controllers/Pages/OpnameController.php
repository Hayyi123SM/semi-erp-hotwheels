<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pages;

use App\Enums\OpnameLineStatus;
use App\Enums\OpnameScope;
use App\Enums\OpnameStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\HitungBarisRequest;
use App\Http\Requests\Inventory\MulaiOpnameRequest;
use App\Http\Requests\Inventory\ReviewBarisRequest;
use App\Models\AuditLog;
use App\Models\Opname;
use App\Models\OpnameLine;
use App\Models\Rack;
use App\Models\Shift;
use App\Services\Inventory\OpnameService;
use App\Support\DataTable\Column;
use App\Support\DataTable\DataTable;
use App\Support\Format;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Halaman Stok Opname beserta tombol-tombolnya.
 *
 * Seluruh perubahan keadaan diserahkan ke `OpnameService`; controller hanya
 * memilih apa yang dikirim ke layar. Pemilihan itu sendiri adalah salah satu
 * aturan fitur: selama sesi menghitung, kolom sistem tidak pernah ikut
 * dipilih, sehingga angkanya tidak ada di memori halaman ini sama sekali.
 * Menyembunyikan kolomnya di template akan tetap mengirimnya ke peramban, dan
 * blind count yang angka sistemnya ada di HTML bukan blind count.
 */
class OpnameController extends Controller
{
    /**
     * Kolom baris yang boleh dikirim saat sesi masih menghitung.
     *
     * `system_qty`, `diff_qty`, dan `reason` sengaja tidak ada di daftar ini.
     * Angka pertama adalah yang disembunyikan; angka kedua bocor lewat
     * perbandingannya; ketiga hanya ada setelah persetujuan, jadi ketika sesi
     * menghitung ia selalu null dan hanya memancing pertanyaan.
     */
    private const array BLIND_COLUMNS = [
        'id',
        'opname_id',
        'lot_id',
        'counted_qty',
        'counted_at',
        'counted_by',
        'status',
        'created_at',
        'updated_at',
    ];

    public function index(Request $request)
    {
        $open = Opname::query()
            ->whereIn('status', OpnameStatus::openValues())
            ->latest('id')
            ->first();

        $blind = $open !== null && $open->status === OpnameStatus::Counting;

        if ($open !== null) {
            $lines = $open->lines()->with(['lot.product.series', 'lot.rack', 'counter', 'approver']);

            if ($blind) {
                $lines->select(self::BLIND_COLUMNS);
            }

            $open->setRelation('rows', $lines->orderBy('id')->get());
        }

        $reviewTargets = $open === null || $blind ? [] : $this->reviewTargets($open);

        return $this->page('pages.inventory.stok-opname', [
            'open' => $open,
            'blind' => $blind,
            // Riwayat kini DataTable, bukan daftar sepuluh terakhir yang ditulis
            // tangan: sesi yang lama bisa dicari, disortir, difilter statusnya,
            // dan dibuka detail masing-masing lewat satu kolom aksi.
            'historyTable' => $this->historyTable($request, $open),
            'historyStatusOptions' => $this->historyStatusOptions(),
            'racks' => Rack::query()->orderBy('code')->get(['id', 'code', 'zone']),
            'scopes' => OpnameScope::cases(),
            // Dua daftar alasan datang dari service, bukan dari enum:
            // arah selisih menentukan alasan yang masuk akal, dan aturan itu
            // hanya boleh ada di satu tempat -- di service yang juga
            // menegakkannya.
            'plusReasons' => OpnameService::PLUS_REASONS,
            'minusReasons' => OpnameService::MINUS_REASONS,
            // Peta ini menghubungkan klik Review dengan data barisnya. URL
            // dibangun di server, bukan dikirim dari peramban, supaya dialog
            // yang dibuka ulang setelah galat validasi menunjuk ke alamat yang
            // sah dan sama.
            'reviewTargets' => $reviewTargets,
            // Konfirmasi sebelum Mulai Sesi: saat shift kasir masih berjalan,
            // opname berarti penjualan terus berjalan. Gerakan stok barunya
            // tidak lagi menutup baris, melainkan diserap ke selisih pada
            // keputusan -- tetapi pengguna harus memutuskan dengan sadar, bukan
            // baru menyadarinya setelah notifikasi "stok bergerak" muncul.
            'openShiftCount' => Shift::query()->open()->count(),
        ], 'Stok Opname');
    }

    public function store(MulaiOpnameRequest $request, OpnameService $service)
    {
        $opname = $service->start(
            $request->scope(),
            $request->rack(),
            $request->sku(),
            $request->user(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                'Sesi %s dimulai untuk %d lot. Hitung fisik satu per satu; angka sistem tetap tersembunyi sampai sesi diajukan.',
                $opname->opname_no,
                $opname->lines()->count(),
            ),
        ]);
    }

    public function hitung(Opname $opname, OpnameLine $line, HitungBarisRequest $request, OpnameService $service)
    {
        $line = $service->count($opname, $line, $request->countedQty(), $request->user());

        // Balasan sengaja tidak memuat ekspektasi atau selisih: sesi masih
        // menghitung, dan layar konfirmasi adalah bagian dari layar juga.
        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                'Hitungan tersimpan: %d unit untuk %s.',
                $request->countedQty(),
                $line->lot?->sku ?? ('#'.$line->lot_id),
            ),
        ]);
    }

    /**
     * Tambah satu ke hitungan fisik, dipanggil oleh pindai SKU di layar
     * (FR-IC-20).
     *
     * Panggilan lewat fetch (JSON) memakai balasan ringkas: qty terbaru dan
     * status baris, supaya peramban hanya mengganti dua sel yang berubah tanpa
     * memuat ulang halaman. Balasan itu tetap tidak memuat ekspektasi ataupun
     * selisih -- sesi masih menghitung, dan aturan blind count berlaku untuk
     * setiap respons yang dikirim selama sesi menghitung, bukan hanya respons
     * halaman penuh.
     */
    public function tambah(Opname $opname, OpnameLine $line, Request $request, OpnameService $service)
    {
        $line = $service->increment($opname, $line, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'line_id' => $line->getKey(),
                'counted_qty' => $line->counted_qty,
                'status' => $line->status->value,
                'status_label' => $line->status->label(),
                'status_type' => Format::statusType($line->status->value),
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                'Hitungan bertambah: %d unit untuk %s.',
                $line->counted_qty,
                $line->lot?->sku ?? ('#'.$line->lot_id),
            ),
        ]);
    }

    public function ajukan(Opname $opname, Request $request, OpnameService $service)
    {
        $opname = $service->submit($opname, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                'Sesi %s diajukan. Selisih kini menunggu persetujuan Owner.',
                $opname->opname_no,
            ),
        ]);
    }

    public function review(Opname $opname, OpnameLine $line, ReviewBarisRequest $request, OpnameService $service)
    {
        $line = $service->review(
            $opname,
            $line,
            $request->decision(),
            $request->reason(),
            $request->approver(),
        );

        $sku = $line->lot?->sku ?? ('#'.$line->lot_id);

        // Hasilnya dibaca dari baris, bukan dari permintaan: permintaan bisa
        // minta "setujui", tetapi bila stok sudah bergerak mengikuti hitungan
        // di antara hitung dan putus, tidak ada selisih yang tersisa dan baris
        // tuntas sebagai `Ok`. Pesan yang tetap berkata "disetujui" akan
        // menyesatkan pada keadaan itu.
        $message = match (true) {
            $line->status === OpnameLineStatus::Ok => sprintf(
                'Stok %s sudah mengikuti hitungan (bergerak setelah baris dihitung); tidak ada selisih yang diputuskan.',
                $sku,
            ),
            $request->decision() === 'APPROVE' => sprintf('Selisih %s disetujui dan diterapkan ke stok.', $sku),
            default => sprintf('Selisih %s ditolak; stok tidak diubah.', $sku),
        };

        return back()->with('toast', [
            'type' => 'success',
            'message' => $message,
        ]);
    }

    public function detail(Request $request, Opname $opname)
    {
        $opname->load([
            'creator',
            'rack',
            'lines.lot.product.series',
            'lines.lot.rack',
            'lines.counter',
            'lines.approver',
        ]);

        // Blind berlaku untuk halaman detail juga: sesi bisa dibuka lewat URL
        // langsung walau masih menghitung (meski memang tidak pernah muncul di
        // riwayat). Selama `Counting`, angka sistem dan selisih tidak ikut
        // dipilih, sama persis dengan halaman berjalan.
        $blind = $opname->status === OpnameStatus::Counting;

        $opname->loadCount([
            'lines',
            'lines as selisih_count' => fn ($lines) => $lines->where('diff_qty', '!=', 0),
            'lines as terhitung_count' => fn ($lines) => $lines->whereNotNull('counted_qty'),
        ]);

        $lines = OpnameLine::query()
            ->where('opname_id', $opname->getKey())
            ->with(['lot.product.series', 'lot.rack', 'counter', 'approver']);

        $columns = [
            Column::make('lot.sku', 'SKU')
                ->priority(1)
                ->card('title')
                ->mono(),
            Column::make('lot.product.name', 'Produk')
                ->priority(1)
                ->card('subtitle')
                ->value(fn (OpnameLine $line) => $this->productLabel($line)),
            Column::make('lot.rack.code', 'Rak')
                ->priority(3)
                ->card('meta')
                ->mono(),
        ];

        if (! $blind) {
            $columns[] = Column::make('system_qty', 'Qty Sistem', align: 'right')
                ->sortable('system_qty')
                ->priority(1)
                ->card('meta')
                ->format('number');
        }

        $columns[] = Column::make('counted_qty', 'Qty Terhitung', align: 'right')
            ->sortable('counted_qty')
            ->priority(1)
            ->card('meta')
            ->format('number');

        if (! $blind) {
            $columns[] = Column::make('diff_qty', 'Selisih', align: 'right')
                ->sortable('diff_qty')
                ->priority(1)
                ->card('meta')
                ->value(fn (OpnameLine $line) => (string) $line->diff_qty)
                ->render(fn (OpnameLine $line) => $this->signedDiffCell((int) $line->diff_qty));
            $columns[] = Column::make('counted_at', 'Dihitung', format: 'datetime')
                ->priority(2)
                ->card('meta');
        }

        $columns[] = Column::make('status', 'Status')
            ->sortable('status')
            ->priority(1)
            ->card('badge')
            ->format('status');
        $columns[] = Column::make('counter.name', 'Penghitung')
            ->priority(2)
            ->card('meta')
            ->value(fn (OpnameLine $line) => $line->counter?->name ?? 'Sistem');

        if (! $blind) {
            $columns[] = Column::make('approver.name', 'Penyetuju Keputusan')
                ->priority(2)
                ->card('meta')
                ->value(fn (OpnameLine $line) => $line->approver?->name ?? 'Sistem');
            $columns[] = Column::make('reason', 'Alasan')
                ->priority(2)
                ->card('meta')
                ->value(fn (OpnameLine $line) => $line->reason?->label() ?? '—');
        }

        $linesTable = DataTable::for($request, $lines, $request->user())
            ->searchable(['lot.sku', 'lot.product.name'])
            ->searchPlaceholder('Cari SKU atau nama produk...')
            ->sortable(['system_qty', 'counted_qty', 'diff_qty', 'status'])
            ->perPage([10, 25, 50, 100])
            ->columns($columns)
            ->emptyState('Sesi tanpa baris', 'Sesi ini tidak menyimpan catatan baris apa pun.');

        // Ringkasan per status untuk kartu KPI, dihitung sekali lewat satu
        // query terkelompok, bukan empat hitungan terpisah.
        $byStatus = $opname->lines()
            ->toBase()
            ->selectRaw('status, COUNT(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');

        // Jejak audit sesi: kejadian pada Opname itu sendiri (mulai, ajukan,
        // batal, buka ulang) ditambah keputusan tiap barisnya (setuju/tolak).
        // Diurutkan naik supaya terbaca seperti cerita, dari pembukaan sampai
        // penutupan.
        $timeline = AuditLog::query()
            ->with('user')
            ->where(function (Builder $query) use ($opname) {
                $query
                    ->where('entity', Opname::class)
                    ->where('entity_id', $opname->getKey())
                    ->orWhere(function (Builder $query) use ($opname) {
                        $query
                            ->where('entity', OpnameLine::class)
                            ->whereIn('entity_id', $opname->lines()->pluck('id'));
                    });
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return $this->page('pages.inventory.stok-opname-detail', [
            'opname' => $opname,
            'blind' => $blind,
            'cakupan' => $this->cakupanLabel($opname),
            'summary' => [
                'total' => (int) $opname->lines_count,
                'terhitung' => (int) $opname->terhitung_count,
                'selisih' => (int) $opname->selisih_count,
                'approved' => (int) ($byStatus[OpnameLineStatus::Approved->value] ?? 0),
                'rejected' => (int) ($byStatus[OpnameLineStatus::Rejected->value] ?? 0),
            ],
            'linesTable' => $linesTable,
            'timeline' => $timeline,
        ], 'Detail Sesi '.$opname->opname_no);
    }

    public function batal(Opname $opname, Request $request, OpnameService $service)
    {
        $service->cancel($opname, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf('Sesi %s dibatalkan.', $opname->opname_no),
        ]);
    }

    public function bukaUlang(Opname $opname, Request $request, OpnameService $service)
    {
        $service->reopen($opname, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => sprintf(
                'Sesi %s dibuka ulang. Koreksi hitungan yang perlu diperbaiki, lalu ajukan lagi.',
                $opname->opname_no,
            ),
        ]);
    }

    /**
     * Riwayat sesi: semua sesi yang tidak sedang terbuka, dibaca sebagai
     * DataTable supaya bisa dicari, disortir, difilter statusnya, dan dibuka
     * detail masing-masing.
     */
    private function historyTable(Request $request, ?Opname $open): DataTable
    {
        $query = Opname::query()
            ->with(['creator', 'rack'])
            ->when($open !== null, fn (Builder $query) => $query->where('id', '!=', $open->getKey()))
            ->withCount(['lines', 'lines as selisih_count' => fn ($lines) => $lines->where('diff_qty', '!=', 0)])
            ->when(
                $request->filled('status'),
                fn (Builder $query) => $query->where('status', (string) $request->query('status')),
            );

        // Pengurutan bawaan: yang terbaru lebih dulu, dipasang hanya bila
        // pembaca belum memilih kolom lain (pola yang sama dengan laporan lain).
        if (! $request->filled('sort')) {
            $query->orderByDesc('id');
        }

        return DataTable::for($request, $query, $request->user())
            ->searchable(['opname_no', 'scope_value', 'creator.name'])
            ->searchPlaceholder('Cari nomor sesi, SKU, atau pembuat...')
            ->sortable(['opname_no', 'started_at', 'status', 'lines_count', 'selisih_count'])
            ->perPage([10, 25, 50, 100])
            ->columns([
                Column::make('opname_no', 'No. Sesi')
                    ->sortable('opname_no')
                    ->priority(1)
                    ->card('title')
                    ->mono(),
                Column::make('scope', 'Cakupan')
                    ->priority(1)
                    ->card('subtitle')
                    ->value(fn (Opname $item) => $this->cakupanLabel($item)),
                Column::make('creator.name', 'Dimulai Oleh')
                    ->priority(2)
                    ->card('meta')
                    ->value(fn (Opname $item) => $item->creator?->name ?? 'Sistem'),
                Column::make('started_at', 'Mulai', format: 'datetime')
                    ->sortable('started_at')
                    ->priority(1)
                    ->card('meta'),
                Column::make('status', 'Status')
                    ->sortable('status')
                    ->priority(1)
                    ->card('badge')
                    ->format('status'),
                Column::make('lines_count', 'Baris', align: 'right')
                    ->sortable('lines_count')
                    ->priority(2)
                    ->card('meta')
                    ->render(fn (Opname $item) => '<span class="tabular-nums">'.e((string) $item->lines_count).'</span>'),
                Column::make('selisih_count', 'Selisih', align: 'right')
                    ->sortable('selisih_count')
                    ->priority(2)
                    ->card('meta')
                    ->value(fn (Opname $item) => (string) $item->selisih_count)
                    ->render(fn (Opname $item) => sprintf(
                        '<span class="tabular-nums %s">%s</span>',
                        ($item->selisih_count > 0 ? 'text-warning-text' : 'text-text-muted'),
                        e((string) $item->selisih_count),
                    )),
                // Satu aksi, bukan daftar: Detail adalah satu-satunya hal yang
                // bisa dilakukan pada sesi yang sudah lewat. Barisnya ikut bisa
                // diklik menuju halaman yang sama (rulai sekunder).
                Column::make('actions', 'Aksi', align: 'right')
                    ->priority(1)
                    ->card('footer')
                    ->component('ui.row-actions', [
                        'actions' => [
                            [
                                'key' => 'detail',
                                'label' => 'Detail',
                                'route' => 'inventory.stok-opname.detail',
                            ],
                        ],
                    ]),
            ])
            ->filters([
                'status' => ['label' => 'Status', 'format' => fn (string $value) => Format::statusLabel($value)],
            ])
            ->rowUrl(fn (Opname $item) => route('inventory.stok-opname.detail', $item))
            ->emptyState(
                'Belum ada sesi opname',
                'Sesi yang sudah selesai, dibatalkan, atau ditutup akan muncul di sini dengan tombol detail.',
            );
    }

    /**
     * Opsi filter status riwayat. Hanya status yang bisa menjadi keadaan akhir
     * sesi: sesi yang masih terbuka memang tidak pernah masuk riwayat.
     *
     * @return array<string, string>
     */
    private function historyStatusOptions(): array
    {
        return [
            OpnameStatus::Approved->value => OpnameStatus::Approved->label(),
            OpnameStatus::Cancelled->value => OpnameStatus::Cancelled->label(),
        ];
    }

    /**
     * Nama cakupan untuk satu sesi: label cakupan, ditambah rak atau SKU yang
     * dipersempitnya. Sesi cakupan penuh cukup labelnya saja.
     */
    private function cakupanLabel(Opname $opname): string
    {
        $label = $opname->scope->label();

        if ($opname->scope === OpnameScope::Rack && $opname->rack !== null) {
            return $label.' · '.$opname->rack->code;
        }

        if ($opname->scope === OpnameScope::Sku && $opname->scope_value !== null) {
            return $label.' · '.$opname->scope_value;
        }

        return $label;
    }

    public function productLabel(OpnameLine $line): string
    {
        if ($line->lot?->product === null) {
            return 'Produk telah dihapus';
        }

        $label = $line->lot->product->name;

        if ($line->lot->product->series !== null) {
            $label .= ' · '.$line->lot->product->series->name;
        }

        return $label;
    }

    private function signedDiffCell(int $diff): string
    {
        $signed = ($diff > 0 ? '+' : '').Format::number($diff);
        $class = $diff > 0 ? 'text-warning-text' : ($diff < 0 ? 'text-danger-text' : 'text-text-muted');

        return sprintf('<span class="tabular-nums %s">%s</span>', $class, e($signed));
    }

    /**
     * Data tiap baris yang menunggu keputusan, dikunci per id.
     *
     * Klik Review tidak lagi merakit payload di template: tombol hanya mengirim
     * id, dan Alpine membaca entri di sini. Sesi yang sedang menghitung tidak
     * pernah punya entri -- angka selisihnya memang belum ada di layar.
     *
     * @return array<int, array{id: int, url: string, sku: string, diff: int, positive: bool}>
     */
    private function reviewTargets(Opname $open): array
    {
        return $open->rows
            ->filter(fn (OpnameLine $row) => $row->status === OpnameLineStatus::Counted)
            ->mapWithKeys(fn (OpnameLine $row) => [$row->getKey() => [
                'id' => $row->getKey(),
                'url' => route('inventory.stok-opname.review', [$open, $row]),
                'sku' => $row->lot?->sku ?? '',
                'diff' => (int) $row->diff_qty,
                'positive' => $row->diff_qty > 0,
            ]])
            ->all();
    }
}
