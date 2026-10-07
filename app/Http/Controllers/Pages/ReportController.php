<?php

namespace App\Http\Controllers\Pages;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\DataTable\Column;
use App\Support\DataTable\DataTable;
use App\Support\MockData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

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

    public function settlement()
    {
        return $this->page('pages.reports.settlement', ['consignors' => MockData::consignors()], 'Settlement Consignor');
    }

    public function margin()
    {
        return $this->page('pages.reports.margin', ['consignors' => MockData::consignors()], 'Profit Margin');
    }

    public function laporan()
    {
        return $this->page('pages.reports.laporan', ['transactions' => MockData::transactions()], 'Laporan Penjualan & Stok');
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
