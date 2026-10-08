<?php

declare(strict_types=1);

namespace Tests\Unit\Pos;

use App\Enums\PaperSize;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Pos\SaleStrukSheet;
use App\Services\Print\Thermal\PosStrukRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Byte ESC/POS struk kasir.
 *
 * Renderer ini satu-satunya tempat byte struk thermal disusun, jadi yang dijaga
 * adalah bahwa isinya mengulang isi halaman struk: nomor nota, kasir, rincian
 * SKU, dan footer. Kalau halaman dan byte memutuskan isi sendiri-sendiri,
 * kasir bisa memegang dua struk berbeda untuk satu penjualan -- dan tidak ada
 * yang bisa bilang mana yang benar.
 */
class PosStrukRendererTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_bytes_open_with_the_esc_reset_and_carry_the_note(): void
    {
        $sale = $this->sale();
        $bytes = PosStrukRenderer::render($this->sheet($sale));

        // Setiap cetakan ESC/POS mulai dari reset printer (ESC @).
        self::assertSame("\x1b", $bytes[0]);

        self::assertStringContainsString($sale->receipt_no, $bytes);
        self::assertStringContainsString($sale->cashier->name, $bytes);
        self::assertStringContainsString('Nota Kasir', $bytes);
        self::assertStringContainsString('No. nota', $bytes);
    }

    #[Test]
    public function the_bytes_list_every_item_in_the_note(): void
    {
        $sale = $this->sale();
        $bytes = PosStrukRenderer::render($this->sheet($sale));

        $item = $sale->items()->sole();

        self::assertStringContainsString($item->sku, $bytes);
        self::assertStringContainsString('2 x Rp40.000', $bytes);
        self::assertStringContainsString('Rp80.000', $bytes);

        self::assertStringContainsString('Total', $bytes);
        self::assertStringContainsString('Terima kasih atas kunjungan Anda.', $bytes);
        self::assertStringContainsString('Dicetak:', $bytes);
    }

    #[Test]
    public function the_strip_ends_with_a_cut_so_the_struk_comes_out_clean(): void
    {
        $bytes = PosStrukRenderer::render($this->sheet($this->sale()));

        // `Printer::cut()` mengirim GS V (potong penuh + feed).
        self::assertStringContainsString("\x1dV", $bytes);
    }

    #[Test]
    public function both_thermal_widths_render_without_throwing(): void
    {
        $sale = $this->sale();

        $narrow = PosStrukRenderer::render($this->sheet($sale, PaperSize::Mm58));
        $wide = PosStrukRenderer::render($this->sheet($sale, PaperSize::Mm80));

        self::assertNotEmpty($narrow);
        self::assertNotEmpty($wide);
        self::assertStringContainsString($sale->receipt_no, $narrow);
        self::assertStringContainsString($sale->receipt_no, $wide);
    }

    #[Test]
    public function a4_is_refused_because_it_has_no_esc_pos_columns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PosStrukRenderer::render($this->sheet($this->sale(), PaperSize::A4));
    }

    #[Test]
    public function the_change_row_only_appears_when_the_sheet_knows_the_change(): void
    {
        $sale = $this->sale();

        $withoutChange = PosStrukRenderer::render($this->sheet($sale));
        self::assertStringNotContainsString('Kembalian', $withoutChange);

        $withChange = PosStrukRenderer::render($this->sheet($sale, changeDue: 10_000));
        self::assertStringContainsString('Kembalian', $withChange);
        self::assertStringContainsString('Rp10.000', $withChange);
    }

    private function sheet(Sale $sale, PaperSize $paper = PaperSize::Mm80, ?int $changeDue = null): SaleStrukSheet
    {
        return new SaleStrukSheet(
            sale: $sale,
            paper: $paper,
            printedBy: User::factory()->staff()->create(['name' => 'Rangga Saputra']),
            changeDue: $changeDue,
        );
    }

    private function sale(string $receipt = 'HW-20261006-0001'): Sale
    {
        $cashier = User::factory()->staff()->create(['name' => 'Budi Santoso']);

        $sale = Sale::factory()->create([
            'receipt_no' => $receipt,
            'shift_id' => Shift::factory()->forUser($cashier)->create()->id,
            'user_id' => $cashier->id,
            'subtotal' => 80_000,
            'discount_total' => 0,
            'total' => 80_000,
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
        ]);

        SalePayment::factory()->create([
            'sale_id' => $sale->id,
            'amount' => $sale->total,
        ]);

        return $sale->fresh(['items.lot.product.series', 'payments', 'cashier', 'shift']);
    }
}
