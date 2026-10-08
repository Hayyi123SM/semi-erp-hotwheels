<?php

namespace App\Services\Report;

use App\Enums\OwnerType;
use App\Enums\SaleStatus;
use App\Enums\SchemeType;
use App\Models\SaleItem;
use App\Models\StockLot;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Laba toko dari dua sumber yang berbeda hukumnya: stok pribadi dan fee titipan.
 *
 * Stok pribadi menghasilkan laba (harga jual minus HPP), barang titipan
 * menghasilkan fee (hak penitip bukan pendapatan toko -- itu uang orang lain).
 * Menjumlahkan keduanya menjadi satu "penjualan" membuat toko tampak lebih besar
 * dan menutup bagian mana uangnya benar-benar untuk toko.
 *
 * Invarian BR-05 dijaga laporan ini benar-benar menghitung ulang, bukan sekadar
 * menampilkan kolom yang sudah tersimpan: `bruto = fee + hak penitip` hanya bisa
 * dipercaya kalau angka di sisi kiri dan kanan datang dari potongan snapshot yang
 * sama. Kalau selisihnya tidak nol, barangnya terjual dengan skema yang tidak
 * utuh tersimpan -- dan laporan menolak menyebutnya sehat.
 */
class MarginReportService
{
    public const DIMENSIONS = ['seri', 'penitip', 'sku', 'hari'];

    /**
     * Ringkasan periode: bruto, HPP, laba pribadi, fee titipan, dan pembagiannya.
     *
     * @return array{
     *     bruto: int,
     *     hpp: int,
     *     labaPribadi: int,
     *     feeTitipan: int,
     *     hakPenitip: int,
     *     pendapatanToko: int,
     *     sharePribadi: float,
     *     shareFee: float,
     *     nota: int,
     * }
     */
    public function summary(?string $from, ?string $to): array
    {
        [$start, $end] = ReportPeriod::span($from, $to);

        $row = $this->base($start, $end)
            ->leftJoin('stock_lots', 'stock_lots.id', '=', 'sale_items.lot_id')
            ->selectRaw(
                'count(distinct sales.id) as nota,
                 coalesce(sum(sale_items.sell_price * sale_items.qty), 0) as bruto,
                 coalesce(sum(case when stock_lots.owner_type = ? then sale_items.sell_price * sale_items.qty else 0 end), 0) as bruto_own,
                 coalesce(sum(case when stock_lots.owner_type = ? then sale_items.cost_price_snapshot * sale_items.qty else 0 end), 0) as hpp,
                 coalesce(sum(sale_items.fee_toko), 0) as fee,
                 coalesce(sum(sale_items.hak_penitip), 0) as hak',
                [OwnerType::Own->value, OwnerType::Own->value],
            )
            ->first();

        $labaPribadi = (int) ($row->bruto_own ?? 0) - (int) ($row->hpp ?? 0);
        $fee = (int) ($row->fee ?? 0);
        $pendapatanToko = $labaPribadi + $fee;

        return [
            'bruto' => (int) ($row->bruto ?? 0),
            'hpp' => (int) ($row->hpp ?? 0),
            'labaPribadi' => $labaPribadi,
            'feeTitipan' => $fee,
            'hakPenitip' => (int) ($row->hak ?? 0),
            'pendapatanToko' => $pendapatanToko,
            'sharePribadi' => $this->percent($labaPribadi, $pendapatanToko),
            'shareFee' => $this->percent($fee, $pendapatanToko),
            'nota' => (int) ($row->nota ?? 0),
        ];
    }

    /**
     * Rekonsiliasi invarian BR-05: `bruto titipan = hak penitip + fee toko`.
     *
     * Perhitungan dilakukan atas snapshot di `sale_items` yang sama dengan ringkasan.
     *
     * @return array{brutoTitipan: int, hakPenitip: int, feeToko: int, selisih: int, ok: bool}
     */
    public function reconciliation(?string $from, ?string $to): array
    {
        [$start, $end] = ReportPeriod::span($from, $to);

        $row = $this->base($start, $end)
            ->leftJoin('stock_lots', 'stock_lots.id', '=', 'sale_items.lot_id')
            ->selectRaw(
                'coalesce(sum(case when stock_lots.owner_type = ? then sale_items.sell_price * sale_items.qty else 0 end), 0) as bruto_titipan,
                 coalesce(sum(sale_items.hak_penitip), 0) as hak,
                 coalesce(sum(sale_items.fee_toko), 0) as fee',
                [OwnerType::Consign->value],
            )
            ->first();

        $brutoTitipan = (int) ($row->bruto_titipan ?? 0);
        $hak = (int) ($row->hak ?? 0);
        $fee = (int) ($row->fee ?? 0);

        return [
            'brutoTitipan' => $brutoTitipan,
            'hakPenitip' => $hak,
            'feeToko' => $fee,
            'selisih' => $brutoTitipan - ($hak + $fee),
            'ok' => $brutoTitipan === ($hak + $fee),
        ];
    }

    /**
     * Baris analisis per dimensi, untuk bagan dan tabel perbandingan.
     *
     * @return array<int, array{label: string, bruto: int, laba: int, fee: int, hak: int, pendapatan: int, share: float}>
     */
    public function dimension(string $dimension, ?string $from, ?string $to): array
    {
        if (! in_array($dimension, self::DIMENSIONS, true)) {
            throw new InvalidArgumentException("Dimensi laporan tidak dikenal: {$dimension}.");
        }

        [$start, $end] = ReportPeriod::span($from, $to);

        $query = $this->base($start, $end)
            ->leftJoin('stock_lots', 'stock_lots.id', '=', 'sale_items.lot_id')
            ->leftJoin('consignors', 'consignors.id', '=', 'stock_lots.consignor_id')
            ->leftJoin('products', 'products.id', '=', 'stock_lots.product_id')
            ->leftJoin('product_series', 'product_series.id', '=', 'products.series_id');

        $key = $this->dimensionKey($dimension);

        $rows = $query
            ->limit(15)
            ->selectRaw("$key as k,
                 coalesce(sum(sale_items.sell_price * sale_items.qty), 0) as bruto,
                 coalesce(sum(sale_items.qty), 0) as qty,
                 coalesce(sum(case when stock_lots.owner_type = ? then sale_items.sell_price * sale_items.qty else 0 end), 0) as bruto_own,
                 coalesce(sum(case when stock_lots.owner_type = ? then sale_items.cost_price_snapshot * sale_items.qty else 0 end), 0) as hpp,
                 coalesce(sum(sale_items.fee_toko), 0) as fee,
                 coalesce(sum(sale_items.hak_penitip), 0) as hak", [OwnerType::Own->value, OwnerType::Own->value])
            ->groupBy($key)
            ->orderByDesc('bruto')
            ->get();

        // `$total` dihitung dari baris yang sama dengan share masing-masing,
        // supaya pembilang dan penyebut tidak bisa berbeda sumber.
        $total = $rows->sum(fn ($row): int => (int) $row->bruto_own - (int) $row->hpp + (int) $row->fee);

        return $rows->map(fn ($row): array => [
            'label' => $this->dimensionLabel($dimension, $row->k),
            'bruto' => (int) $row->bruto,
            'qty' => (int) $row->qty,
            'laba' => (int) $row->bruto_own - (int) $row->hpp,
            'fee' => (int) $row->fee,
            'hak' => (int) $row->hak,
            'pendapatan' => (int) $row->bruto_own - (int) $row->hpp + (int) $row->fee,
            'share' => $total > 0 ? round(((int) $row->bruto_own - (int) $row->hpp + (int) $row->fee) / $total * 100, 1) : 0.0,
        ])->values()->all();
    }

    /**
     * Take-rate per skema: dari Rp100 penjualan titipan, berapa yang jadi milik toko.
     *
     * @return array<int, array{label: string, lines: int, bruto: int, fee: int, rate: float}>
     */
    public function takeRates(?string $from, ?string $to): array
    {
        [$start, $end] = ReportPeriod::span($from, $to);

        return $this->base($start, $end)
            ->selectRaw(
                'sale_items.scheme_type,
                 sale_items.scheme_rate,
                 sale_items.scheme_amount,
                 count(*) as lines_count,
                 coalesce(sum(sale_items.sell_price * sale_items.qty), 0) as bruto,
                 coalesce(sum(sale_items.fee_toko), 0) as fee'
            )
            ->groupBy('sale_items.scheme_type', 'sale_items.scheme_rate', 'sale_items.scheme_amount')
            ->orderByDesc('fee')
            ->get()
            ->map(fn ($row): array => [
                'label' => $this->schemeLabel($row->scheme_type, $row->scheme_rate, $row->scheme_amount),
                'lines' => (int) $row->lines_count,
                'bruto' => (int) $row->bruto,
                'fee' => (int) $row->fee,
                'rate' => (int) $row->bruto > 0 ? round((int) $row->fee / (int) $row->bruto * 100, 1) : 0.0,
            ])
            ->all();
    }

    /**
     * Insight: SKU terlaris, sell-through penitip, dead stock, dan margin negatif.
     *
     * `negative_margin_flag` (BR-06) belum ada di `sale_items` (baru ada di
     * `stock_lots`), jadi laporan ini menghitungnya ulang dari snapshot:
     * `fee_toko < 0` berarti harga jual di bawah hak penitip -- tanda diskon
     * menelan seluruh fee toko.
     *
     * @return array{
     *     topSku: array<int, array{sku: string, qty: int, bruto: int}>,
     *     sellThrough: array<int, array{name: string, sold: int, received: int, rate: float|null}>,
     *     deadStock: array<int, array{sku: string, product: string, owner: string, qty: int, since: string|null}>,
     *     negativeMargin: array{lines: int, amount: int},
     * }
     */
    public function insights(?string $from, ?string $to): array
    {
        [$start, $end] = ReportPeriod::span($from, $to);

        return [
            'topSku' => $this->topSku($start, $end),
            'sellThrough' => $this->sellThrough($start, $end),
            'deadStock' => (new StockReportService)->deadStock(),
            'negativeMargin' => $this->negativeMargin($start, $end),
        ];
    }

    /**
     * Baris detail untuk ekspor, satu per kelompok dimensi.
     *
     * @return array<int, array<int, mixed>>
     */
    public function dimensionRows(string $dimension, ?string $from, ?string $to): array
    {
        return array_map(fn (array $row): array => [
            $row['label'],
            $row['bruto'],
            $row['laba'],
            $row['fee'],
            $row['hak'],
            $row['pendapatan'],
            $row['share'],
        ], $this->dimension($dimension, $from, $to));
    }

    private function base(Carbon $start, Carbon $end): Builder
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', SaleStatus::Paid->value)
            ->whereBetween('sales.sold_at', [$start, $end]);
    }

    /**
     * Ekspresi `GROUP BY` satu dimensi. Satu tempat, supaya label dan agregasi
     * tidak bisa menunjuk dua kolom yang berbeda.
     */
    private function dimensionKey(string $dimension): string
    {
        return match ($dimension) {
            'seri' => 'product_series.name',
            'penitip' => 'consignors.name',
            'sku' => 'sale_items.sku',
            'hari' => 'date(sales.sold_at)',
        };
    }

    private function dimensionLabel(string $dimension, mixed $key): string
    {
        if ($dimension === 'hari') {
            return Carbon::parse((string) $key)->format('d M');
        }

        return (string) ($key ?? 'PRIBADI');
    }

    private function schemeLabel(mixed $type, mixed $rate, mixed $amount): string
    {
        if ($type instanceof SchemeType) {
            $enum = $type;
        } elseif (is_string($type) || $type === null) {
            $enum = $type === null ? null : SchemeType::tryFrom($type);
        } else {
            $enum = null;
        }

        $name = match ($enum) {
            SchemeType::Percentage => 'Persentase',
            SchemeType::Nett => 'Nett',
            SchemeType::Flat => 'Flat',
            default => null,
        };

        return match ($name) {
            'Persentase' => 'Persentase'.($rate !== null ? ' · '.str_replace('.', ',', (string) round((float) $rate, 1)).'%' : ''),
            'Nett' => 'Nett'.($amount !== null ? ' · Rp'.number_format((int) $amount, 0, ',', '.') : ''),
            'Flat' => 'Flat'.($amount !== null ? ' · Rp'.number_format((int) $amount, 0, ',', '.') : ''),
            default => 'Tanpa skema',
        };
    }

    private function percent(int $part, int $total): float
    {
        return $total > 0 ? round($part / $total * 100, 1) : 0.0;
    }

    /**
     * @return array<int, array{sku: string, qty: int, bruto: int}>
     */
    private function topSku(Carbon $start, Carbon $end): array
    {
        return $this->base($start, $end)
            ->selectRaw('sale_items.sku, sum(sale_items.qty) as qty, sum(sale_items.sell_price * sale_items.qty) as bruto')
            ->groupBy('sale_items.sku')
            ->orderByDesc('bruto')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'sku' => (string) $row->sku,
                'qty' => (int) $row->qty,
                'bruto' => (int) $row->bruto,
            ])
            ->all();
    }

    /**
     * Berapa bagian barang masuk satu penitip yang laku dalam periode ini.
     *
     * @return array<int, array{name: string, sold: int, received: int, rate: float|null}>
     */
    private function sellThrough(Carbon $start, Carbon $end): array
    {
        $sold = $this->base($start, $end)
            ->leftJoin('stock_lots', 'stock_lots.id', '=', 'sale_items.lot_id')
            ->leftJoin('consignors', 'consignors.id', '=', 'stock_lots.consignor_id')
            ->where('stock_lots.owner_type', OwnerType::Consign->value)
            ->selectRaw('coalesce(stock_lots.consignor_id, 0) as consignor_id, coalesce(consignors.name, ?) as name, sum(sale_items.qty) as sold', ['Tanpa penitip'])
            ->groupBy('stock_lots.consignor_id', 'consignors.name')
            ->get()
            ->keyBy('consignor_id');

        $received = StockLot::query()
            ->where('owner_type', OwnerType::Consign->value)
            ->selectRaw('consignor_id, sum(qty_received) as received')
            ->groupBy('consignor_id')
            ->get()
            ->keyBy('consignor_id');

        return $sold->map(function ($row) use ($received): array {
            $rec = $received->get($row->consignor_id)?->received ?? 0;

            return [
                'name' => (string) $row->name,
                'sold' => (int) $row->sold,
                'received' => (int) $rec,
                'rate' => (int) $rec > 0 ? round((int) $row->sold / (int) $rec * 100, 1) : null,
            ];
        })->values()->all();
    }

    /**
     * @return array{lines: int, amount: int}
     */
    private function negativeMargin(Carbon $start, Carbon $end): array
    {
        $rows = $this->base($start, $end)
            ->selectRaw('count(*) as lines_count, coalesce(sum(- sale_items.fee_toko), 0) as amount')
            ->whereNot('sale_items.fee_toko', null)
            ->where('sale_items.fee_toko', '<', 0)
            ->first();

        return [
            'lines' => (int) ($rows->lines_count ?? 0),
            'amount' => (int) ($rows->amount ?? 0),
        ];
    }
}
