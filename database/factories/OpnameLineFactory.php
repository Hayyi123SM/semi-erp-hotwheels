<?php

namespace Database\Factories;

use App\Enums\AdjustmentReason;
use App\Enums\OpnameLineStatus;
use App\Models\Opname;
use App\Models\OpnameLine;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpnameLine>
 */
class OpnameLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'opname_id' => Opname::factory(),
            'lot_id' => StockLot::factory(),
            'system_qty' => 5,
            'counted_qty' => 5,
            'diff_qty' => 0,
            'reason' => null,
            'status' => OpnameLineStatus::Ok,
            'approved_by' => null,
        ];
    }

    /**
     * Baris hasil hitung fisik; diff_qty dihitung dari system vs counted.
     */
    public function counted(int $systemQty, int $countedQty): static
    {
        return $this->state(fn () => [
            'system_qty' => $systemQty,
            'counted_qty' => $countedQty,
            'diff_qty' => $countedQty - $systemQty,
            'status' => $countedQty === $systemQty ? OpnameLineStatus::Ok : OpnameLineStatus::Pending,
        ]);
    }

    public function shortage(int $systemQty = 5, int $countedQty = 3, AdjustmentReason $reason = AdjustmentReason::Lost): static
    {
        return $this->counted($systemQty, $countedQty)->state(fn () => [
            'reason' => $reason,
        ]);
    }

    public function overage(int $systemQty = 3, int $countedQty = 5, AdjustmentReason $reason = AdjustmentReason::Found): static
    {
        return $this->counted($systemQty, $countedQty)->state(fn () => [
            'reason' => $reason,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => OpnameLineStatus::Approved,
            'approved_by' => User::factory()->owner(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => OpnameLineStatus::Rejected,
            'approved_by' => User::factory()->owner(),
        ]);
    }
}
