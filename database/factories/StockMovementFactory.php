<?php

namespace Database\Factories;

use App\Enums\MovementType;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 *
 * Tabel bersifat append-only: hanya created_at, tanpa updated_at.
 */
class StockMovementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lot_id' => StockLot::factory(),
            'type' => MovementType::InConsign,
            'qty_delta' => 5,
            'ref_type' => null,
            'ref_id' => null,
            'actor_id' => User::factory()->staff(),
            'device_id' => fake()->uuid(),
            'reason' => null,
            'balance_after' => 5,
        ];
    }

    public function inConsign(int $qty = 5): static
    {
        return $this->state(fn () => [
            'type' => MovementType::InConsign,
            'qty_delta' => $qty,
            'balance_after' => $qty,
        ]);
    }

    public function inOwn(int $qty = 5): static
    {
        return $this->state(fn () => [
            'type' => MovementType::InOwn,
            'qty_delta' => $qty,
            'balance_after' => $qty,
        ]);
    }

    public function sale(int $qty = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => MovementType::Sale,
            'qty_delta' => -$qty,
            'ref_type' => 'SALE',
            'balance_after' => max(0, ($attributes['balance_after'] ?? 0) - $qty),
        ]);
    }

    public function quarantineIn(int $qty = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => MovementType::QuarantineIn,
            'qty_delta' => -$qty,
            'ref_type' => 'QUARANTINE',
            'balance_after' => max(0, ($attributes['balance_after'] ?? 0) - $qty),
        ]);
    }

    public function adjustmentMinus(int $qty = 1, ?string $reason = null): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => MovementType::AdjMinus,
            'qty_delta' => -$qty,
            'reason' => $reason,
            'balance_after' => max(0, ($attributes['balance_after'] ?? 0) - $qty),
        ]);
    }

    public function rtv(int $qty = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => MovementType::Rtv,
            'qty_delta' => -$qty,
            'ref_type' => 'RTV',
            'balance_after' => max(0, ($attributes['balance_after'] ?? 0) - $qty),
        ]);
    }

    public function by(User $actor): static
    {
        return $this->state(fn () => [
            'actor_id' => $actor->id,
        ]);
    }
}
