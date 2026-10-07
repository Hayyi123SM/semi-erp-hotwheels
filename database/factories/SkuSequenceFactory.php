<?php

namespace Database\Factories;

use App\Models\SkuSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SkuSequence>
 *
 * Tabel memakai primary key komposit (owner_code, category_code) tanpa kolom id.
 */
class SkuSequenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_code' => 'CN'.str_pad((string) fake()->unique()->numberBetween(1, 999), 2, '0', STR_PAD_LEFT),
            'category_code' => 'HW',
            'last_seq' => 0,
        ];
    }

    /**
     * Nama "for" tidak boleh dipakai karena bentrok dengan Factory::for().
     */
    public function forOwner(string $ownerCode, string $categoryCode = 'HW'): static
    {
        return $this->state(fn () => [
            'owner_code' => $ownerCode,
            'category_code' => $categoryCode,
        ]);
    }

    public function at(int $lastSeq): static
    {
        return $this->state(fn () => [
            'last_seq' => $lastSeq,
        ]);
    }
}
