<?php

namespace Database\Factories;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = 90_000;
        $discount = 0;

        return [
            'client_sale_id' => null,
            'receipt_no' => 'TRX-'.fake()->unique()->numerify('YYYYMMDD-####'),
            'shift_id' => Shift::factory(),
            'device_id' => fake()->uuid(),
            'user_id' => User::factory()->staff(),
            'sold_at_client' => now(),
            'sold_at' => now(),
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'total' => $subtotal - $discount,
            'status' => SaleStatus::Paid,
            'voided_at' => null,
            'voided_by' => null,
            'void_reason' => null,
            'flags' => [],
            'synced_at' => now(),
        ];
    }

    /**
     * Transaksi dibuat perangkat yang sedang offline: sold_at belum diisi,
     * synced_at null, dan memakai client_sale_id untuk idempotensi sinkron.
     */
    public function offline(string $clientSaleId): static
    {
        return $this->state(fn () => [
            'client_sale_id' => $clientSaleId,
            'sold_at_client' => now(),
            'sold_at' => null,
            'synced_at' => null,
            'status' => SaleStatus::Paid,
        ]);
    }

    public function synced(): static
    {
        return $this->state(fn (array $attributes) => [
            'client_sale_id' => $attributes['client_sale_id'] ?? 'CS-'.fake()->uuid(),
            'sold_at' => $attributes['sold_at'] ?? now(),
            'synced_at' => now(),
        ]);
    }

    public function voided(string $reason = 'Salah input'): static
    {
        return $this->state(fn () => [
            'status' => SaleStatus::Voided,
            'voided_at' => now(),
            'voided_by' => User::factory()->owner(),
            'void_reason' => $reason,
        ]);
    }

    public function syncConflict(): static
    {
        return $this->state(fn () => [
            'status' => SaleStatus::SyncConflict,
        ]);
    }

    public function staleTerms(): static
    {
        return $this->state(fn () => [
            'status' => SaleStatus::TermsStale,
        ]);
    }
}
