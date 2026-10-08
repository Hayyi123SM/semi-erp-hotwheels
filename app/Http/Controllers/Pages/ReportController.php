<?php

namespace App\Http\Controllers\Pages;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Report\MarginReportService;
use App\Services\Report\SalesReportService;
use App\Services\Report\SettlementReportService;
use App\Services\Report\StockReportService;
use App\Services\Spreadsheet\ExportService;
use App\Support\DataTable\Column;
use App\Support\DataTable\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Kolom yang boleh jadi `ORDER BY` di laporan audit.
     *
     * Satu daftar, dipakai dua kali: sebagai whitelist `DataTable`, dan untuk
     * menentukan apakah urutan bawaan perlu dipasang. Kalau keduanya
     * terpisah, suatu saat keduanya bisa berbeda -- `sort` diabaikan diam-diam
     * sementara urutan bawaan masih ditambah, dan tidak ada yang salahnya
     * terlihat dari tampilannya.
     */
    private const AUDIT_SORTABLE = ['created_at', 'action', 'entity'];

    /**
     * Saldo dan utang hak penitip.
     *
     * Angka-angka ini adalah uang toko dan dihitung dari tabel `consignor_ledger`
     * yang hanya boleh dibaca Owner -- sama seperti laporan margin. Staff tidak
     * melihat saldo hak penitip, karena angka yang menunggu dibayar itu bukan
     * bagian dari pekerjaan kasir.
     */
    public function settlement(Request $request): View|StreamedResponse
    {
        abort_unless($request->user()?->isOwner(), 403);

        $service = new SettlementReportService;

        $format = $request->query('format');

        if (! in_array($format, $this->exportFormats(), true)) {
            $format = null;
        }

        if ($format !== null) {
            return $this->exportSettlement($format, $service);
        }

        return $this->page('pages.reports.settlement', [
            'summary' => $service->summary(),
            'balances' => $service->balances(),
            'settlements' => $service->settlements(),
            'aging' => $service->aging(),
        ], 'Consignor Settlement');
    }

    public function margin(Request $request): View|StreamedResponse
    {
        abort_unless($request->user()?->isOwner(), 403);

        $service = new MarginReportService;
        $dimension = $request->query('d', 'seri');

        if (! in_array($dimension, MarginReportService::DIMENSIONS, true)) {
            $dimension = 'seri';
        }

        if (in_array($request->query('format'), $this->exportFormats(), true)) {
            return $this->exportMargin((string) $request->query('format'), $service, $dimension, $request);
        }

        return $this->page('pages.reports.margin', [
            'dimension' => $dimension,
            'summary' => $service->summary($request->query('from'), $request->query('to')),
            'reconciliation' => $service->reconciliation($request->query('from'), $request->query('to')),
            'dimensions' => $service->dimension($dimension, $request->query('from'), $request->query('to')),
            'takeRates' => $service->takeRates($request->query('from'), $request->query('to')),
            'insights' => $service->insights($request->query('from'), $request->query('to')),
        ], 'Profit Margin');
    }

    public function laporan(Request $request): View|StreamedResponse
    {
        $viewer = $request->user();
        $sales = new SalesReportService;
        $stock = new StockReportService;

        if (in_array($request->query('format'), $this->exportFormats(), true)) {
            return $this->exportLaporan((string) $request->query('format'), $sales, $stock, $viewer, $request);
        }

        $table = DataTable::for($request, $sales->salesQuery($viewer, $request->query('from'), $request->query('to')))
            ->searchable(['receipt_no', 'user.name'])
            ->sortable(['sold_at', 'receipt_no', 'total', 'items_count'])
            ->searchPlaceholder('Cari nomor nota atau kasir...')
            ->perPage([25, 50, 100])
            ->columns([
                Column::make('receipt_no', 'Nota')
                    ->sortable('receipt_no')
                    ->mono()
                    ->priority(1)
                    ->card('title'),
                Column::make('sold_at', 'Waktu', format: 'datetime')
                    ->sortable('sold_at')
                    ->priority(1)
                    ->card('meta'),
                Column::make('user.name', 'Kasir')
                    ->priority(2)
                    ->card('meta'),
                Column::make('items_count', 'Item', align: 'right')
                    ->sortable('items_count')
                    ->priority(2)
                    ->card('meta'),
                Column::make('total', 'Total', align: 'right', format: 'rupiah', sort: 'total')
                    ->priority(1)
                    ->card('price'),
            ])
            ->emptyState('Tidak ada transaksi di periode ini', 'Ubah rentang tanggal atau buka halaman kasir untuk mencatat penjualan baru.');

        return $this->page('pages.reports.laporan', [
            'summary' => $sales->summary($viewer, $request->query('from'), $request->query('to')),
            'shifts' => $sales->shifts($viewer, $request->query('from'), $request->query('to')),
            'valuation' => $stock->valuation(),
            'movements' => $stock->movements($request->query('from'), $request->query('to')),
            'deadStock' => $stock->deadStock(),
            'table' => $table,
        ], 'Laporan Penjualan & Stok');
    }

    private function exportSettlement(string $format, SettlementReportService $service): StreamedResponse
    {
        $rows = array_map(
            fn (array $b): array => [$b['code'], $b['name'], $b['accrual'], $b['paid'], $b['balance']],
            $service->balances(),
        );

        return (new ExportService)->{$format}('saldo-penitip', ['Kode', 'Penitip', 'Total Hak', 'Sudah Dibayar', 'Sisa Hak'], $rows);
    }

    private function exportMargin(string $format, MarginReportService $service, string $dimension, Request $request): StreamedResponse
    {
        $label = match ($dimension) {
            'seri' => 'per-seri',
            'penitip' => 'per-penitip',
            'sku' => 'per-sku',
            'hari' => 'per-hari',
        };

        return (new ExportService)->{$format}(
            'margin-'.$label,
            ['Label', 'Bruto', 'Laba', 'Fee', 'Hak', 'Pendapatan', 'Share'],
            $service->dimensionRows($dimension, $request->query('from'), $request->query('to')),
        );
    }

    private function exportLaporan(string $format, SalesReportService $sales, StockReportService $stock, ?User $viewer, Request $request): StreamedResponse
    {
        $rows = [];

        foreach ($sales->shifts($viewer, $request->query('from'), $request->query('to')) as $shift) {
            $rows[] = ['SHIFT', $shift['label'], $shift['kasir'], $shift['nota'], $shift['items'], $shift['total'], $shift['metode']];
        }

        foreach ($stock->movements($request->query('from'), $request->query('to')) as $movement) {
            $rows[] = ['MOVEMENT', $movement['label'], $movement['type'], $movement['lines'], $movement['qty'], '', ''];
        }

        return (new ExportService)->{$format}(
            'laporan-penjualan-stok',
            ['Tipe', 'Kunci', 'Kasir / Jenis', 'Nota / Baris', 'Item / Qty', 'Total / Qty Delta', 'Metode'],
            $rows,
        );
    }

    /**
     * Format yang bisa diunduh. Semua yang lain dianggap bukan permintaan
     * ekspor, supaya `format=` yang diketik sembarangan tidak membuat 500.
     *
     * @return array<int, string>
     */
    private function exportFormats(): array
    {
        return ['csv', 'xlsx'];
    }

    /**
     * Laporan audit dari tabel `audit_logs` yang sebenarnya.
     *
     * Halaman ini dulunya menampilkan lima baris hard-coded di dalam Blade, jadi
     * jejak yang kita rapikan di tempat lain hanya tertulis di database tanpa
     * pernah bisa dibaca. Sekarang ia membaca tabel, sehingga pencarian "cetak
     * ulang yang melebihi qty" atau "template WhatsApp terakhir diubah siapa"
     * menjawabannya dari data.
     *
     * Filter diterapkan ke query SEBELUM `DataTable` dibuat, bukan sesudahnya.
     * `DataTable` menyimpan salinan builder-nya sendiri, jadi menyaringnya
     * setelah query-nya dibuat tidak akan mengubah apa pun -- halaman tetap
     * menampilkan seluruh log sambil filter di toolbar terlihat aktif. Itu
     * kegagalan yang paling sulit ketahuan, karena tampilannya benar.
     */
    public function auditLog(Request $request)
    {
        $query = $this->auditLogQuery($request);

        $table = DataTable::for($request, $query)
            ->searchable([
                'user.name',
                'action',
                'entity',
                'entity_key',
                'device_id',
                'reason',
                'before',
                'after',
            ])
            ->orSearchUsing(function (Builder $query, string $search): void {
                // `entity_id` integer tidak bisa dibaca dengan `LIKE` di kedua
                // mesin, jadi nomor identitas ikut dicocokkan lewat '=' -- dan
                // hanya kalau yang diketik memang angka. Tanpa syarat itu,
                // mengetik `4` akan mengembalikan semua baris.
                if (is_numeric($search)) {
                    $query->orWhere('audit_logs.entity_id', (int) $search);
                }
            })
            ->searchPlaceholder('Cari aktor, device, aksi, entitas, alasan, atau isi perubahan...')
            ->sortable(self::AUDIT_SORTABLE)
            ->perPage([25, 50, 100, 200])
            ->columns([
                Column::make('created_at', 'Waktu', format: 'datetime')
                    ->sortable('created_at')
                    ->priority(1)
                    ->card('meta'),
                Column::make('action', 'Aksi')
                    ->sortable('action')
                    ->priority(1)
                    ->card('badge')
                    ->value(fn (AuditLog $log): string => $log->action_label)
                    ->component('ui.audit-action-badge'),
                Column::make('entity_label', 'Entitas')
                    ->sortable('entity')
                    ->priority(1)
                    ->card('title')
                    ->value(fn (AuditLog $log): string => $log->entity_label)
                    ->render(fn (AuditLog $log) => sprintf(
                        '<span class="text-text-muted">%s</span> <span class="font-mono text-sku">%s</span>',
                        e($log->entity_label),
                        e($log->identity()),
                    )),
                Column::make('user.name', 'Aktor')
                    ->priority(2)
                    ->card('subtitle')
                    ->value(fn (AuditLog $log): string => $log->user?->name ?? 'Sistem')
                    ->render(fn (AuditLog $log) => e($log->user?->name ?? 'Sistem')),
                Column::make('diff_summary', 'Perubahan')
                    ->priority(2)
                    ->card('meta')
                    ->value(fn (AuditLog $log): string => $log->diff_summary)
                    ->component('ui.audit-diff'),
                Column::make('device_id', 'Device', align: 'right')
                    ->priority(3)
                    ->card('meta'),
                Column::make('reason', 'Alasan')
                    ->priority(3)
                    ->card('meta'),
            ])
            ->filters([
                'action' => ['label' => 'Aksi', 'format' => fn (string $value) => AuditAction::labelFor($value)],
                'entity' => ['label' => 'Entitas'],
                'has_changes' => ['label' => 'Hanya yang punya perubahan', 'flag' => true],
            ]);

        $filtered = $table->hasActiveFilters();

        $table->emptyState(
            $filtered ? 'Jejak audit tidak ditemukan' : 'Belum ada jejak audit',
            $filtered
                ? 'Coba ubah kata kunci atau filter yang aktif.'
                : 'Setiap mutasi stok, pembayaran, dan perubahan data akan tercatat di sini secara otomatis.',
        );

        return $this->page('pages.reports.audit-log', [
            'table' => $table,
            'actionOptions' => $this->auditActionOptions(),
            'entityOptions' => $this->auditEntityOptions(),
        ], 'Audit Log');
    }

    /**
     * Query dasar laporan audit, sudah disaring sesuai filter di toolbar.
     *
     * `with('user')` di sini, bukan di view: laporan ini selalu menampilkan nama
     * aktor, jadi tanpa eager load setiap baris akan menambah satu query.
     *
     * Urutan bawaannya `created_at` terbaru dulu, dipasang hanya saat pembaca
     * tidak memilih kolom lain. `DataTable` menempelkan `ORDER BY` miliknya di
     * belakang urutan yang sudah ada di query, dan yang pertama berturutan hanya
     * yang berlaku -- jadi urutan bawaan harus dipasang lebih dulu, dan hanya
     * kalau memang tidak ada pilihan pembaca yang mengalahkannya.
     */
    private function auditLogQuery(Request $request): Builder
    {
        $query = AuditLog::query()
            ->with('user')
            ->when($request->filled('action'), fn (Builder $q) => $q->where('action', $request->query('action')))
            ->when($request->filled('entity'), fn (Builder $q) => $q->where('entity', $request->query('entity')))
            ->when($request->boolean('has_changes'), fn (Builder $q) => $q->where(
                fn (Builder $inner) => $inner->whereNotNull('before')->orWhereNotNull('after'),
            ));

        // `sort` yang tidak dikenal diperlakukan sama seperti tidak ada
        // `sort`: `DataTable` membuangnya, jadi halaman harus ikut memakai
        // urutan bawaan. `id` sebagai pemutus supaya dua baris dengan
        // `created_at` yang sama tidak bergantian tempat antara halaman.
        $sort = $request->query('sort');

        if (! is_string($sort) || ! in_array($sort, self::AUDIT_SORTABLE, true)) {
            $query->orderByDesc('created_at')->orderByDesc('id');
        }

        return $query;
    }

    /**
     * Opsi filter aksi, diurutkan menurut labelnya.
     *
     * Diurutkan abjad sesuai yang dibaca, bukan menurut urutan enum: daftar ini
     * dibaca sambil mencari, bukan dipindai dari atas sampai bawah.
     *
     * @return array<string, string>
     */
    private function auditActionOptions(): array
    {
        $options = [];

        foreach (AuditAction::cases() as $action) {
            $options[$action->value] = $action->label();
        }

        asort($options);

        return $options;
    }

    /**
     * Opsi filter entitas, diambil dari baris yang benar-benar ada.
     *
     * Diambil dari database, bukan dari daftar statis: `entity` diisi
     * `class_basename()` di pemanggil `AuditLogger`, jadi tabel mana pun yang
     * mulai menulis log akan muncul di sini tanpa perlu ditambah ke kode.
     *
     * @return array<string, string>
     */
    private function auditEntityOptions(): array
    {
        return AuditLog::query()
            ->select('entity')
            ->distinct()
            ->orderBy('entity')
            ->pluck('entity', 'entity')
            ->all();
    }
}
