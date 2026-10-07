<?php

namespace Database\Factories;

use App\Enums\ConsignorStatus;
use App\Enums\DiscountPolicy;
use App\Enums\LossLiability;
use App\Enums\SchemeType;
use App\Enums\SettlementCycle;
use App\Models\Consignor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consignor>
 */
class ConsignorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // SRS Lampiran A: kode pemilik pada SKU adalah CNxx (mis. CN01).
            'consignor_code' => 'CN'.str_pad((string) fake()->unique()->numberBetween(1, 999), 2, '0', STR_PAD_LEFT),
            'name' => fake()->company(),
            'wa_number' => '+628'.fake()->unique()->numerify('##########'),
            'wa_opt_in_at' => fake()->dateTimeBetween('-1 year'),
            'address' => fake()->address(),
            'agreement_date' => fake()->dateTimeBetween('-2 years')->format('Y-m-d'),
            'scheme_type' => SchemeType::Percentage,
            'scheme_rate' => fake()->randomElement([15, 20, 25, 30]),
            'scheme_amount' => null,
            'discount_policy' => DiscountPolicy::StoreBears,
            'loss_liability' => LossLiability::Store,
            'settlement_cycle' => SettlementCycle::Monthly,
            'min_payout' => 50_000,
            'bank_name' => fake()->randomElement(['BCA', 'BNI', 'BRI', 'Mandiri', 'BSI']),
            'bank_account' => fake()->numerify('##########'),
            'bank_holder' => fake()->name(),
            'status' => ConsignorStatus::Active,
            'notes' => null,
        ];
    }

    public function percentage(float $rate = 20): static
    {
        return $this->state(fn () => [
            'scheme_type' => SchemeType::Percentage,
            'scheme_rate' => $rate,
            'scheme_amount' => null,
        ]);
    }

    public function nett(int $amount = 38_000): static
    {
        return $this->state(fn () => [
            'scheme_type' => SchemeType::Nett,
            'scheme_rate' => null,
            'scheme_amount' => $amount,
        ]);
    }

    public function flat(int $amount = 8_000): static
    {
        return $this->state(fn () => [
            'scheme_type' => SchemeType::Flat,
            'scheme_rate' => null,
            'scheme_amount' => $amount,
        ]);
    }

    public function sharedDiscount(): static
    {
        return $this->state(fn () => [
            'discount_policy' => DiscountPolicy::Shared,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => ConsignorStatus::Suspended,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'status' => ConsignorStatus::Archived,
        ]);
    }

    public function withoutBank(): static
    {
        return $this->state(fn () => [
            'bank_name' => null,
            'bank_account' => null,
            'bank_holder' => null,
        ]);
    }
}
