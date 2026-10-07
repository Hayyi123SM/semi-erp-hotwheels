<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Sale;
use App\Models\SalePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalePayment>
 */
class SalePaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            'method' => PaymentMethod::Cash,
            'amount' => 90_000,
            'reference' => null,
        ];
    }

    public function qris(int $amount = 90_000): static
    {
        return $this->state(fn () => [
            'method' => PaymentMethod::Qris,
            'amount' => $amount,
            'reference' => strtoupper(fake()->bothify('QR##-####-####')),
        ]);
    }

    public function transfer(int $amount = 90_000): static
    {
        return $this->state(fn () => [
            'method' => PaymentMethod::Transfer,
            'amount' => $amount,
            'reference' => fake()->bankAccountNumber(),
        ]);
    }
}
