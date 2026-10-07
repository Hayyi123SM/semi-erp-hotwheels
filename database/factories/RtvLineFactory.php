<?php

namespace Database\Factories;

use App\Models\RtvLine;
use App\Models\RtvNote;
use App\Models\StockLot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RtvLine>
 */
class RtvLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rtv_id' => RtvNote::factory(),
            'lot_id' => StockLot::factory(),
            'qty' => 1,
        ];
    }

    public function qty(int $qty): static
    {
        return $this->state(fn () => [
            'qty' => $qty,
        ]);
    }
}
