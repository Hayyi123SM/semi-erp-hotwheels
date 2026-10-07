<?php

namespace Database\Factories;

use App\Enums\RackType;
use App\Models\Rack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rack>
 */
class RackFactory extends Factory
{
    /**
     * Kode rak dari SRS memakai bentuk A-01-01, jadi empat bagiannya dibatasi
     * 4 x 20 x 40 = 3.200 kombinasi.
     */
    private const PREFIXES = ['A', 'B', 'C', 'D'];

    private const SECTION_MAX = 20;

    private static int $counter = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => self::nextCode(),
            'zone' => strtoupper(fake()->randomElement(self::PREFIXES)),
            'type' => RackType::Storage,
            'capacity' => fake()->numberBetween(12, 200),
            'is_active' => true,
        ];
    }

    /**
     * Kode rak harus unik dalam satu test karena `racks.code` punya unique
     * index. Hanya 3.200 kombinasi acak terlalu sedikit: sebuah test yang
     * membuat belasan rack akan pernah bentrok, dan kegagalannya muncul
     * sebagai pelanggaran unique index yang tidak menjelaskan apa pun.
     *
     * Counter dipakai supaya tidak ada tebakan. 3.200 rak dalam satu proses
     * phpunit tidak terjadi, jadi pengulangan tidak pernah tercapai.
     */
    private static function nextCode(): string
    {
        $n = self::$counter++;
        $space = count(self::PREFIXES) * self::SECTION_MAX * 40;
        $n %= $space;

        return sprintf(
            '%s-%02d-%02d',
            self::PREFIXES[$n % count(self::PREFIXES)],
            intdiv($n, count(self::PREFIXES)) % self::SECTION_MAX + 1,
            intdiv($n, count(self::PREFIXES) * self::SECTION_MAX) % 40 + 1,
        );
    }

    public function display(): static
    {
        return $this->state(fn () => [
            'type' => RackType::Display,
        ]);
    }

    public function quarantine(): static
    {
        return $this->state(fn () => [
            'type' => RackType::Quarantine,
        ]);
    }

    public function rtvStaging(): static
    {
        return $this->state(fn () => [
            'type' => RackType::RtvStaging,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
