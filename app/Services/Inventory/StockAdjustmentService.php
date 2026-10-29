<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\AdjustmentReason;
use App\Enums\AuditAction;
use App\Enums\LotStatus;
use App\Enums\MovementType;
use App\Models\Rack;
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
 * Dua arah memakai alasan yang sama, `MISCOUNT`, karena keduanya hanya
 * membetulkan angka yang sudah tercatat -- bukan menerima barang baru dan bukan
 * mencatat barang hilang/rusak. Penambahan unit yang benar-benar baru tetap
 * lewat penerimaan barang, sedangkan unit temuan dan selisih fisik diputuskan
 * lewat opname.
 *
 * Pemeriksaan dilakukan di dalam transaksi terhadap baris yang dibaca ulang
 * dengan kunci. `lockForUpdate()` tidak berfungsi di SQLite yang dipakai suite
 * pengujian, jadi hasil baca ulang divalidasi ulang -- bukan dipercaya --
 * supaya dua kiriman bersamaan tidak sama-sama mengubah stok yang sama.
 */
class StockAdjustmentService
{
    /**
     * Percobaan ulang transaksi bila terjadi deadlock antar-operasi stok.
     */
    private const int TX_ATTEMPTS = 5;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RackCapacityService $rackCapacity,
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

            $this->guardExpectedQty($fresh, $expectedQty);
            $this->guardQty($qty);
            $this->guardReduction($fresh, $qty);

            $before = $this->snapshot($fresh);
            $newQty = $fresh->qty_on_hand - $qty;
            $newStatus = $newQty === 0 ? LotStatus::SoldOut : $fresh->status;

            return $this->persist($fresh, $newQty, $newStatus, -$qty, $actor, $before);
        }, self::TX_ATTEMPTS);
    }

    /**
     * Tambah stok satu lot dengan alasan salah input/salah hitung.
     *
     * Lot `SOLD_OUT` yang kembali berisi dipulihkan ke `AVAILABLE`. Kapasitas
     * rak diperiksa sebelum stok berubah karena unit tambahan menempati ruang
     * fisik yang sama seperti penerimaan barang.
     *
     * @param  int  $qty  jumlah unit yang ditambahkan, minimal 1
     * @param  int  $expectedQty  stok yang dilihat pengguna saat dialog dibuka
     *
     * @throws ValidationException
     */
    public function increase(StockLot $lot, int $qty, int $expectedQty, ?User $actor = null): StockLot
    {
        return DB::transaction(function () use ($lot, $qty, $expectedQty, $actor): StockLot {
            $fresh = StockLot::query()->lockForUpdate()->findOrFail($lot->getKey());

            $this->guardExpectedQty($fresh, $expectedQty);
            $this->guardQty($qty);
            $this->guardAddition($fresh, $qty);
            $this->guardRackCapacity($fresh, $qty);

            $before = $this->snapshot($fresh);
            $newQty = $fresh->qty_on_hand + $qty;
            $newStatus = $fresh->status === LotStatus::SoldOut ? LotStatus::Available : $fresh->status;

            return $this->persist($fresh, $newQty, $newStatus, $qty, $actor, $before);
        }, self::TX_ATTEMPTS);
    }

    /**
     * @throws ValidationException
     */
    private function guardExpectedQty(StockLot $lot, int $expectedQty): void
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
    }

    /**
     * @throws ValidationException
     */
    private function guardQty(int $qty): void
    {
        if ($qty < 1) {
            throw ValidationException::withMessages([
                'qty' => 'Jumlah koreksi minimal 1 unit.',
            ]);
        }

        if ($qty > OpnameService::MAX_COUNTED_QTY) {
            throw ValidationException::withMessages([
                'qty' => 'Jumlah koreksi terlalu besar; periksa lagi.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function guardReduction(StockLot $lot, int $qty): void
    {
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

        if ($qty > $lot->qty_on_hand) {
            throw ValidationException::withMessages([
                'qty' => sprintf(
                    'Jumlah yang dikurangi melebihi stok yang ada (%d unit).',
                    $lot->qty_on_hand,
                ),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function guardAddition(StockLot $lot, int $qty): void
    {
        if (! in_array($lot->status, [LotStatus::Available, LotStatus::SoldOut], true)) {
            throw ValidationException::withMessages([
                'qty' => sprintf(
                    'Lot %s berstatus %s; hanya lot tersedia atau habis yang bisa ditambah.',
                    $lot->sku,
                    Format::statusLabel($lot->status),
                ),
            ]);
        }

        if ($lot->qty_on_hand + $qty > OpnameService::MAX_COUNTED_QTY) {
            throw ValidationException::withMessages([
                'qty' => 'Stok setelah ditambah melebihi batas '.OpnameService::MAX_COUNTED_QTY.' unit.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function guardRackCapacity(StockLot $lot, int $qty): void
    {
        if ($lot->rack_id === null) {
            return;
        }

        $rack = Rack::query()->lockForUpdate()->find($lot->rack_id);

        if ($rack !== null) {
            $this->rackCapacity->assertFits($rack, $qty, 'qty');
        }
    }

    /**
     * @param  array{qty_on_hand: int, status: string}  $before
     */
    private function persist(
        StockLot $lot,
        int $newQty,
        LotStatus $newStatus,
        int $delta,
        ?User $actor,
        array $before,
    ): StockLot {
        $reason = AdjustmentReason::Miscount;

        $lot->update([
            'qty_on_hand' => $newQty,
            'status' => $newStatus,
        ]);

        StockMovement::create([
            'lot_id' => $lot->id,
            'type' => $delta > 0 ? MovementType::AdjPlus : MovementType::AdjMinus,
            'qty_delta' => $delta,
            'ref_type' => StockLot::class,
            'ref_id' => $lot->id,
            'actor_id' => $actor?->id,
            'device_id' => DeviceId::current(),
            'reason' => $reason->value,
            'balance_after' => $newQty,
        ]);

        $this->audit->log(
            AuditAction::StockAdjustment->value,
            StockLot::class,
            (int) $lot->id,
            $before,
            [
                'qty_on_hand' => $newQty,
                'status' => $newStatus->value,
            ],
            $reason->value,
        );

        return $lot->refresh();
    }

    /**
     * @return array{qty_on_hand: int, status: string}
     */
    private function snapshot(StockLot $lot): array
    {
        return [
            'qty_on_hand' => $lot->qty_on_hand,
            'status' => $lot->status->value,
        ];
    }
}
