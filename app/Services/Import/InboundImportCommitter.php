<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\Rack;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Inventory\InboundLine;
use App\Services\Inventory\InboundService;
use App\Services\Inventory\StockInPribadiDraftService;
use App\Services\Notification\NotificationSender;
use App\Support\DeviceId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menyalurkan baris impor Inbound ke tujuannya setelah divalidasi.
 *
 * Dua modul Inbound berperilaku berbeda setelah validasi, dan perbedaan itu
 * disengaja:
 *
 * - Stock In Pribadi mengisi DRAFT, bukan stok. HPP adalah keputusan Owner dan
 *   form tetap jalan terakhirnya -- impor hanya menghemat pengetikan, bukan
 *   memindahkan tombol commit ke berkas Excel.
 * - Consignment In langsung menjadi dokumen. Sekelompok baris dengan
 *   "Kode Penitip" dan tanggal yang sama menjadi satu consignment, dan seluruh
 *   kelompok ditulis dalam SATU transaksi luar: bila satu SKU gagal, tidak ada
 *   dokumen setengah jadi yang tertinggal.
 */
final class InboundImportCommitter
{
    public function __construct(
        private readonly InboundService $inbound,
        private readonly StockInPribadiDraftService $pribadiDrafts,
        private readonly NotificationSender $notifications,
        private readonly AuditLogger $audit,
    ) {}

    public function commit(string $module, ImportResult $result, User $actor, string $token): RedirectResponse
    {
        return $module === 'stock_in_pribadi'
            ? $this->commitStockInPribadi($result, $actor)
            : $this->commitConsignment($result, $actor, $token);
    }

    private function commitStockInPribadi(ImportResult $result, User $actor): RedirectResponse
    {
        $products = $this->products(array_column(array_column($result->rows, 'data'), 'product_id'));
        $items = [];

        foreach ($result->rows as $entry) {
            $data = $entry['data'];
            $product = $products[(int) $data['product_id']] ?? null;

            if ($product === null) {
                continue;
            }

            $items[] = [
                'id' => Str::uuid()->toString(),
                'product_id' => $product->id,
                'casting_code' => (string) $product->casting_code,
                'name' => (string) $product->name,
                'series_code' => (string) ($product->series?->code ?? ''),
                'qty' => (int) $data['qty'],
                'cost_price' => (int) $data['cost_price'],
                'card_condition' => (string) $data['card_condition'],
                'blister_condition' => (string) $data['blister_condition'],
                'rack_id' => $data['rack_id'] === null ? null : (int) $data['rack_id'],
            ];
        }

        $this->pribadiDrafts->save($items, DeviceId::current());

        $qty = array_sum(array_column($items, 'qty'));

        $this->audit->imported('Import Stock In Pribadi', [
            'rows' => count($items),
            'qty' => $qty,
        ]);

        return redirect()->route('inbound.stock-in-pribadi')->with('toast', [
            'type' => 'success',
            'message' => count($items).' baris ('.$qty.' unit) diisi ke form. Periksa lalu simpan sebagai stok.',
        ]);
    }

    private function commitConsignment(ImportResult $result, User $actor, string $token): RedirectResponse
    {
        // Satu dokumen = satu (penitip, tanggal masuk). Baris dengan tanggal
        // berbeda pada penitip yang sama dipisah supaya tanggal dokumennya tidak
        // menipu -- kolom `consignment_date` milik dokumen, bukan baris.
        $groups = [];
        $consignorIds = [];

        foreach ($result->rows as $entry) {
            $data = $entry['data'];
            $consignorId = (int) $data['consignor_id'];
            $date = (string) ($data['consignment_date'] ?: now()->toDateString());
            $groups[$consignorId.'|'.$date][] = $data;
            $consignorIds[$consignorId] = $consignorId;
        }

        $consignors = Consignor::query()->whereIn('id', array_values($consignorIds))->get()->keyBy('id');

        $allRows = array_merge(...array_values($groups));
        $products = $this->products(array_column($allRows, 'product_id'));
        $racks = $this->racks(array_column($allRows, 'rack_id'));

        $consignments = [];

        DB::transaction(function () use ($groups, $consignors, $products, $racks, $actor, $token, &$consignments): void {
            foreach ($groups as $key => $rows) {
                [$consignorId, $date] = explode('|', $key, 2);

                $consignor = $consignors[(int) $consignorId] ?? null;

                if ($consignor === null) {
                    continue;
                }

                $lines = array_map(
                    fn (array $data): InboundLine => $this->line($data, $products, $racks),
                    $rows,
                );

                [$consignment] = $this->inbound->receiveConsignment(
                    consignor: $consignor,
                    lines: $lines,
                    consignmentDate: $date,
                    source: 'IMPORT',
                    notes: null,
                    actor: $actor,
                    deviceId: DeviceId::current(),
                    draft: null,
                    idempotencyKey: $this->idempotencyKey($token, (int) $consignorId, $date),
                );

                $consignments[] = $consignment;
            }
        });

        $qty = 0;
        $lots = 0;

        foreach ($consignments as $consignment) {
            $qty += (int) $consignment->qty_received;
            $lots += $consignment->stockLots()->count();

            $this->audit->log('RECEIVE_CONSIGN', 'Consignment', $consignment->getKey(), [], [
                'doc_no' => $consignment->doc_no,
                'qty' => $consignment->qty_received,
                'source' => 'IMPORT',
            ]);

            // E-receipt di luar transaksi, persis alur form: WhatsApp yang gagal
            // tidak boleh membatalkan barang yang sudah masuk.
            $this->notifications->sendReceipt($consignment, $actor);
        }

        $this->audit->imported('Import Consignment In', [
            'documents' => count($consignments),
            'qty' => $qty,
        ]);

        return redirect()->route('inbound.consignment-in.riwayat')->with('toast', [
            'type' => 'success',
            'message' => count($consignments).' dokumen diterima: '.$qty.' unit, '.$lots.' SKU. Label masuk antrean cetak.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, Rack>  $racks
     */
    private function line(array $data, $products, $racks): InboundLine
    {
        $product = $products[(int) $data['product_id']];
        $rackId = $data['rack_id'] === null ? null : (int) $data['rack_id'];

        return new InboundLine(
            product: $product,
            qty: (int) $data['qty'],
            rack: $rackId === null ? null : ($racks[$rackId] ?? null),
            cardCondition: CardCondition::tryFrom((string) $data['card_condition']),
            blisterCondition: BlisterCondition::tryFrom((string) $data['blister_condition']),
            listPrice: $data['list_price'] === null ? null : (int) $data['list_price'],
        );
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return Collection<int, Product>
     */
    private function products(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === []
            ? collect()
            : Product::query()->with('series')->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * @param  array<int, int|string|null>  $ids
     * @return Collection<int, Rack>
     */
    private function racks(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === []
            ? collect()
            : Rack::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    private function idempotencyKey(string $token, int $consignorId, string $date): string
    {
        return 'import:'.substr(hash('sha256', $token.'|'.$consignorId.'|'.$date), 0, 40);
    }
}
