<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\LotStatus;
use App\Enums\MovementType;
use App\Enums\QuarantineStatus;
use App\Models\QuarantineCase;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\DeviceId;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penyelesaian kasus karantina: assign, eskalasi, write-off (BR-11..13).
 *
 * Rumus intinya adalah rekonsiliasi stok pada saat assign:
 *
 *     S = qty_on_hand lot tercatat
 *     C = jumlah fisik berlabel yang benar-benar terhitung
 *     Q = jumlah unit karantina yang di-assign ke lot ini
 *     V = S - (C + Q)
 *
 * V = 0 artinya pemisahan bersih: angka tercatat menyisakan persis jumlah fisik
 * berlabel plus yang tertahan di karantina. Assign hanya dibolehkan pada V = 0.
 */
class QuarantineResolutionService
{
    /**
     * Tautkan kasus ke satu kandidat lot dan tutup kasusnya.
     *
     * @throws ValidationException
     */
    public function assign(
        QuarantineCase $case,
        StockLot $lot,
        int $countedC,
        bool $confirmAmbiguous,
        ?User $actor = null,
        ?string $note = null,
    ): QuarantineCase {
        $this->assertOpen($case);

        if ($countedC < 0) {
            throw ValidationException::withMessages([
                'counted_c' => 'Jumlah fisik berlabel tidak boleh negatif.',
            ]);
        }

        if ($this->isAmbiguous($lot) && ! $confirmAmbiguous) {
            throw ValidationException::withMessages([
                'confirm' => 'Kandidat ini ambigu: ada lot lain produk & kondisi yang sama dari pemilik berbeda. Centang konfirmasi untuk melanjutkan.',
            ]);
        }

        $S = $lot->qty_on_hand;
        $Q = $case->qty;
        $V = $S - ($countedC + $Q);

        $this->assertCleanVariance($S, $countedC, $Q, $V);

        return DB::transaction(function () use ($case, $lot, $countedC, $Q, $actor, $note): QuarantineCase {
            $freshCase = QuarantineCase::query()->lockForUpdate()->findOrFail($case->getKey());
            $this->assertOpen($freshCase);

            // Lot dibaca ulang di dalam transaksi: qty bisa berubah di antara
            // pemeriksaan pertama dan baris ini, lalu V dihitung ulang dari
            // angka yang benar-benar dipakai.
            $freshLot = StockLot::query()->lockForUpdate()->findOrFail($lot->getKey());
            $S = $freshLot->qty_on_hand;
            $V = $S - ($countedC + $Q);
            $this->assertCleanVariance($S, $countedC, $Q, $V);

            $freshCase->update([
                'status' => QuarantineStatus::Closed,
                'assigned_lot_id' => $freshLot->id,
                'S' => $S,
                'C' => $countedC,
                'Q' => $Q,
                'V' => $V,
                'counted_at' => now(),
                'decided_by' => $actor?->id,
                'resolved_at' => now(),
                'evidence' => $this->appendEvidence($freshCase, 'ASSIGNED', $actor, $note,
                    'lot_id:'.$freshLot->id.' sku:'.$freshLot->sku.' S:'.$S.' C:'.$countedC.' Q:'.$Q.' V:'.$V),
            ]);

            StockMovement::create([
                'lot_id' => $freshLot->id,
                'type' => MovementType::QuarantineOut,
                'qty_delta' => 0,
                'ref_type' => QuarantineCase::class,
                'ref_id' => $freshCase->getKey(),
                'actor_id' => $actor?->id,
                'device_id' => DeviceId::current(),
                'reason' => 'Kasus '.$freshCase->case_no.' diselesaikan: unit di-identifikasi kembali ke lot ini.',
                'balance_after' => $freshLot->qty_on_hand,
            ]);

            return $freshCase->refresh();
        });
    }

    /**
     * Naikkan kasus ke status ESCALATED untuk keputusan Owner (BR-12).
     *
     * @throws ValidationException
     */
    public function escalate(QuarantineCase $case, ?User $actor = null, ?string $note = null): QuarantineCase
    {
        $this->assertOpen($case);

        if ($case->status === QuarantineStatus::Escalated) {
            throw ValidationException::withMessages([
                'note' => 'Kasus ini sudah dieskalasi.',
            ]);
        }

        return DB::transaction(function () use ($case, $actor, $note): QuarantineCase {
            $fresh = QuarantineCase::query()->lockForUpdate()->findOrFail($case->getKey());
            $this->assertOpen($fresh);

            if ($fresh->status === QuarantineStatus::Escalated) {
                throw ValidationException::withMessages([
                    'note' => 'Kasus ini sudah dieskalasi.',
                ]);
            }

            $fresh->update([
                'status' => QuarantineStatus::Escalated,
                'decided_by' => $actor?->id,
                'evidence' => $this->appendEvidence($fresh, 'ESCALATED', $actor, $note),
            ]);

            return $fresh->refresh();
        });
    }

    /**
     * Tulis-hapus unit tak teridentifikasi: kasus ditutup, tidak ada lot yang
     * dikenal sehingga tidak ada gerakan stok.
     *
     * @throws ValidationException
     */
    public function writeOff(QuarantineCase $case, ?User $actor = null, ?string $note = null): QuarantineCase
    {
        $this->assertOpen($case);

        return DB::transaction(function () use ($case, $actor, $note): QuarantineCase {
            $fresh = QuarantineCase::query()->lockForUpdate()->findOrFail($case->getKey());
            $this->assertOpen($fresh);

            $fresh->update([
                'status' => QuarantineStatus::Closed,
                'decided_by' => $actor?->id,
                'resolved_at' => now(),
                'evidence' => $this->appendEvidence($fresh, 'WRITE_OFF', $actor, $note),
            ]);

            return $fresh->refresh();
        });
    }

    /**
     * Rekonsiliasi stok pada saat assign (BR-13): V musti tepat nol.
     *
     * @throws ValidationException
     */
    private function assertCleanVariance(int $S, int $C, int $Q, int $V): void
    {
        if ($V > 0) {
            throw ValidationException::withMessages([
                'counted_c' => sprintf(
                    'Selisih positif: S (%d) - (C %d + Q %d) = %d. Stok tercatat melebihi fisik; periksa ulang hitung, atau koreksi (ADJ_MINUS) dulu.',
                    $S,
                    $C,
                    $Q,
                    $V,
                ),
            ]);
        }

        if ($V < 0) {
            throw ValidationException::withMessages([
                'counted_c' => sprintf(
                    'Selisih negatif: S (%d) - (C %d + Q %d) = %d. Ada %d unit fisik tanpa unit tercatat; tidak bisa di-assign.',
                    $S,
                    $C,
                    $Q,
                    $V,
                    -$V,
                ),
            ]);
        }
    }

    /**
     * Apakah ada lot lain AVAILABLE dengan produk & kondisi yang sama milik
     * pemilik berbeda? Bila ya, identifikasi pemilik tidak bisa dipastikan
     * tanpa konfirmasi (BR-11).
     */
    private function isAmbiguous(StockLot $lot): bool
    {
        $siblings = StockLot::query()
            ->where('product_id', $lot->product_id)
            ->where('status', LotStatus::Available->value)
            ->where('qty_on_hand', '>', 0)
            ->whereKeyNot($lot->getKey())
            ->get(['id', 'owner_type', 'consignor_id', 'card_condition']);

        return $siblings->contains(fn (StockLot $other): bool => $other->card_condition === $lot->card_condition
            && ($other->owner_type !== $lot->owner_type || $other->consignor_id !== $lot->consignor_id));
    }

    /**
     * @param  list<array<string, mixed>>|null  $existing
     * @return list<array<string, mixed>>
     */
    private function appendEvidence(
        QuarantineCase $case,
        string $action,
        ?User $actor,
        ?string $note,
        ?string $detail = null,
    ): array {
        $entry = [
            'at' => now()->toIso8601String(),
            'by' => $actor?->name,
            'action' => $action,
        ];

        if ($detail !== null) {
            $entry['detail'] = $detail;
        }

        if ($note !== null && $note !== '') {
            $entry['note'] = $note;
        }

        return [...($case->evidence ?? []), $entry];
    }

    /**
     * @throws ValidationException
     */
    private function assertOpen(QuarantineCase $case): void
    {
        if (! $case->status->isOpen()) {
            throw ValidationException::withMessages([
                'case' => 'Kasus ini sudah ditutup.',
            ]);
        }
    }
}
