<?php

namespace Database\Factories;

use App\Enums\LedgerType;
use App\Models\Consignor;
use App\Models\ConsignorLedger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsignorLedger>
 *
 * Tabel bersifat append-only: hanya created_at, tanpa updated_at.
 */
class ConsignorLedgerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'consignor_id' => Consignor::factory(),
            'type' => LedgerType::SaleAccrual,
            'amount' => 36_000,
            'sale_item_id' => null,
            'sale_id' => null,
            'settlement_id' => null,
            'reason' => null,
            'created_at' => now(),
        ];
    }

    public function accrual(int $amount = 36_000): static
    {
        return $this->state(fn () => [
            'type' => LedgerType::SaleAccrual,
            'amount' => $amount,
        ]);
    }

    public function refundReversal(int $amount = 36_000): static
    {
        return $this->state(fn () => [
            'type' => LedgerType::RefundReversal,
            'amount' => -$amount,
        ]);
    }

    public function adjustment(int $amount, ?string $reason = null): static
    {
        return $this->state(fn () => [
            'type' => LedgerType::Adjustment,
            'amount' => $amount,
            'reason' => $reason,
        ]);
    }

    public function settlementPayment(int $amount = 758_000): static
    {
        return $this->state(fn () => [
            'type' => LedgerType::SettlementPayment,
            'amount' => -$amount,
        ]);
    }

    public function carryOver(int $amount): static
    {
        return $this->state(fn () => [
            'type' => LedgerType::CarryOver,
            'amount' => $amount,
        ]);
    }

    public function locked(int $settlementId): static
    {
        return $this->state(fn () => [
            'settlement_id' => $settlementId,
        ]);
    }
}
