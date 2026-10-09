<?php

declare(strict_types=1);

namespace Tests\Unit\Pos;

use App\Enums\PaperSize;
use App\Enums\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Pos\SaleStrukSheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * View model struk POS.
 *
 * Pratinjau di layar kasir, halaman cetak, dan byte ESC/POS membaca satu objek
 * ini. Yang dijaga di sini adalah bahwa angkanya dibaca dari `sales` dan
 * `sale_items` -- bukan dari input mana pun -- dan bahwa nama toko mengikuti
 * `Setting` dengan fallback yang sama seperti bukti terima titipan. Kalau dua
 * medium menampilkan struk yang berbeda, tidak ada yang bisa bilang mana yang
 * dicetak kasir pada malam itu.
 */
class SaleStrukSheetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Satu nota, satu baris lot milik produk bernama, satu pembayaran tunai.
     */
    private function sale(User $cashier, string $receipt = 'HW-20261006-0001'): Sale
    {
        $sale = Sale::factory()->create([
            'receipt_no' => $receipt,
            'shift_id' => Shift::factory()->forUser($cashier)->create()->id,
            'user_id' => $cashier->id,
        ]);

        $product = Product::factory()->create(['name' => 'Mobil Koleksi RSR']);
        $lot = StockLot::factory()->create([
            'product_id' => $product->id,
            'sku' => 'POS-HW-001',
        ]);

        SaleItem::factory()->create([
            'sale_id' => $sale->id,
            'lot_id' => $lot->id,
            'sku' => $lot->sku,
            'qty' => 2,
            'list_price' => 45_000,
            'discount' => 5_000,
            'sell_price' => 40_000,
            'fee_toko' => 16_000,
            'hak_penitip' => 64_000,
        ]);

        SalePayment::factory()->create([
            'sale_id' => $sale->id,
            'method' => PaymentMethod::Cash,
            'amount' => $sale->total,
        ]);

        return $sale->fresh(['items.lot.product.series', 'payments', 'cashier', 'shift']);
    }

    private function sheet(
        Sale $sale,
        PaperSize $paper = PaperSize::Mm80,
        ?int $changeDue = null,
        ?User $printedBy = null,
    ): SaleStrukSheet {
        return new SaleStrukSheet(
            sale: $sale,
            paper: $paper,
            printedBy: $printedBy ?? User::factory()->staff()->create(['name' => 'Rangga Saputra']),
            changeDue: $changeDue,
        );
    }

    #[Test]
    public function the_store_name_comes_from_settings_with_the_receipts_fallback(): void
    {
        $sale = $this->sale(User::factory()->staff()->create());

        self::assertSame('167 Diecast Shop', $this->sheet($sale)->storeName());

        Setting::set('store.name', 'Toko Mainan Andi');

        // Setting dibaca setiap pemanggilan, jadi sheet yang sama boleh dibuat
        // lagi tanpa takut nama toko mengikat ke momen pembuatannya.
        self::assertSame('Toko Mainan Andi', $this->sheet($sale)->storeName());
    }

    #[Test]
    public function the_sheet_reads_the_note_and_who_printed_it(): void
    {
        $cashier = User::factory()->staff()->create(['name' => 'Budi Santoso']);
        $sale = $this->sale($cashier);
        $sheet = $this->sheet($sale);

        // Nama kasir dibaca dari relasi `cashier`, dan waktu struk adalah
        // waktu transaksi (`sold_at`), bukan waktu mencetak.
        self::assertSame('HW-20261006-0001', $sheet->docNo());
        self::assertSame('Budi Santoso', $sheet->cashierName());
        self::assertSame('#'.$sale->shift_id, $sheet->shiftNo());
        self::assertSame('Rangga Saputra', $sheet->printedByName());

        // Waktu transaksi terbaca dan bukan teks kosong; format persisnya
        // adalah urusan `Format::datetime()`.
        self::assertNotSame('', $sheet->soldOn());
    }

    #[Test]
    public function the_lines_are_named_and_priced_from_the_stored_item(): void
    {
        $sheet = $this->sheet($this->sale(User::factory()->staff()->create()));
        $line = $sheet->lines()[0];

        self::assertSame('POS-HW-001', $line['sku']);
        self::assertSame('Mobil Koleksi RSR', $line['name']);
        self::assertSame(2, $line['qty']);
        self::assertSame(40_000, $line['price']);
        self::assertSame(80_000, $line['line_total']);
        self::assertSame(5_000, $line['discount']);
        self::assertSame(45_000, $line['list_price']);
    }

    #[Test]
    public function payments_carry_label_amount_and_reference(): void
    {
        $sale = $this->sale(User::factory()->staff()->create());
        $sale->payments()->create([
            'method' => PaymentMethod::Qris,
            'amount' => 20_000,
            'reference' => 'QRIS-REF-01',
        ]);

        // Relasi `payments` sudah termuat saat `sale()` dibuat, jadi baris baru
        // tidak akan terlihat sebelum dimuat ulang.
        $sale->load('payments');

        $payments = $this->sheet($sale)->payments();

        self::assertSame('Tunai', $payments[0]['method']);
        self::assertSame(90_000, $payments[0]['amount']);
        self::assertNull($payments[0]['reference']);

        self::assertSame('QRIS', $payments[1]['method']);
        self::assertSame('QRIS-REF-01', $payments[1]['reference']);
    }

    #[Test]
    public function change_due_defaults_to_null_and_records_what_was_given(): void
    {
        $sale = $this->sale(User::factory()->staff()->create());

        self::assertNull($this->sheet($sale)->changeDue);
        self::assertSame(10_000, $this->sheet($sale, changeDue: 10_000)->changeDue);
    }

    #[Test]
    public function the_page_reports_each_paper(): void
    {
        $sale = $this->sale(User::factory()->staff()->create());

        $page58 = $this->sheet($sale, PaperSize::Mm58)->page();
        self::assertSame(['size' => '58mm auto', 'width' => '58mm', 'thermal' => true], $page58);

        $page80 = $this->sheet($sale, PaperSize::Mm80)->page();
        self::assertSame(['size' => '80mm auto', 'width' => '80mm', 'thermal' => true], $page80);

        $pageA4 = $this->sheet($sale, PaperSize::A4)->page();
        self::assertSame(['size' => 'A4', 'width' => '190mm', 'thermal' => false], $pageA4);
    }
}
