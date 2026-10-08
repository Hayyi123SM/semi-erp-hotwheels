<?php

namespace App\Services\Report;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Angka penjualan, tanpa laba.
 *
 * Laporan penjualan adalah satu-satunya laporan yang tidak berbicara soal uang
 * toko: ia menghitung nota, item, dan nilai transaksi. Karena itu Staff boleh
 * membacanya -- selama yang ia baca adalah shift miliknya sendiri. `scopeToUser`
 * memegang batas itu persis seperti halaman kasir: Owner membaca semua shift,
 * Staff hanya shift yang `user_id`-nya miliknya (`Shifts::scopeForUser`).
 *
 * Akses ke kolom laba memang bukan urusan service ini -- laporan penjualan tidak
 * pernah memuat kolom laba untuk siapa pun. Yang disembunyikan untuk Staff hanya
 * milik shift orang lain.
 */
class SalesReportService
{
    /**
     * Query dasar nota, siap diteruskan ke `DataTable`. `sold_at` dipakai
     * sebagai waktu transaksi, bukan `created_at`, supaya laporan mengikuti saat
     * uang benar-benar diterima -- bukan saat perangkat mengunggahnya.
     */
    public function salesQuery(?User $viewer, ?string $from, ?string $to): Builder
    {
        [$start, $end] = ReportPeriod::span($from, $to);

        $query = Sale::query()
            ->with(['cashier:id,name', 'payments'])
            ->withCount('items')
            ->where('status', SaleStatus::Paid->value)
            ->whereBetween('sold_at', [$start, $end]);

        return $this->scopeToUser($query, $viewer);
    }

    /**
     * @return array{
     *     total: int,
     *     nota: int,
     *     items: int,
     *     topKasir: array{name: string, total: int, nota: int}|null,
     *     perMetode: array<int, array{method: string, label: string, amount: int, nota: int}>,
     * }
     */
    public function summary(?User $viewer, ?string $from, ?string $to): array
    {
        [$start, $end] = ReportPeriod::span($from, $to);

        $base = Sale::query()
            ->where('status', SaleStatus::Paid->value)
            ->whereBetween('sold_at', [$start, $end]);
        $this->scopeToUser($base, $viewer);

        $totals = (clone $base)
            ->selectRaw('count(*) as nota, coalesce(sum(total), 0) as total')
            ->first();

        $items = (clone $base)
            ->leftJoin('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->sum('sale_items.qty');

        $top = (clone $base)
            ->leftJoin('users', 'users.id', '=', 'sales.user_id')
            ->selectRaw('sales.user_id, users.name as kasir, count(*) as nota, coalesce(sum(sales.total), 0) as total')
            ->groupBy('sales.user_id', 'users.name')
            ->orderByDesc('total')
            ->first();

        $perMetode = $this->methods($base);

        return [
            'total' => (int) ($totals->total ?? 0),
            'nota' => (int) ($totals->nota ?? 0),
            'items' => (int) $items,
            'topKasir' => $top === null ? null : [
                'name' => $top->kasir ?? 'Sistem',
                'total' => (int) $top->total,
                'nota' => (int) $top->nota,
            ],
            'perMetode' => $perMetode,
        ];
    }

    /**
     * Baris per shift: berapa nota, berapa item, berapa rupiah, dan metode yang
     * paling banyak dipakai. Shift tanpa transaksi tidak muncul -- laporan ini
     * menjawab "berapa yang terjual", bukan "shift mana yang menganggur".
     *
     * @return array<int, array{shiftId: int|null, label: string, kasir: string, nota: int, items: int, total: int, metode: string}>
     */
    public function shifts(?User $viewer, ?string $from, ?string $to): array
    {
        [$start, $end] = ReportPeriod::span($from, $to);

        $base = Sale::query()
            ->where('status', SaleStatus::Paid->value)
            ->whereBetween('sold_at', [$start, $end]);
        $this->scopeToUser($base, $viewer);

        $rows = (clone $base)
            ->leftJoin('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->selectRaw(
                'sales.shift_id,
                 count(distinct sales.id) as nota,
                 coalesce(sum(sale_items.qty), 0) as items,
                 coalesce(sum(sales.total), 0) as total'
            )
            ->groupBy('sales.shift_id')
            ->orderByDesc('total')
            ->get()
            ->keyBy('shift_id');

        $shiftIds = $rows->keys()->filter()->all();
        $shifts = $shiftIds === []
            ? collect()
            : Shift::query()->with('user:id,name')->whereIn('id', $shiftIds)->get()->keyBy('id');

        $mainMethod = $this->mainMethodPerShift($base, $rows->keys()->all());

        return $rows->map(function ($row) use ($shifts, $mainMethod): array {
            $shiftId = $row->shift_id;
            $shift = $shiftId === null ? null : $shifts->get($shiftId);
            $kasir = $shift?->user?->name ?? 'Tanpa kasir';
            $methodKey = $mainMethod[$shiftId] ?? null;

            return [
                'shiftId' => $shiftId,
                'label' => 'Shift '.($shiftId ?? '—').' · '.$kasir,
                'kasir' => $kasir,
                'nota' => (int) $row->nota,
                'items' => (int) $row->items,
                'total' => (int) $row->total,
                'metode' => $methodKey === null
                    ? '—'
                    : (PaymentMethod::tryFrom($methodKey)?->label() ?? $methodKey),
            ];
        })->values()->all();
    }

    /**
     * Owner melihat semua shift. Kasir hanya shift miliknya sendiri -- batas yang
     * sama dengan halaman riwayat nota; dua halaman tidak boleh menampilkan angka
     * yang berbeda untuk orang yang sama.
     */
    private function scopeToUser(Builder $query, ?User $viewer): Builder
    {
        if ($viewer !== null && ! $viewer->isOwner()) {
            $query->whereHas('shift', fn (Builder $shift) => $shift->forUser($viewer->getKey()));
        }

        return $query;
    }

    /**
     * @param  Builder  $base  query `sales` yang sudah di-scope ke pembaca
     * @return array<int, array{method: string, label: string, amount: int, nota: int}>
     */
    private function methods(Builder $base): array
    {
        return SalePayment::query()
            ->whereIn('method', $this->posMethods())
            ->whereIn('sale_id', (clone $base)->toBase()->select('id'))
            ->selectRaw('method, sum(amount) as amount, count(distinct sale_id) as nota')
            ->groupBy('method')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row): array => [
                'method' => $this->methodValue($row->method),
                'label' => PaymentMethod::tryFrom($this->methodValue($row->method))?->label() ?? $this->methodValue($row->method),
                'amount' => (int) $row->amount,
                'nota' => (int) $row->nota,
            ])
            ->all();
    }

    /**
     * Metode pembayaran yang memegang rupiah terbanyak per shift.
     *
     * @param  Builder  $base  query `sales` yang sudah di-scope ke pembaca
     * @param  array<int, int|null>  $shiftIds
     * @return array<int|null, string>
     */
    private function mainMethodPerShift(Builder $base, array $shiftIds): array
    {
        $rows = SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->whereIn('sale_payments.method', $this->posMethods())
            ->whereIn('sales.id', (clone $base)->toBase()->select('id'))
            ->selectRaw('sales.shift_id, sale_payments.method, sum(sale_payments.amount) as amount')
            ->groupBy('sales.shift_id', 'sale_payments.method')
            ->get()
            ->groupBy('shift_id');

        $result = [];

        foreach ($rows as $shiftId => $group) {
            $top = $group->sortByDesc('amount')->first();
            $result[$shiftId] = $this->methodValue($top->method);
        }

        return $result;
    }

    /**
     * Hanya metode yang bisa dipakai di kasir. `Transfer` khusus untuk bayar
     * penitip dan tidak pernah muncul di rekap penjualan -- menampilkannya akan
     * membuat baris "Transfer Rp 0" yang dibaca kasir sebagai data hilang.
     *
     * @return array<int, string>
     */
    private function posMethods(): array
    {
        return array_map(fn (PaymentMethod $method): string => $method->value, PaymentMethod::pos());
    }

    /**
     * Nilai `method` dari baris agregat.
     */
    private function methodValue(mixed $method): string
    {
        return $method instanceof PaymentMethod ? $method->value : (string) $method;
    }
}
