<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\InputMethod;
use App\Enums\SchemeType;
use App\Models\StockLot;
use App\Services\Inventory\TermsCalculator;

/**
 * Satu baris penjualan yang sudah dihitung ulang oleh server.
 *
 * Angka di sini bukan angka yang dikirim layar kasir -- layar tidak pernah
 * mengirim harga sama sekali. Semuanya berasal dari `stock_lots` pada saat
 * penjualan ini dibuat, sehingga harga yang berubah di Master Data, stok yang
 * sudah terpotong penjualan lain, atau ketentuan skema yang diganti Owner tidak
 * bisa menghasilkan nota yang tidak cocok dengan barang yang keluar.
 *
 * `feeToko` dan `hakPenitip` berupa jumlah per baris, bukan per unit, mengikuti
 * `SaleItemFactory`: pembulatan skema terjadi di level unit
 * ({@see TermsCalculator}) dan hasilnya dikalikan qty
 * di sini, sekali.
 */
final readonly class PricedLine
{
    public function __construct(
        public StockLot $lot,
        public int $qty,
        public InputMethod $inputMethod,
        public int $listPrice,
        public int $sellPrice,
        public int $lineTotal,
        public ?SchemeType $schemeType,
        public ?float $schemeRate,
        public ?int $schemeAmount,
        public int $termsVersion,
        public ?int $costPrice,
        public int $feeToko,
        public ?int $hakPenitip,
    ) {}

    /**
     * Isi baris `sale_items`, tanpa `sale_id` yang baru diketahui setelah nota
     * dibuat.
     *
     * @return array<string, mixed>
     */
    public function saleItemAttributes(): array
    {
        return [
            'lot_id' => $this->lot->id,
            'sku' => $this->lot->sku,
            'owner_code' => $this->lot->owner_code,
            'qty' => $this->qty,
            'list_price' => $this->listPrice,
            'discount' => 0,
            'sell_price' => $this->sellPrice,
            'scheme_type' => $this->schemeType?->value,
            'scheme_rate' => $this->schemeRate,
            'scheme_amount' => $this->schemeAmount,
            'terms_version' => $this->termsVersion,
            'cost_price_snapshot' => $this->costPrice,
            'fee_toko' => $this->feeToko,
            'hak_penitip' => $this->hakPenitip,
            'input_method' => $this->inputMethod->value,
        ];
    }
}
