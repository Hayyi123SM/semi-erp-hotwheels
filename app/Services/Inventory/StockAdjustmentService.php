<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\AdjustmentReason;
use App\Enums\AuditAction;
use App\Enums\LotStatus;
use App\Enums\MovementType;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\DeviceId;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Koreksi manual stok karena salah input/salah hitung (FR-IC-25).
 *
 * Jalur ini sengaja hanya bisa mengurangi. Penambahan manual tetap lewat
 * penerimaan barang supaya asal-usul unitnya tercatat, sedangkan pengurangan
 * karena barang hilang/rusak adalah kasus berbeda: yang itu menyentuh hak
 * penitip dan harus lewat opname atau write-off.
 *
 * Pemeriksaan dilakukan di dalam transaksi terhadap baris yang dibaca ulang
 * dengan kunci. `lockForUpdate()` tidak berfungsi di SQLite yang dipakai suite
 * pengujian, jadi hasil baca ulang divalidasi ulang -- bukan dipercaya --
 * supaya dua kiriman bersamaan tidak sama-sama mengurangi stok yang sama.
 */
class StockAdjustmentService
{
    /**
     * Percobaan ulang transaksi bila terjadi deadlock antar-operasi stok.
     */
    private const int TX_ATTEMPTS = 5;

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Kurangi stok satu lot dengan alasan salah input/salah hitung.
     *
     * @param  int  $qty  jumlah unit yang dikurangi, minimal 1
     * @param  int  $expectedQty  stok yang dilihat pengguna saat dialog dibuka
     *
     * @throws ValidationException
     */
    public function reduce(StockLot $lot, int $qty, int $expectedQty, ?User $actor = null): StockLot
    {
        return DB::transaction(function () use ($lot, $qty, $expectedQty, $actor): StockLot {
            $fresh = StockLot::query()->lockForUpdate()->findOrFail($lot->getKey());

            $this->guard($fresh, $qty, $expectedQty);

            $reason = AdjustmentReason::Miscount;
            $before = [
                'qty_on_hand' => $fresh->qty_on_hand,
                'status' => $fresh->status->value,
            ];

            $newQty = $fresh->qty_on_hand - $qty;
            $newStatus = $newQty === 0 ? LotStatus::SoldOut : $fresh->status;

            $fresh->update([
                'qty_on_hand' => $newQty,
                'status' => $newStatus,
            ]);

            StockMovement::create([
                'lot_id' => $fresh->id,
                'type' => MovementType::AdjMinus,
                'qty_delta' => -$qty,
                'ref_type' => StockLot::class,
                'ref_id' => $fresh->id,
                'actor_id' => $actor?->id,
                'device_id' => DeviceId::current(),
                'reason' => $reason->value,
                'balance_after' => $newQty,
            ]);

            $this->audit->log(
                AuditAction::StockAdjustment->value,
                StockLot::class,
                (int) $fresh->id,
                $before,
                [
                    'qty_on_hand' => $newQty,
                    'status' => $newStatus->value,
                ],
                $reason->value,
            );

            return $fresh->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * @throws ValidationException
     */
    private function guard(StockLot $lot, int $qty, int $expectedQty): void
    {
        if ($expectedQty !== $lot->qty_on_hand) {
            throw ValidationException::withMessages([
                'qty' => sprintf(
                    'Stok %s sudah berubah menjadi %d unit. Muat ulang halaman lalu ulangi koreksi.',
                    $lot->sku,
                    $lot->qty_on_hand,
                ),
            ]);
        }

        if ($lot->status !== LotStatus::Available) {
            throw ValidationException::withMessages([
                'qty' => sprintf(
                    'Lot %s berstatus %s; hanya lot tersedia yang bisa dikurangi.',
                    $lot->sku,
                    Format::statusLabel($lot->status),
                ),
            ]);
        }

        if ($lot->qty_on_hand < 1) {
            throw ValidationException::withMessages([
                'qty' => 'Stok lot ini kosong; tidak ada unit yang bisa dikurangi.',
            ]);
        }

        if ($qty < 1) {
            throw ValidationException::withMessages([
                'qty' => 'Jumlah yang dikurangi minimal 1 unit.',
            ]);
        }

        if ($qty > $lot->qty_on_hand) {
            throw ValidationException::withMessages([
                'qty' => sprintf(
                    'Jumlah yang dikurangi melebihi stok yang ada (%d unit).',
                    $lot->qty_on_hand,
                ),
            ]);
        }
    }
}
