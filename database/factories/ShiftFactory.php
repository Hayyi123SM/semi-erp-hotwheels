<?php

namespace Database\Factories;

use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => fake()->uuid(),
            'user_id' => User::factory()->staff(),
            'opened_at' => now(),
            'opening_cash' => 500_000,
            'closed_at' => null,
            'closing_cash' => null,
            'cash_diff' => null,
            'closed_by' => null,
            'cash_diff_approved_by' => null,
            'status' => ShiftStatus::Open,
            'notes' => null,
        ];
    }

    /**
     * Shift milik satu kasir di satu perangkat, untuk pengujian siklus shift.
     */
    public function forDevice(?string $deviceId): static
    {
        return $this->state(fn (): array => [
            'device_id' => $deviceId,
        ]);
    }

    public function forUser(?User $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user?->getKey(),
        ]);
    }

    public function openedWith(int $openingCash, ?string $deviceId = null): static
    {
        return $this->state(fn (): array => [
            'device_id' => $deviceId ?? fake()->uuid(),
            'opening_cash' => $openingCash,
            'opened_at' => now(),
            'status' => ShiftStatus::Open,
        ]);
    }

    /**
     * Shift yang sudah ditutup dengan uang yang ada di laci.
     *
     * Default `closingCash` sama dengan `opening_cash`, jadi hasil pemakaiannya
     * adalah selisih nol: dipakai oleh pengujian yang menguji mekanisme shift,
     * bukan rekonsiliasi uangnya. Pengujian selisih tetap memakai `closed()` lalu
     * memberikan `closing_cash` sendiri.
     */
    public function closed(int $closingCash = 500_000, ?User $closedBy = null, ?User $approver = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'closed_at' => now(),
            'closing_cash' => $closingCash,
            'cash_diff' => $closingCash - ($attributes['opening_cash'] ?? 0),
            'closed_by' => $closedBy?->getKey(),
            'cash_diff_approved_by' => $approver?->getKey(),
            'status' => ShiftStatus::Closed,
        ]);
    }
}
