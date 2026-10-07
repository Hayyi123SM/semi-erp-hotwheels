<?php

namespace Database\Factories;

use App\Enums\InputMethod;
use App\Enums\SchemeType;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockLot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleItem>
 */
class SaleItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $qty = 2;
        $listPrice = 45_000;
        $sellPrice = 45_000;
        $fee = 9_000;
        $hak = 36_000;

        return [
            'sale_id' => Sale::factory(),
            'lot_id' => StockLot::factory(),
            'sku' => 'CN01-HW-003',
            'owner_code' => 'CN01',
            'qty' => $qty,
            'list_price' => $listPrice,
            'discount' => 0,
            'sell_price' => $sellPrice,
            'scheme_type' => SchemeType::Percentage,
            'scheme_rate' => 20,
            'scheme_amount' => null,
            'terms_version' => 1,
            'cost_price_snapshot' => null,
            'fee_toko' => $fee * $qty,
            'hak_penitip' => $hak * $qty,
            'input_method' => InputMethod::Scan,
        ];
    }

    public function qty(int $qty): static
    {
        return $this->state(fn (array $attributes) => [
            'qty' => $qty,
            'fee_toko' => (int) round(($attributes['fee_toko'] ?? 0) / max(1, $attributes['qty'] ?? 1) * $qty),
            'hak_penitip' => (int) round(($attributes['hak_penitip'] ?? 0) / max(1, $attributes['qty'] ?? 1) * $qty),
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn () => [
            'input_method' => InputMethod::Manual,
        ]);
    }

    public function nett(int $amount = 38_000): static
    {
        return $this->state(fn (array $attributes) => [
            'scheme_type' => SchemeType::Nett,
            'scheme_rate' => null,
            'scheme_amount' => $amount,
            'sell_price' => $attributes['sell_price'] ?? $amount,
        ]);
    }
}
