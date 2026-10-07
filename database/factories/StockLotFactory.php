<?php

namespace Database\Factories;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\DiscountPolicy;
use App\Enums\LotStatus;
use App\Enums\OwnerType;
use App\Enums\SchemeType;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\Rack;
use App\Models\StockLot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockLot>
 */
class StockLotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // SRS Lampiran A: CN01-HW-001 / OW00-HW-001
            'sku' => sprintf('CN%02d-HW-%03d', fake()->numberBetween(1, 99), fake()->unique()->numberBetween(1, 9999)),
            'consignment_id' => Consignment::factory(),
            'sequence' => fake()->numberBetween(1, 9999),
            'category_code' => 'HW',
            'owner_type' => OwnerType::Consign,
            'owner_code' => 'CN01',
            'consignor_id' => Consignor::factory(),
            'product_id' => Product::factory(),
            'card_condition' => CardCondition::Mint,
            'blister_condition' => BlisterCondition::Clear,
            'list_price' => 45_000,
            'cost_price' => null,
            'scheme_type' => SchemeType::Percentage,
            'scheme_rate' => 20,
            'scheme_amount' => null,
            'discount_policy' => DiscountPolicy::StoreBears,
            'terms_version' => 1,
            'qty_received' => 5,
            'qty_on_hand' => 5,
            'labels_printed' => 0,
            'rack_id' => Rack::factory(),
            'status' => LotStatus::Available,
            'last_sold_at' => null,
        ];
    }

    /**
     * Stok milik toko sendiri (Stock In Pribadi): tanpa consignment,
     * tanpa penitip, dan memakai kode pemilik OW00.
     */
    public function own(): static
    {
        return $this->state(fn () => [
            'owner_type' => OwnerType::Own,
            'owner_code' => 'OW00',
            'consignment_id' => null,
            'consignor_id' => null,
            'cost_price' => fake()->randomElement([25_000, 30_000, 35_000, 40_000]),
            'scheme_type' => null,
            'scheme_rate' => null,
            'scheme_amount' => null,
            'discount_policy' => null,
        ]);
    }

    public function sku(string $sku): static
    {
        return $this->state(fn () => [
            'sku' => $sku,
        ]);
    }

    public function ownedBy(Consignor $consignor): static
    {
        return $this->state(fn () => [
            'owner_type' => OwnerType::Consign,
            'owner_code' => $consignor->consignor_code,
            'consignor_id' => $consignor->id,
        ]);
    }

    public function qty(int $qty): static
    {
        return $this->state(fn () => [
            'qty_received' => $qty,
            'qty_on_hand' => $qty,
        ]);
    }

    public function soldOut(): static
    {
        return $this->state(fn () => [
            'qty_on_hand' => 0,
            'status' => LotStatus::SoldOut,
            'last_sold_at' => now(),
        ]);
    }

    public function returned(): static
    {
        return $this->state(fn () => [
            'qty_on_hand' => 0,
            'status' => LotStatus::Returned,
        ]);
    }

    public function writtenOff(): static
    {
        return $this->state(fn () => [
            'qty_on_hand' => 0,
            'status' => LotStatus::WrittenOff,
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn () => [
            'status' => LotStatus::Void,
        ]);
    }

    public function printedLabels(int $count = 1): static
    {
        return $this->state(fn () => [
            'labels_printed' => $count,
        ]);
    }
}
