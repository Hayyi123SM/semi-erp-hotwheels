<?php

declare(strict_types=1);

namespace Tests\Unit\Consignment;

use App\Enums\PaperSize;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Consignment\ReceiptContent;
use App\Services\Consignment\ReceiptSheet;
use App\Services\Print\Thermal\ReceiptRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Byte ESC/POS bukti terima.
 *
 * Renderer adalah satu-satunya tempat byte struk thermal disusun, jadi yang
 * dijaga di sini adalah bahwa isinya mengulang isi halaman bukti terima:
 * nomor dokumen, penitip, rincian SKU, dan footer. Kalau renderer dan halaman
 * memutuskan isi sendiri-sendiri, penitip bisa memegang dua struk berbeda
 * untuk satu barang -- dan tidak ada yang bisa bilang mana yang benar.
 */
class ReceiptRendererTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_bytes_open_with_the_esc_reset_and_carry_the_document(): void
    {
        $consignment = $this->consignmentWithLots();
        $bytes = ReceiptRenderer::render($this->sheet($consignment, PaperSize::Mm80));

        // Setiap cetakan ESC/POS mulai dari reset printer (ESC @).
        self::assertSame("\x1b", $bytes[0]);

        self::assertStringContainsString($consignment->doc_no, $bytes);
        self::assertStringContainsString($consignment->consignor->name, $bytes);
        self::assertStringContainsString($consignment->consignor->consignor_code, $bytes);
    }

    #[Test]
    public function the_bytes_list_every_lot_in_the_document(): void
    {
        $consignment = $this->consignmentWithLots();
        $bytes = ReceiptRenderer::render($this->sheet($consignment, PaperSize::Mm80));

        foreach ($consignment->stockLots->sortBy('sequence') as $lot) {
            self::assertStringContainsString($lot->sku, $bytes);
            self::assertStringContainsString($lot->qty_received.' pcs', $bytes);
        }

        self::assertStringContainsString('Petugas Toko', $bytes);
        self::assertStringContainsString('Dicetak:', $bytes);
    }

    #[Test]
    public function the_strip_ends_with_a_cut_so_the_receipt_comes_out_clean(): void
    {
        $consignment = $this->consignmentWithLots();
        $bytes = ReceiptRenderer::render($this->sheet($consignment, PaperSize::Mm80));

        // `Printer::cut()` mengirim GS V (potong penuh + feed).
        self::assertStringContainsString("\x1dV", $bytes);
    }

    #[Test]
    public function both_thermal_widths_render_without_throwing(): void
    {
        $consignment = $this->consignmentWithLots();

        $narrow = ReceiptRenderer::render($this->sheet($consignment, PaperSize::Mm58));
        $wide = ReceiptRenderer::render($this->sheet($consignment, PaperSize::Mm80));

        self::assertNotEmpty($narrow);
        self::assertNotEmpty($wide);
        self::assertStringContainsString($consignment->doc_no, $narrow);
        self::assertStringContainsString($consignment->doc_no, $wide);
    }

    #[Test]
    public function a4_is_refused_because_it_has_no_esc_pos_columns(): void
    {
        $consignment = $this->consignmentWithLots();

        $this->expectException(InvalidArgumentException::class);

        ReceiptRenderer::render($this->sheet($consignment, PaperSize::A4));
    }

    #[Test]
    public function the_renderer_reads_the_same_content_object_as_the_page(): void
    {
        // Bukan sekadar "isi dokumen". Nomor yang dicetak serializer dan yang
        // tampil di WhatsApp harus dibaca dari sumber yang sama, supaya dua
        // medium berhenti berbeda.
        $consignor = Consignor::factory()->create([
            'name' => 'Toko Jaya',
            'consignor_code' => 'TJ-01',
        ]);
        $consignment = $this->consignmentWithLots($consignor);

        $fromRenderer = ReceiptRenderer::render($this->sheet($consignment, PaperSize::Mm80));
        $fromContent = (new ReceiptContent($consignment))->variables();

        foreach (['doc_no', 'consignor_name', 'consignor_code', 'date', 'item_count', 'qty'] as $variable) {
            self::assertStringContainsString((string) $fromContent[$variable], $fromRenderer);
        }
    }

    private function sheet(Consignment $consignment, PaperSize $paper): ReceiptSheet
    {
        return new ReceiptSheet($consignment, $paper, User::factory()->staff()->create(), autoPrint: false);
    }

    private function consignmentWithLots(?Consignor $consignor = null): Consignment
    {
        $consignment = $consignor === null
            ? Consignment::factory()->completed()->create()
            : Consignment::factory()->completed()->for($consignor)->create();
        $consignor = $consignment->consignor;

        foreach ([1 => 5, 2 => 3] as $sequence => $qty) {
            StockLot::factory()->ownedBy($consignor)->create([
                'consignment_id' => $consignment->id,
                'sequence' => $sequence,
                'sku' => sprintf('CN%02d-HW-%03d', $consignment->id, $sequence),
                'qty_received' => $qty,
                'qty_on_hand' => $qty,
            ]);
        }

        return $consignment->fresh(['consignor', 'stockLots']);
    }
}
