<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\ConsignmentStatus;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orkestrasi penerimaan barang dari sisi Controller.
 *
 * Controller tetap ramping: ia cukup memberikan parameter mentah dari request
 * yang sudah divalidasi, service mengubahnya menjadi baris ReceiveStock, lalu
 * menyerahkan pemrosesan atomik kepada StockLotService.
 *
 * Satu transaksi membungkus pembuatan dokumen plus seluruh lot-nya. Bila satu
 * SKU gagal di tengah, dokumen ikut batal (tidak ada consignment DRAFT yatim).
 */
final class InboundService
{
    public function __construct(
        private readonly StockLotService $stockLots,
        private readonly ConsignmentNoService $docNos,
    ) {}

    /**
     * Terima barang titipan: buat dokumen lalu seluruh lot sekaligus.
     *
     * Dua jalan pintas idempoten ada di sini, dan keduanya mengembalikan dokumen
     * yang sudah jadi tanpa membuat lot kedua:
     *
     * - `$draft` yang sudah `COMMITTED` (mis. Staff menekan commit dua kali, atau
     *   response sebelumnya hilang di tengah jalan). Draft adalah jaring pengaman
     *   crash, jadi ia juga menjadi bukti bahwa commit-nya sudah terjadi.
     * - `$idempotencyKey` yang sudah menempel pada dokumen lain. Ini menutup kasus
     *   yang tidak bisa ditangani draft: form yang di-submit tanpa draft sama
     *   sekali, jadi tidak ada identitas lain untuk dicocokkan.
     *
     * Keduanya dicek sebelum transaksi dibuka, karena Setelah `stock_lots` mulai
     * diisi tidak ada lagi jalan keluar yang murah.
     *
     * @param  list<InboundLine>  $lines
     * @return array{0: Consignment, 1: list<StockLot>}
     */
    public function receiveConsignment(
        Consignor $consignor,
        array $lines,
        string $consignmentDate,
        ?string $source = null,
        ?string $notes = null,
        ?User $actor = null,
        ?string $deviceId = null,
        ?Consignment $draft = null,
        ?string $idempotencyKey = null,
        ?int $qtyClaimed = null,
        ?string $varianceNote = null,
    ): array {
        $this->guardNotEmpty($lines);

        if ($replay = $this->replayIfAlreadyCommitted($draft, $idempotencyKey)) {
            return $replay;
        }

        try {
            return $this->commitWithinTransaction(
                $consignor,
                $lines,
                $consignmentDate,
                $source,
                $notes,
                $actor,
                $deviceId,
                $draft,
                $idempotencyKey,
                $qtyClaimed,
                $varianceNote,
            );
        } catch (QueryException $e) {
            // Dua request yang benar-benar bersamaan bisa sama-sama lolos
            // pengecekan di atas -- keduanya belum punya dokumen dengan kunci
            // itu. Constraint UNIQUE lalu menolak yang belakangan, dan tanpa
            // penanganan ini Staff melihat 500 padahal commit-nya sudah berhasil
            // dan barangnya sudah masuk. Jadi kegagalan ini dibaca sebagai
            // "orang lain menang", bukan sebagai error.
            $winner = $this->replayIfAlreadyCommitted($draft, $idempotencyKey);

            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }
    }

    /**
     * @param  list<InboundLine>  $lines
     * @return array{0: Consignment, 1: list<StockLot>}
     */
    private function commitWithinTransaction(
        Consignor $consignor,
        array $lines,
        string $consignmentDate,
        ?string $source,
        ?string $notes,
        ?User $actor,
        ?string $deviceId,
        ?Consignment $draft,
        ?string $idempotencyKey,
        ?int $qtyClaimed,
        ?string $varianceNote,
    ): array {
        return DB::transaction(function () use ($consignor, $lines, $consignmentDate, $source, $notes, $actor, $deviceId, $draft, $idempotencyKey, $qtyClaimed, $varianceNote): array {
            $total = array_sum(array_map(fn (InboundLine $line) => $line->qty, $lines));

            $consignment = $this->claimConsignmentRow(
                $draft,
                [
                    'doc_no' => $this->docNos->next(),
                    'consignor_id' => $consignor->id,
                    'source' => $source,
                    'consignment_date' => $consignmentDate,
                    'notes' => $notes,
                    'qty_claimed' => $qtyClaimed ?? $total,
                    'qty_received' => $total,
                    'variance_note' => $varianceNote,
                    'idempotency_key' => $idempotencyKey,
                    'status' => ConsignmentStatus::Draft->value,
                    'created_by' => $actor?->id,
                ],
            );

            $requests = array_map(
                fn (InboundLine $line) => ReceiveStock::forConsignment(
                    $consignment,
                    $consignor,
                    $line->product,
                    $line->qty,
                    rack: $line->rack,
                    cardCondition: $line->cardCondition,
                    blisterCondition: $line->blisterCondition,
                    listPrice: $line->listPrice,
                    schemeType: $line->schemeType,
                    schemeRate: $line->schemeRate,
                    schemeAmount: $line->schemeAmount,
                    discountPolicy: $line->discountPolicy,
                    actor: $actor,
                    deviceId: $deviceId,
                ),
                $lines,
            );

            $lots = $this->stockLots->receiveMany($requests);

            // Baris draft sudah tidak punya purpose setelah commit: isinya identik
            // dengan `stock_lots` yang baru dibuat, jadi membiarkannya hanya
            // menyisakan dua sumber kebenaran untuk baris yang sama.
            $consignment->items()->delete();

            return [$consignment->refresh(), $lots];
        });
    }

    /**
     * Baris dokumen yang akan dipakai commit ini.
     *
     * Draft yang ada dipakai ulang, bukan dibuat dokumen baru: barisnya sudah
     * punya `draft_id` yang jadi acuan Staff selama mengisi, dan membuat baris
     * baru berarti `CI-...` yang dipakai Staff selama 20 menit terakhir
     * tidak ada di mana pun. `doc_no` sementara `DRFT-...` diganti dengan
     * nomor dokumen yang sebenarnya di sini, satu-satunya tempat nomor itu
     * dialokasikan.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function claimConsignmentRow(?Consignment $draft, array $attributes): Consignment
    {
        if ($draft === null) {
            return Consignment::create($attributes);
        }

        $draft->fill($attributes)->save();

        return $draft;
    }

    /**
     * @return array{0: Consignment, 1: list<StockLot>}|null
     */
    private function replayIfAlreadyCommitted(?Consignment $draft, ?string $idempotencyKey): ?array
    {
        $committed = null;

        if ($draft !== null && in_array($draft->status, [ConsignmentStatus::Committed, ConsignmentStatus::Completed], true)) {
            $committed = $draft;
        }

        if ($committed === null && $idempotencyKey !== null) {
            $committed = Consignment::query()
                ->where('idempotency_key', $idempotencyKey)
                ->whereIn('status', [ConsignmentStatus::Committed, ConsignmentStatus::Completed])
                ->first();
        }

        if ($committed === null) {
            return null;
        }

        return [$committed, $committed->stockLots()->get()->all()];
    }

    /**
     * Terima stok milik toko sendiri (Stock In Pribadi, OW00).
     *
     * @param  list<InboundLine>  $lines
     * @return list<StockLot>
     */
    public function receiveOwnStock(
        array $lines,
        ?string $source = null,
        ?string $notes = null,
        ?User $actor = null,
        ?string $deviceId = null,
    ): array {
        $this->guardNotEmpty($lines);

        return DB::transaction(function () use ($lines, $actor, $deviceId): array {
            $requests = array_map(
                function (InboundLine $line) use ($actor, $deviceId): ReceiveStock {
                    if ($line->costPrice === null) {
                        throw ValidationException::withMessages([
                            'items.*.cost_price' => 'HPP wajib diisi untuk stok pribadi.',
                        ]);
                    }

                    return ReceiveStock::forOwnStock(
                        product: $line->product,
                        qty: $line->qty,
                        costPrice: $line->costPrice,
                        rack: $line->rack,
                        cardCondition: $line->cardCondition,
                        blisterCondition: $line->blisterCondition,
                        actor: $actor,
                        deviceId: $deviceId,
                    );
                },
                $lines,
            );

            return $this->stockLots->receiveMany($requests);
        });
    }

    /**
     * @param  list<InboundLine>  $lines
     *
     * @throws ValidationException
     */
    private function guardNotEmpty(array $lines): void
    {
        if ($lines !== []) {
            return;
        }

        throw ValidationException::withMessages([
            'items' => 'Tidak ada barang yang diterima.',
        ]);
    }
}
