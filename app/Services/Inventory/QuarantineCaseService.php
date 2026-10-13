<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\CardCondition;
use App\Enums\QuarantineReason;
use App\Enums\QuarantineStatus;
use App\Models\QuarantineCase;
use App\Models\Rack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pendaftaran kasus karantina (BR-10).
 *
 * Registrasi tidak menyentuh stok: unit yang masuk belum teridentifikasi, jadi
 * tidak ada lot yang berkurang. Gerakan `QUARANTINE_IN` semestinya informasional,
 * tetapi `stock_movements.lot_id` tidak boleh null (foreign key NOT NULL), jadi
 * gerakan itu sengaja tidak dicatat -- kasus itu sendiri adalah buktinya.
 */
class QuarantineCaseService
{
    public function __construct(private readonly QuarantineCaseSequencer $sequencer) {}

    /**
     * Buka kasus karantina baru, lengkap dengan nomor unik `QC-YYYYMMDD-NNN`.
     */
    public function register(
        int $qty,
        Rack $rack,
        string $keyword,
        ?string $series,
        ?string $color,
        ?CardCondition $condition,
        ?QuarantineReason $reason,
        User $actor,
    ): QuarantineCase {
        $attributes = array_filter([
            'model' => $keyword,
            'series' => $series,
            'color' => $color,
            'condition' => $condition?->value,
            'reason' => $reason?->value,
        ], fn (mixed $value): bool => $value !== null);

        return DB::transaction(function () use ($qty, $rack, $attributes, $actor): QuarantineCase {
            return QuarantineCase::create([
                'case_no' => $this->sequencer->next(),
                'status' => QuarantineStatus::Open,
                'qty' => $qty,
                'attributes' => $attributes,
                'rack_id' => $rack->id,
                'opened_by' => $actor->id,
            ]);
        });
    }
}
