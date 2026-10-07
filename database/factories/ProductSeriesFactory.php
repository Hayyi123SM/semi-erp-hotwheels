<?php

namespace Database\Factories;

use App\Models\ProductSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductSeries>
 */
class ProductSeriesFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Dipakai sebagai category_code pada SKU (HW, MB, DD, ...).
            'code' => strtoupper(fake()->unique()->lexify('??')),
            'name' => fake()->unique()->words(2, true).' Series',
        ];
    }
}
