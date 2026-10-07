<?php

namespace Database\Factories;

use App\Enums\OpnameScope;
use App\Enums\OpnameStatus;
use App\Models\Opname;
use App\Models\Rack;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opname>
 */
class OpnameFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'opname_no' => 'OPN-'.fake()->unique()->numerify('YYYYMMDD-###'),
            'scope' => OpnameScope::All,
            'rack_id' => null,
            'status' => OpnameStatus::Draft,
            'started_at' => null,
            'closed_at' => null,
            'created_by' => User::factory()->staff(),
        ];
    }

    public function forRack(Rack $rack): static
    {
        return $this->state(fn () => [
            'scope' => OpnameScope::Rack,
            'rack_id' => $rack->id,
        ]);
    }

    public function counting(): static
    {
        return $this->state(fn () => [
            'status' => OpnameStatus::Counting,
            'started_at' => now(),
        ]);
    }

    public function pendingApproval(): static
    {
        return $this->counting()->state(fn () => [
            'status' => OpnameStatus::PendingApproval,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => OpnameStatus::Approved,
            'started_at' => now(),
            'closed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => OpnameStatus::Cancelled,
        ]);
    }
}
