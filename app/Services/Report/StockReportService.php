<?php

namespace App\Services\Report;

use App\Enums\LotStatus;
use App\Enums\MovementType;
use App\Enums\OpnameScope;
use App\Enums\OwnerType;
use App\Models\Opname;
use App\Models\OpnameLine;
use App\Models\StockLot;
use App\Models\StockMovement;

/**
 * Stok sebagai posisi dan sebagai aliran.
 *
 * Dua pertanyaan yang berbeda: "berapa yang ada di rak sekarang" (posisi, dari
 * `stock_lots`) dan "gerakan apa yang terjadi selama periode ini" (aliran, dari
 * tabel `stock_movements` yang append-only). Nilai stok pribadi memakai HPP
 * (`cost_price`) dan nilai titipan memakai harga jual -- barang titipan bukan
 * aset toko, jadi menghitungnya dengan HPP akan mengarang kerugian yang tidak
 * pernah terjadi. Aturan ini dipakai Setiap halaman inventori lain, dan laporan
 * ini memakai angka yang sama persis.
 */
class StockReportService
{
    /**
     * Ambang "stok menipis" -- dua angka yang sama dengan Live Stock (FR-IC-02).
     */
    private const int LOW_STOCK_QTY = 1;

    /**
     * @return array{
     *     lots: int,
     *     units: int,
     *     ownUnits: int,
     *     consignUnits: int,
     *     ownValue: int,
     *     consignValue: int,
     *     consignors: int,
     *     lowStock: int,
     * }
     */
    public function valuation(): array
    {
        $row = StockLot::query()
            ->selectRaw(
                'count(*) as lots,
                 coalesce(sum(qty_on_hand), 0) as units,
                 coalesce(sum(case when owner_type = ? then qty_on_hand else 0 end), 0) as own_units,
                 coalesce(sum(case when owner_type = ? then qty_on_hand else 0 end), 0) as consign_units,
                 coalesce(sum(case when owner_type = ? then qty_on_hand * coalesce(cost_price, 0) else 0 end), 0) as own_value,
                 coalesce(sum(case when owner_type = ? then qty_on_hand * list_price else 0 end), 0) as consign_value,
                 count(case when qty_on_hand <= ? then 1 end) as low_stock',
                [OwnerType::Own->value, OwnerType::Consign->value, OwnerType::Own->value, OwnerType::Consign->value, self::LOW_STOCK_QTY],
            )
            ->first();

        return [
            'lots' => (int) ($row->lots ?? 0),
            'units' => (int) ($row->units ?? 0),
            'ownUnits' => (int) ($row->own_units ?? 0),
            'consignUnits' => (int) ($row->consign_units ?? 0),
            'ownValue' => (int) ($row->own_value ?? 0),
            'consignValue' => (int) ($row->consign_value ?? 0),
            'consignors' => StockLot::query()->where('owner_type', OwnerType::Consign->value)->whereNotNull('consignor_id')->distinct()->count('consignor_id'),
            'lowStock' => (int) ($row->low_stock ?? 0),
        ];
    }

    /**
     * Ringkasan gerakan selama periode, per jenis gerakan.
     *
     * @return array<int, array{type: string, label: string, lines: int, qty: int}>
     */
    public function movements(?string $from, ?string $to): array
    {
        [$start, $end] = ReportPeriod::span($from, $to);

        return StockMovement::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('type, count(*) as lines_count, coalesce(sum(qty_delta), 0) as qty')
            ->groupBy('type')
            ->orderByDesc('lines_count')
            ->get()
            ->map(fn ($row): array => [
                'type' => $row->type instanceof \BackedEnum ? $row->type->value : (string) $row->type,
                'label' => MovementType::tryFrom($row->type instanceof \BackedEnum ? $row->type->value : (string) $row->type)?->label()
                    ?? ($row->type instanceof \BackedEnum ? $row->type->value : (string) $row->type),
                'lines' => (int) $row->lines_count,
                'qty' => (int) $row->qty,
            ])
            ->all();
    }

    /**
     * Stok yang diam 90 hari atau lebih: masih ada di rak, tidak pernah keluar.
     *
     * Satu-satunya definisi "dead stock" di aplikasi; laporan margin memakai
     * hasil ini juga, supaya dua halaman tidak menyebut angka yang berbeda
     * untuk barang yang sama.
     *
     * @return array<int, array{sku: string, product: string, owner: string, qty: int, since: string}>
     */
    public function deadStock(): array
    {
        $cutoff = now()->subDays(90);

        return StockLot::query()
            ->with('product:id,name')
            ->with('consignor:id,name')
            ->where('qty_on_hand', '>', 0)
            ->where('status', LotStatus::Available->value)
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->get()
            ->map(fn (StockLot $lot): array => [
                'sku' => $lot->sku,
                'product' => $lot->product?->name ?? '',
                'owner' => $lot->owner_type === OwnerType::Consign ? ($lot->consignor?->name ?? 'TITIP') : 'PRIBADI',
                'qty' => $lot->qty_on_hand,
                'since' => $lot->created_at?->format('d M Y') ?? '—',
            ])
            ->values()
            ->all();
    }

    /**
     * Ringkasan sesi stok opname terbaru dan total selisihnya.
     *
     * @return array{
     *     sessions: array<int, array{no: string, scope: string, status: string, diffLines: int, diffQty: int, at: string}>,
     * }
     */
    public function opnames(): array
    {
        $sessions = Opname::query()
            ->with('rack:id,code')
            ->withCount('lines')
            ->latest()
            ->limit(20)
            ->get()
            ->map(function (Opname $opname): array {
                $diff = $this->diffSummary($opname->id);

                return [
                    'no' => $opname->opname_no,
                    'scope' => $opname->scope === OpnameScope::Rack ? 'Rak '.($opname->rack?->code ?? '—') : ($opname->scope?->label() ?? '—'),
                    'status' => $opname->status?->label() ?? '—',
                    'diffLines' => $diff['lines'],
                    'diffQty' => $diff['qty'],
                    'at' => $opname->submitted_at?->format('d M Y H:i') ?? ($opname->created_at?->format('d M Y H:i') ?? '—'),
                ];
            })
            ->all();

        return ['sessions' => $sessions];
    }

    /**
     * Baris selisih yang sudah ada angkanya, dan total qty penyimpangannya.
     *
     * @return array{lines: int, qty: int}
     */
    private function diffSummary(int $opnameId): array
    {
        $row = OpnameLine::query()
            ->where('opname_id', $opnameId)
            ->selectRaw('count(*) as diff_lines, coalesce(sum(abs(diff_qty)), 0) as qty')
            ->whereNotNull('diff_qty')
            ->first();

        return [
            'lines' => (int) ($row->diff_lines ?? 0),
            'qty' => (int) ($row->qty ?? 0),
        ];
    }
}
