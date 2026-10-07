<?php

namespace Database\Factories;

use App\Enums\QuarantineStatus;
use App\Models\QuarantineCase;
use App\Models\Rack;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuarantineCase>
 */
class QuarantineCaseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $S = 3;
        $C = 1;
        $Q = 2;

        return [
            'case_no' => 'QRT-'.fake()->unique()->numerify('YYYYMMDD-###'),
            'status' => QuarantineStatus::Open,
            'qty' => $Q,
            'photo_urls' => [],
            'attributes' => [],
            'rack_id' => Rack::factory()->quarantine(),
            'assigned_lot_id' => null,
            // V = S - (C + Q); default di factory ini sudah rekonsiliasi bersih.
            'S' => $S,
            'C' => $C,
            'Q' => $Q,
            'V' => $S - ($C + $Q),
            'counted_at' => now(),
            'decided_by' => null,
            'evidence' => [],
            'resolved_at' => null,
            'opened_by' => User::factory()->staff(),
        ];
    }

    /**
     * Hitung ulang V dari S, C, Q sehingga test tidak perlu mengingat rumusnya.
     *
     * @param  array{S: int, C: int, Q: int}  $counts
     */
    public function counted(int $S, int $C, int $Q): static
    {
        return $this->state(fn () => [
            'S' => $S,
            'C' => $C,
            'Q' => $Q,
            'V' => $S - ($C + $Q),
        ]);
    }

    /**
     * Selisih negatif: lebih banyak unit fisik dari catatan.
     * SRS: assign ditolak otomatis dan kasus dieskalasi.
     */
    public function overage(int $S = 1, int $C = 0, int $Q = 2): static
    {
        return $this->counted($S, $C, $Q)->escalated();
    }

    /**
     * Selisih positif: unit hilang, perlu adjustment_minus.
     */
    public function shortage(int $S = 5, int $C = 1, int $Q = 2): static
    {
        return $this->counted($S, $C, $Q);
    }

    public function escalated(): static
    {
        return $this->state(fn () => [
            'status' => QuarantineStatus::Escalated,
        ]);
    }

    public function waitingEvidence(): static
    {
        return $this->state(fn () => [
            'status' => QuarantineStatus::WaitingEvidence,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => QuarantineStatus::Closed,
            'resolved_at' => now(),
            'decided_by' => User::factory()->owner(),
        ]);
    }

    public function assigned(StockLot $lot): static
    {
        return $this->state(fn () => [
            'assigned_lot_id' => $lot->id,
        ]);
    }
}
