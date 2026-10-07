<?php

namespace Database\Factories;

use App\Enums\ConsignmentStatus;
use App\Enums\OwnerType;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consignment>
 */
class ConsignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'doc_no' => 'CG-'.fake()->unique()->numerify('YYYYMMDD-###'),
            'owner_type' => OwnerType::Consign,
            'consignor_id' => Consignor::factory(),
            'source' => fake()->randomElement(['Toko', 'Bazar', 'Event', 'Titip External']),
            'consignment_date' => fake()->dateTimeBetween('-6 months')->format('Y-m-d'),
            'notes' => null,
            'qty_claimed' => null,
            'qty_received' => null,
            'variance_note' => null,
            'status' => ConsignmentStatus::Draft,
            'created_by' => User::factory()->staff(),
            'committed_at' => null,
            'committed_by' => null,
        ];
    }

    public function committed(): static
    {
        return $this->state(fn () => [
            'status' => ConsignmentStatus::Committed,
            'committed_at' => now(),
            'committed_by' => User::factory()->staff(),
        ]);
    }

    public function completed(): static
    {
        return $this->committed()->state(fn () => [
            'status' => ConsignmentStatus::Completed,
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn () => [
            'status' => ConsignmentStatus::Void,
        ]);
    }

    public function counted(int $claimed, int $received): static
    {
        return $this->state(fn () => [
            'qty_claimed' => $claimed,
            'qty_received' => $received,
            'variance_note' => $claimed === $received ? null : 'Selisih fisik vs klaim penitip.',
        ]);
    }
}
