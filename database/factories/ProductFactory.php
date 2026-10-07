<?php

namespace Database\Factories;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\PackagingType;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'series_id' => ProductSeries::factory(),
            'name' => fake()->unique()->words(2, true),
            'casting_code' => fake()->unique()->bothify('??-##?'),
            'year' => fake()->numberBetween(1998, 2024),
            'color' => fake()->randomElement(['Red', 'Blue', 'Silver', 'Black', 'Yellow', 'Green']),
            'packaging_type' => PackagingType::Carded,
            'card_condition' => CardCondition::Mint,
            'blister_condition' => BlisterCondition::Clear,
            'factory_barcode_ref' => fake()->unique()->numerify('#############'),
            'default_list_price' => fake()->randomElement([30_000, 35_000, 45_000, 50_000, 55_000, 75_000]),
            'photos' => [],
            'tags' => [],
            'needs_review' => false,
            'status' => ProductStatus::Active,
        ];
    }

    public function needsReview(): static
    {
        return $this->state(fn () => [
            'needs_review' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => ProductStatus::Inactive,
        ]);
    }

    public function loose(): static
    {
        return $this->state(fn () => [
            'packaging_type' => PackagingType::Loose,
        ]);
    }
}
