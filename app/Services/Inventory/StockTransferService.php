<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\AuditAction;
use App\Enums\MovementType;
use App\Models\Rack;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\DeviceId;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pindah rak (FR-IC-04).
 *
 * Satu-satunya hal yang berubah adalah lokasi: `stock_lots.rack_id` berganti,
 * `qty_on_hand` tidak, dan karena itu gerakan yang dicatat memakai
 * `MovementType::Transfer` dengan `qty_delta` nol. Gerakan itu tetap wajib --
 * tanpanya kartu stok kehilangan kapan barang berpindah, dan "kenapa saldo
 * tidak berubah sementara riwayat bilang ada kejadian" tidak bisa dijawab.
 *
 * Pemeriksaan dilakukan dua kali: sekali sebelum transaksi supaya galat yang
 * paling umum (rak tujuan nonaktif, atau barang memang sudah di rak itu)
 * keluar tanpa perlu membuka transaksi, dan sekali lagi di dalam transaksi
 * terhadap baris yang dibaca ulang dengan kunci. `lockForUpdate()` tidak
 * berfungsi di SQLite yang dipakai suite pengujian, jadi baris kedua inilah
 * yang benar-benar menjaga: hasil baca ulang divalidasi ulang, bukan dipercaya.
 */
class StockTransferService
{
    /**
     * Percobaan ulang transaksi bila terjadi deadlock antar-kasir.
     */
    private const int TX_ATTEMPTS = 5;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Pindahkan satu lot ke rak lain.
     *
     * @param  string|null  $reason  alasan pemindahan, dicatat ke audit bila ada
     *
     * @throws ValidationException
     */
    public function transfer(StockLot $lot, Rack $rack, ?User $actor = null, ?string $reason = null): StockLot
    {
        $before = [
            'rack_id' => $lot->rack_id,
            'rack' => $lot->rack?->code,
        ];

        $this->guard($lot, $rack);

        return DB::transaction(function () use ($lot, $rack, $actor, $reason, $before): StockLot {
            $fresh = StockLot::query()
                ->with('rack')
                ->lockForUpdate()
                ->findOrFail($lot->getKey());

            // Dibaca ulang, diperiksa ulang: di antara pemeriksaan pertama dan
            // baris ini qty bisa saja sudah habis terjual, yang membuat
            // pemindahan raknya mustahil dilakukan secara fisik.
            $this->guard($fresh, $rack);

            $fresh->update(['rack_id' => $rack->id]);

            StockMovement::create([
                'lot_id' => $fresh->id,
                'type' => MovementType::Transfer,
                'qty_delta' => 0,
                'ref_type' => StockLot::class,
                'ref_id' => $fresh->id,
                'actor_id' => $actor?->id,
                'device_id' => DeviceId::current(),
                'reason' => $reason,
                'balance_after' => $fresh->qty_on_hand,
            ]);

            $this->audit->log(
                AuditAction::TransferRack->value,
                StockLot::class,
                (int) $fresh->id,
                $before,
                ['rack_id' => $rack->id, 'rack' => $rack->code],
                $reason,
            );

            return $fresh->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * @throws ValidationException
     */
    private function guard(StockLot $lot, Rack $rack): void
    {
        if ($lot->qty_on_hand < 1) {
            throw ValidationException::withMessages([
                'rack_id' => 'Tidak ada unit yang bisa dipindahkan: stok lot ini kosong.',
            ]);
        }

        if ($lot->rack_id === $rack->id) {
            throw ValidationException::withMessages([
                'rack_id' => 'Barang sudah berada di rak tersebut.',
            ]);
        }

        if (! $rack->is_active) {
            throw ValidationException::withMessages([
                'rack_id' => 'Rak tujuan sedang nonaktif.',
            ]);
        }
    }
}
