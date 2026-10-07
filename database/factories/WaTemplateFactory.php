<?php

namespace Database\Factories;

use App\Enums\WaCategory;
use App\Models\WaTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaTemplate>
 */
class WaTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'E-receipt Consignment '.fake()->unique()->numerify('##'),
            'category' => WaCategory::ConsignmentReceipt,
            'body' => "Halo {consignor_name},\n\nBarang Anda sudah kami terima:\n{doc_no}\n\nTotal unit: {total_unit}\n\nTerima kasih.",
            'variables' => ['consignor_name', 'doc_no', 'total_unit'],
            'is_active' => true,
        ];
    }

    public function receipt(): static
    {
        return $this->state(fn () => [
            'category' => WaCategory::Receipt,
            'name' => 'Struk Penjualan '.fake()->unique()->numerify('##'),
        ]);
    }

    public function statement(): static
    {
        return $this->state(fn () => [
            'category' => WaCategory::Statement,
            'name' => 'Statement Settlement '.fake()->unique()->numerify('##'),
        ]);
    }

    public function rtv(): static
    {
        return $this->state(fn () => [
            'category' => WaCategory::Rtv,
            'name' => 'Retur Ke Penitip '.fake()->unique()->numerify('##'),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
