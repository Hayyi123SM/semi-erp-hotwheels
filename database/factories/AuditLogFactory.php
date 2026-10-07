<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 *
 * Tabel bersifat append-only: hanya created_at, tanpa updated_at.
 *
 * Nilai defaultnya meniru baris yang benar-benar ditulis `AuditLogger`:
 * aksi huruf besar, `entity` berupa `class_basename()` tanpa namespace, dan
 * identitas pada `entity_id`. Factory yang menulis `action => 'created'` dan
 * `entity => 'App\Models\Consignor'` menghasilkan baris yang tidak akan pernah
 * muncul di produksi, dan laporan audit yang diuji terhadapnya terlihat benar
 * selama isinya tidak pernah dibandingkan dengan data aslinya.
 */
class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->owner(),
            'device_id' => fake()->uuid(),
            'action' => 'CREATED',
            'entity' => 'Consignor',
            'entity_id' => null,
            'entity_key' => null,
            'before' => null,
            'after' => [],
            'reason' => null,
            'created_at' => now(),
        ];
    }

    /**
     * Baris yang menunjuk satu baris tabel lewat `entity_id`.
     */
    public function forEntity(string $entity, int $id): static
    {
        return $this->state(fn (array $attributes) => [
            'entity' => $entity,
            'entity_id' => $id,
            'entity_key' => null,
        ]);
    }

    /**
     * Baris yang menunjuk `Setting`, yang identitasnya berupa teks.
     */
    public function forSetting(string $key): static
    {
        return $this->state(fn (array $attributes) => [
            'entity' => 'Setting',
            'entity_id' => null,
            'entity_key' => $key,
        ]);
    }

    /**
     * Baris tanpa user: operasi sistem, bukan yang dilakukan seseorang.
     */
    public function bySystem(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
        ]);
    }

    /**
     * Baris pembuatan: seluruh isi baris baru ada di `after`, `before` kosong.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function created(array $attributes = []): static
    {
        return $this->state(fn () => [
            'action' => 'CREATED',
            'before' => null,
            'after' => $attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function updated(array $before = [], array $after = []): static
    {
        return $this->state(fn () => [
            'action' => 'UPDATED',
            'before' => $before,
            'after' => $after,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'action' => 'ARCHIVED',
        ]);
    }

    public function deleted(): static
    {
        return $this->state(fn () => [
            'action' => 'DELETED',
        ]);
    }

    /**
     * Peristiwa tanpa identitas baris, seperti impor katalog.
     *
     * @param  array<string, mixed>  $summary
     */
    public function imported(array $summary = []): static
    {
        return $this->state(fn () => [
            'action' => 'IMPORT',
            'entity_id' => null,
            'entity_key' => null,
            'after' => $summary,
        ]);
    }

    public function withReason(string $reason): static
    {
        return $this->state(fn () => [
            'reason' => $reason,
        ]);
    }

    public function at(\DateTimeInterface $moment): static
    {
        return $this->state(fn () => [
            'created_at' => $moment,
        ]);
    }
}
