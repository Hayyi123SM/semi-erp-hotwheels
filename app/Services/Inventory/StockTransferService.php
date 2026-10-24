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

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RackCapacityService $rackCapacity,
    ) {}

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
            // Rak tujuan dibaca ulang dan dikunci lebih dulu, dengan urutan yang
            // sama seperti pemindahan massal: satu arah kunci untuk semua jalur.
            $target = Rack::query()->lockForUpdate()->findOrFail($rack->getKey());

            $fresh = StockLot::query()
                ->with('rack')
                ->lockForUpdate()
                ->findOrFail($lot->getKey());

            // Dibaca ulang, diperiksa ulang: di antara pemeriksaan pertama dan
            // baris ini qty bisa saja sudah habis terjual, yang membuat
            // pemindahan raknya mustahil dilakukan secara fisik.
            $this->guard($fresh, $target);
            $this->rackCapacity->assertFits($target, $fresh->qty_on_hand, 'rack_id');

            $fresh->update(['rack_id' => $target->id]);

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
                ['rack_id' => $target->id, 'rack' => $target->code],
                $reason,
            );

            return $fresh->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * Pindahkan banyak lot ke satu rak dalam satu transaksi.
     *
     * Berbeda dengan `transfer()`, keadaan yang hanya melekat pada sebagian
     * baris tidak menggagalkan sisanya: lot yang sudah berada di rak tujuan
     * atau yang stoknya kosong dilewati dan dihitung. Pemindahan massal
     * memang sering menyertakan baris semacam itu -- memilih 50 baris lalu
     * menolak 50-nya hanya karena 2 di antaranya sudah di rak tujuan akan
     * memaksa operator memilih ulang satu per satu, yang justru yang
     * ingin dihindari fitur ini.
     *
     * Yang tetap menggagalkan seluruh kejadian hanya rak tujuan sendiri
     * (tidak ditemukan / nonaktif): tanpanya tidak ada satu pun baris
     * layak dipindah, jadi melanjutkan hanya membuang gerakan stok kosong.
     *
     * Lot yang tidak muat di rak tujuan juga dilewati, bukan menggagalkan
     * seluruh pilihan; sisa ruang dihitung sekali lalu dikurangi tiap lot
     * yang benar-benar pindah, supaya urutan pilihan tidak mengubah hasil.
     *
     * @param  iterable<StockLot>  $lots  lot pilihan, kunci saja yang dipakai
     * @return array{moved: int, skipped: int, skippedNoSpace: int} pindah / dilewati (sudah di rak tujuan, kosong, atau hilang) / dilewati karena rak penuh
     *
     * @throws ValidationException
     */
    public function transferMany(iterable $lots, Rack $rack, ?User $actor = null, ?string $reason = null): array
    {
        $ids = collect($lots)
            ->map(fn (StockLot $lot): int => (int) $lot->getKey())
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return ['moved' => 0, 'skipped' => 0, 'skippedNoSpace' => 0];
        }

        if (! $rack->is_active) {
            throw ValidationException::withMessages([
                'rack_id' => 'Rak tujuan sedang nonaktif.',
            ]);
        }

        return DB::transaction(function () use ($ids, $rack, $actor, $reason): array {
            $target = Rack::query()->lockForUpdate()->findOrFail($rack->getKey());
            $remaining = $this->rackCapacity->available($target);

            $freshLots = StockLot::query()
                ->with('rack')
                ->lockForUpdate()
                ->whereKey($ids)
                ->get();

            // Lot hilang di antara validasi dan transaksi (dihapus pemakai
            // lain) tidak bisa dipindah; dilaporkan sebagai dilewati, bukan
            // sebagai kegagalan, supaya 49 sisanya tetap pindah.
            $moved = 0;
            $skipped = count($ids) - $freshLots->count();
            $skippedNoSpace = 0;

            foreach ($freshLots as $fresh) {
                if ($fresh->qty_on_hand < 1 || $fresh->rack_id === $target->id) {
                    $skipped++;

                    continue;
                }

                if ($remaining !== null && $fresh->qty_on_hand > $remaining) {
                    $skippedNoSpace++;

                    continue;
                }

                $before = [
                    'rack_id' => $fresh->rack_id,
                    'rack' => $fresh->rack?->code,
                ];

                $fresh->update(['rack_id' => $target->id]);

                if ($remaining !== null) {
                    $remaining -= $fresh->qty_on_hand;
                }

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
                    ['rack_id' => $target->id, 'rack' => $target->code],
                    $reason,
                );

                $moved++;
            }

            return ['moved' => $moved, 'skipped' => $skipped, 'skippedNoSpace' => $skippedNoSpace];
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
