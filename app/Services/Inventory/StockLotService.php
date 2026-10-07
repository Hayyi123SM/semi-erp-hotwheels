<?php

namespace App\Services\Inventory;

use App\Enums\ConsignmentStatus;
use App\Enums\LabelReason;
use App\Enums\LabelStatus;
use App\Enums\LotStatus;
use App\Enums\MovementType;
use App\Enums\OwnerType;
use App\Models\Consignment;
use App\Models\LabelPrintJob;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Services\Label\LabelPrinterSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya pintu masuk barang ke dalam stok.
 *
 * Seluruh operasi berjalan dalam satu transaksi: alokasi nomor SKU, insert lot,
 * pencatatan pergerakan stok, penutupan consignment, dan antrean label harus
 * berhasil bersama. Bila salah satu gagal, semuanya batal termasuk kenaikan
 * last_seq. Nomor yang batal dipakai kembali itu aman karena belum pernah
 * menghasilkan SKU di tabel stock_lots, dan SRS hanya melarang pemakaian ulang
 * nomor yang sudah tercetak pada lot.
 */
class StockLotService
{
    /**
     * Percobaan ulang transaksi bila terjadi deadlock antar-kasir.
     */
    private const int TX_ATTEMPTS = 5;

    public function __construct(
        private readonly SkuService $skus,
        private readonly LabelPrinterSettings $printer,
    ) {}

    /**
     * Terima satu baris barang dan hasilkan satu lot.
     */
    public function receive(ReceiveStock $request): StockLot
    {
        return DB::transaction(function () use ($request): StockLot {
            $lot = $this->createLot($request);

            $this->recordMovement($lot, $request);
            $this->queueLabel($lot, $request);
            $this->closeConsignment($request);

            return $lot;
        }, self::TX_ATTEMPTS);
    }

    /**
     * Terima banyak baris milik satu consignment dalam satu transaksi.
     *
     * Satu transaksi untuk seluruh baris menjaga agar urutan SKU yang
     * teralokasi tidak bolong di tengah jalan bila baris terakhir gagal.
     *
     * @param  iterable<ReceiveStock>  $requests
     * @return list<StockLot>
     *
     * @throws ValidationException
     */
    public function receiveMany(iterable $requests): array
    {
        // Materialisasi sekali: iterable boleh berupa generator yang hanya bisa
        // diiterasi satu kali, sedangkan closure di bawah membutuhkannya
        // untuk validasi dan untuk pemrosesan.
        $rows = [...$requests];

        if ($rows === []) {
            throw ValidationException::withMessages([
                'items' => 'Tidak ada barang untuk diterima.',
            ]);
        }

        $consignment = $this->sharedConsignment($rows);

        return DB::transaction(function () use ($rows, $consignment): array {
            $lots = [];

            foreach ($rows as $request) {
                $lot = $this->createLot($request);

                $this->recordMovement($lot, $request);
                $this->queueLabel($lot, $request);

                $lots[] = $lot;
            }

            // Consignment ditutup sekali setelah semua lot beres.
            $this->commitConsignment($consignment, $rows[0]->actor);

            return $lots;
        }, self::TX_ATTEMPTS);
    }

    private function createLot(ReceiveStock $request): StockLot
    {
        $product = $request->product;
        $categoryCode = $request->categoryCode();
        $sequence = $this->skus->nextSequence($request->ownerCode, $categoryCode);

        return StockLot::create([
            'sku' => $this->skus->format($request->ownerCode, $categoryCode, $sequence),
            'consignment_id' => $request->consignment?->id,
            'sequence' => $sequence,
            'category_code' => $categoryCode,
            'owner_type' => $request->ownerType,
            'owner_code' => $request->ownerCode,
            'consignor_id' => $request->consignor?->id,
            'product_id' => $product->id,
            'card_condition' => ($request->cardCondition ?? $product->card_condition)->value,
            'blister_condition' => ($request->blisterCondition ?? $product->blister_condition)->value,
            'list_price' => $request->listPrice ?? $product->default_list_price,
            'cost_price' => $request->costPrice,
            'scheme_type' => $request->schemeType?->value,
            'scheme_rate' => $request->schemeRate,
            'scheme_amount' => $request->schemeAmount,
            'discount_policy' => $request->discountPolicy?->value,
            'terms_version' => $request->termsVersion,
            'negative_margin_flag' => $request->negativeMarginFlag,
            'qty_received' => $request->qty,
            'qty_on_hand' => $request->qty,
            'labels_printed' => 0,
            'rack_id' => $request->rack?->id,
            'status' => LotStatus::Available->value,
        ]);
    }

    /**
     * Setiap perubahan stok wajib dicatat pada tabel append-only
     * stock_movements, bukan dengan mengubah qty_on_hand secara langsung.
     */
    private function recordMovement(StockLot $lot, ReceiveStock $request): void
    {
        StockMovement::create([
            'lot_id' => $lot->id,
            'type' => ($request->ownerType === OwnerType::Own
                ? MovementType::InOwn
                : MovementType::InConsign)->value,
            'qty_delta' => $request->qty,
            'ref_type' => $request->consignment !== null ? 'CONSIGNMENT' : null,
            'ref_id' => $request->consignment?->id,
            'actor_id' => $request->actor?->id,
            'device_id' => $request->deviceId,
            'reason' => null,
            'balance_after' => $request->qty,
        ]);
    }

    private function queueLabel(StockLot $lot, ReceiveStock $request): void
    {
        if (! $request->queueLabel) {
            return;
        }

        LabelPrintJob::create([
            'lot_id' => $lot->id,
            'copies' => 1,
            'reason' => LabelReason::Initial->value,
            'template' => $this->printer->defaultTemplate(),
            'show_price' => true,
            'status' => LabelStatus::Queued->value,
            'requested_by' => $request->actor?->id,
        ]);
    }

    private function closeConsignment(ReceiveStock $request): void
    {
        $this->commitConsignment($request->consignment, $request->actor);
    }

    private function commitConsignment(?Consignment $consignment, mixed $actor): void
    {
        if ($consignment === null || $consignment->status === ConsignmentStatus::Committed) {
            return;
        }

        $consignment->update([
            'status' => ConsignmentStatus::Committed,
            'committed_at' => now(),
            'committed_by' => $actor?->id,
        ]);
    }

    /**
     * Pastikan seluruh baris memang milik consignment yang sama.
     *
     * @param  list<ReceiveStock>  $rows
     *
     * @throws ValidationException
     */
    private function sharedConsignment(array $rows): ?Consignment
    {
        $consignments = [];

        foreach ($rows as $request) {
            $consignments[$request->consignment?->id ?? 0] = $request->consignment;
        }

        if (count($consignments) > 1) {
            throw ValidationException::withMessages([
                'consignment_id' => 'Semua baris harus berasal dari satu consignment yang sama.',
            ]);
        }

        return reset($consignments) ?: null;
    }
}
