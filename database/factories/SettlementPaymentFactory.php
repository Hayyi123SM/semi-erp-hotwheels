<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Settlement;
use App\Models\SettlementPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SettlementPayment>
 */
class SettlementPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'settlement_id' => Settlement::factory(),
            'method' => PaymentMethod::Transfer,
            'amount' => 758_000,
            'paid_at' => now(),
            'reference' => strtoupper(fake()->bothify('TRX-####-####')),
            'proof_path' => null,
            'notes' => null,
        ];
    }

    public function cash(int $amount = 758_000): static
    {
        return $this->state(fn () => [
            'method' => PaymentMethod::Cash,
            'amount' => $amount,
            'reference' => null,
        ]);
    }

    public function withProof(string $path = 'proof/settlement.pdf'): static
    {
        return $this->state(fn () => [
            'proof_path' => $path,
        ]);
    }
}
