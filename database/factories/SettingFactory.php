<?php

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'setting.'.fake()->unique()->slug(2),
            'value' => [],
            'description' => fake()->sentence(),
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function key(string $key, array $value = []): static
    {
        return $this->state(fn () => [
            'key' => $key,
            'value' => $value,
        ]);
    }
}
