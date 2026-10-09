<?php

namespace Database\Seeders;

use App\Enums\LedgerType;
use App\Enums\MovementType;
use App\Enums\OwnerType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Enums\ShiftStatus;
use App\Models\ConsignorLedger;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WaTemplate;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $cashier = User::where('username', 'ahmad.fauzi')->firstOrFail();

        $this->seedSettings();
        $this->seedWaTemplates();

        $shift = Shift::create([
            'device_id' => 'WMS-01',
            'user_id' => $cashier->id,
            'opened_at' => now()->subHours(6)->startOfDay()->addHours(9),
            'opening_cash' => 500000,
            'status' => ShiftStatus::Open,
        ]);

        $saleRows = [
            [
                'receipt' => 'POS-CP2-2026-08911',
                'sku' => 'CN01-HW-001',
                'soldAt' => '2026-09-22 16:03:00',
                'method' => PaymentMethod::Cash,
            ],
            [
                'receipt' => 'POS-CP2-2026-08912',
                'sku' => 'CN02-HW-001',
                'soldAt' => '2026-09-22 18:17:00',
                'method' => PaymentMethod::Qris,
            ],
            [
                'receipt' => 'POS-CP2-2026-08913',
                'sku' => 'OW00-HW-001',
                'soldAt' => '2026-09-22 19:42:00',
                'method' => PaymentMethod::Cash,
            ],
        ];

        foreach ($saleRows as $row) {
            $this->makeSale($shift, $cashier, $row);
        }
    }

    private function seedSettings(): void
    {
        $settings = [
            'store.name' => '167 Diecast Shop',
            'store.address' => 'Jl. Mainan No. 1, Kota',
            'receipt.footer' => 'Terima kasih telah berbelanja!',
            'pos.label_templates' => ['3x2' => 'minimalis-solid', '4x3' => 'luas-info'],
            'timezone' => 'Asia/Jakarta',
        ];

        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'description' => 'Seeder default']
            );
        }
    }

    private function seedWaTemplates(): void
    {
        $templates = [
            [
                'name' => 'Nota Penitipan Masuk',
                'category' => 'CONSIGNMENT_RECEIPT',
                'body' => "Halo {{penitip}}, barang titipan Anda sudah kami terima.\nNo. Nota: {{no_nota}}\nJumlah: {{jumlah}} unit\nTotal Penjualan Dasar: Rp{{total}}",
                'variables' => ['penitip', 'no_nota', 'jumlah', 'total'],
            ],
            [
                'name' => 'Nota Jual (Struk)',
                'category' => 'RECEIPT',
                'body' => 'Struk Penjualan {{no_nota}} - Rp{{total}}',
                'variables' => ['no_nota', 'total'],
            ],
            [
                'name' => 'Laporan Saldo Penitip',
                'category' => 'STATEMENT',
                'body' => 'Halo {{penitip}}, saldo Anda saat ini Rp{{saldo}}.',
                'variables' => ['penitip', 'saldo'],
            ],
            [
                'name' => 'Notifikasi Barang Rusak/RTV',
                'category' => 'RTV',
                'body' => 'Halo {{penitip}}, barang Anda {{sku}} mengalami {{kondisi}}.',
                'variables' => ['penitip', 'sku', 'kondisi'],
            ],
        ];

        foreach ($templates as $tpl) {
            WaTemplate::updateOrCreate(['name' => $tpl['name']], $tpl);
        }
    }

    private function makeSale(Shift $shift, User $cashier, array $row): void
    {
        $lot = StockLot::where('sku', $row['sku'])->firstOrFail();

        $fee = match ($lot->scheme_type?->value) {
            'PERCENTAGE' => (int) round($lot->list_price * ($lot->scheme_rate ?? 0) / 100),
            'NETT' => (int) ($lot->scheme_amount ?? 0),
            'FLAT' => max(0, $lot->list_price - (int) ($lot->scheme_amount ?? 0)),
            default => $lot->list_price,
        };

        $consign = $lot->owner_type === OwnerType::Consign;
        $hak = $consign ? max(0, $lot->list_price - $fee) : 0;

        $sale = Sale::create([
            'client_sale_id' => $row['receipt'].'-S',
            'receipt_no' => $row['receipt'],
            'shift_id' => $shift->id,
            'device_id' => 'WMS-01',
            'user_id' => $cashier->id,
            'sold_at_client' => $row['soldAt'],
            'sold_at' => $row['soldAt'],
            'subtotal' => $lot->list_price,
            'discount_total' => 0,
            'total' => $lot->list_price,
            'status' => SaleStatus::Paid,
            // Dibuat sebagai penjualan yang sudah sampai ke server, persis seperti
            // keluaran `CheckoutService`. Mengosongkannya membuat ketiga nota demo
            // ini masuk daftar "Belum sinkron" -- daftar antrean perangkat offline --
            // sehingga filternya tidak bisa dipercaya bahkan oleh data contoh.
            'synced_at' => $row['soldAt'],
        ]);

        $item = SaleItem::create([
            'sale_id' => $sale->id,
            'lot_id' => $lot->id,
            'sku' => $lot->sku,
            'owner_code' => $lot->owner_code,
            'qty' => 1,
            'list_price' => $lot->list_price,
            'discount' => 0,
            'sell_price' => $lot->list_price,
            'scheme_type' => $lot->scheme_type,
            'scheme_rate' => $lot->scheme_rate,
            'scheme_amount' => $lot->scheme_amount,
            'cost_price_snapshot' => $lot->cost_price,
            'fee_toko' => $fee,
            'hak_penitip' => $consign ? $hak : null,
            'input_method' => 'SCAN',
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'method' => $row['method'],
            'amount' => $lot->list_price,
            'reference' => $row['method'] === PaymentMethod::Qris ? 'QR-'.strtoupper($lot->sku) : null,
        ]);

        $lot->qty_on_hand = max(0, $lot->qty_on_hand - 1);
        $lot->last_sold_at = $row['soldAt'];
        $lot->save();

        StockMovement::create([
            'lot_id' => $lot->id,
            'type' => MovementType::Sale,
            'qty_delta' => -1,
            'ref_type' => Sale::class,
            'ref_id' => $sale->id,
            'actor_id' => $cashier->id,
            'device_id' => 'WMS-01',
            'reason' => 'Demo sale',
            'balance_after' => $lot->qty_on_hand,
            'created_at' => $row['soldAt'],
        ]);

        if ($consign && $hak > 0) {
            ConsignorLedger::create([
                'consignor_id' => $lot->consignor_id,
                'type' => LedgerType::SaleAccrual,
                'amount' => $hak,
                'sale_item_id' => $item->id,
                'sale_id' => $sale->id,
                'reason' => 'Akrual hak penitip ('.($lot->scheme_type?->value ?? '').')',
                'created_at' => $row['soldAt'],
            ]);
        }
    }
}
