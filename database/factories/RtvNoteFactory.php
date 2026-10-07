<?php

namespace Database\Factories;

use App\Enums\RtvStatus;
use App\Models\Consignor;
use App\Models\RtvNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RtvNote>
 */
class RtvNoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rtv_no' => 'RTV-'.fake()->unique()->numerify('YYYYMMDD-###'),
            'consignor_id' => Consignor::factory(),
            'status' => RtvStatus::Draft,
            'reason' => fake()->randomElement(['Penitip tidak lanjut, tidak laku', 'Barang rusak', 'Salah kirim penitip']),
            'executed_at' => null,
            'created_by' => User::factory()->staff(),
            'approved_by' => null,
        ];
    }

    public function verifying(): static
    {
        return $this->state(fn () => [
            'status' => RtvStatus::Verifying,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => RtvStatus::Approved,
            'approved_by' => User::factory()->owner(),
        ]);
    }

    public function executed(): static
    {
        return $this->approved()->state(fn () => [
            'status' => RtvStatus::Executed,
            'executed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => RtvStatus::Cancelled,
        ]);
    }
}
