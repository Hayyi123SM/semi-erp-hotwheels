<?php

namespace Database\Factories;

use App\Enums\SettlementStatus;
use App\Models\Consignor;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Settlement>
 */
class SettlementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bruto = 980_000;
        $fee = 186_000;
        $hak = 794_000;
        $refunds = 36_000;
        $net = $hak - $refunds;

        return [
            'settlement_no' => 'STL-'.fake()->unique()->numerify('YYYYMM-####'),
            'consignor_id' => Consignor::factory(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'cut_off_at' => null,
            'total_bruto' => $bruto,
            'total_fee' => $fee,
            'total_hak' => $hak,
            'refunds' => $refunds,
            'adjustments' => 0,
            'carry_over' => 0,
            'net_payable' => $net,
            'status' => SettlementStatus::Draft,
            'approved_at' => null,
            'approved_by' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => SettlementStatus::Approved,
            'approved_at' => now(),
            'approved_by' => User::factory()->owner(),
            'cut_off_at' => now(),
        ]);
    }

    public function paid(): static
    {
        return $this->approved()->state(fn () => [
            'status' => SettlementStatus::Paid,
        ]);
    }

    public function partiallyPaid(): static
    {
        return $this->approved()->state(fn () => [
            'status' => SettlementStatus::PartiallyPaid,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => SettlementStatus::Cancelled,
        ]);
    }

    public function withCarryOver(int $carryOver): static
    {
        return $this->state(fn (array $attributes) => [
            'carry_over' => $carryOver,
            'net_payable' => ($attributes['net_payable'] ?? 0) + $carryOver,
        ]);
    }
}
