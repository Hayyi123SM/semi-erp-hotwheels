<?php

namespace Database\Factories;

use App\Enums\LabelReason;
use App\Enums\LabelStatus;
use App\Models\LabelPrintJob;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabelPrintJob>
 */
class LabelPrintJobFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lot_id' => StockLot::factory(),
            'copies' => 1,
            'reason' => LabelReason::Initial,
            'template' => '3x2',
            'show_price' => true,
            'status' => LabelStatus::Queued,
            'requested_by' => User::factory()->staff(),
            'approved_by' => null,
            'printed_at' => null,
        ];
    }

    /**
     * Sudah keluar dari printer dan dikonfirmasi operator. Hanya di status ini
     * `labels_printed` boleh bertambah.
     */
    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => LabelStatus::Confirmed,
            'printed_at' => now()->subMinute(),
            'confirmed_at' => now(),
        ]);
    }

    /**
     * Sudah dikirim ke printer, belum dikonfirmasi. `labels_printed` masih 0.
     */
    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => LabelStatus::Sent,
            'printed_at' => now()->subMinute(),
        ]);
    }

    public function failed(string $message = 'Kertas habis di tengah cetak'): static
    {
        return $this->state(fn () => [
            'status' => LabelStatus::Failed,
            'printed_at' => now()->subMinute(),
            'error_message' => $message,
            'failed_at' => now(),
        ]);
    }

    public function reprint(LabelReason $reason = LabelReason::LabelDamaged): static
    {
        return $this->state(fn () => [
            'reason' => $reason,
            'status' => LabelStatus::Queued,
        ]);
    }

    public function ownerApproved(): static
    {
        return $this->state(fn () => [
            'approved_by' => User::factory()->owner(),
        ]);
    }
}
