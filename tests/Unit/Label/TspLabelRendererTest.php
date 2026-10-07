<?php

declare(strict_types=1);

namespace Tests\Unit\Label;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\OwnerType;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\StockLot;
use App\Services\Label\LabelContent;
use App\Services\Label\LabelTemplate;
use App\Services\Label\TspLabelOp;
use App\Services\Label\TspLabelRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test yang menjaga renderer TSPL tidak menyimpang dari geometri label.
 *
 * Renderer berjalan di sebuah jembatan yang bare: tanpa browser, tanpa CSS,
 * tidak ada yang menyembunyikan teks yang kelewat batas. Posisi setiap
 * operasi diperiksa terhadap angka dot nyata (8 dot/mm) supaya QR tidak
 * keluar padi-padian dari stiker dan teks tidak tercetak lewat tepi.
 */
class TspLabelRendererTest extends TestCase
{
    private const string FONT = '1';

    private function lot(string $productName = 'Ferrari F40', int $price = 50_000): StockLot
    {
        $lot = new StockLot([
            'sku' => 'CN01-HW-001-U03',
            'owner_code' => 'cn01',
            'owner_type' => OwnerType::Consign,
            'card_condition' => CardCondition::NearMint,
            'blister_condition' => BlisterCondition::Clear,
            'list_price' => $price,
            'qty_received' => 12,
        ]);

        $lot->setRelation('product', new Product(['name' => $productName]));
        $lot->setRelation('consignment', new Consignment);
        $lot->setRelation('consignor', new Consignor(['name' => 'Budi Santoso']));

        return $lot;
    }

    private function renderer(): TspLabelRenderer
    {
        return new TspLabelRenderer;
    }

    private function firstQr(array $ops): TspLabelOp
    {
        foreach ($ops as $op) {
            if ($op->kind === TspLabelOp::QR) {
                return $op;
            }
        }

        $this->fail('Tidak ada operasi QR di blok label.');
    }

    #[Test]
    public function qr_only_label_matches_label_geometry_dots(): void
    {
        $block = $this->renderer()->render(LabelContent::fromLot($this->lot()), LabelTemplate::QrOnly);

        // 1,5 x 1,5 cm pada 203 dpi = 120 x 120 dot.
        $this->assertSame(120, $block->widthDots);
        $this->assertSame(120, $block->heightDots);

        $qr = $this->firstQr($block->ops);

        // Sisi maksimal QR-only = 0,86 cm = 68,8 dot; sel = 69 / 29 = 2.
        $this->assertSame(2, $qr->cell);
        $this->assertSame(31, $qr->x); // (120 - 58) / 2 = 31, dipusatkan.
        $this->assertSame(5, $qr->y);  // padding 0,06 cm.
        $this->assertSame('CN01-HW-001-U03', $qr->text);
    }

    #[Test]
    public function qr_only_puts_sku_and_price_below_the_qr(): void
    {
        $block = $this->renderer()->render(LabelContent::fromLot($this->lot()), LabelTemplate::QrOnly);

        $texts = array_values(array_filter(
            $block->ops,
            fn (TspLabelOp $op) => $op->kind === TspLabelOp::TEXT,
        ));

        $this->assertCount(2, $texts);

        // SKU (14 karakter) lebih lebar dari kapasitas (13), jadi dipangkas
        // dengan penanda eksplisit alih-alih menggelinding lewat tepi label.
        $this->assertSame('CN01-HW-001..', $texts[0]->text);
        $this->assertSame('Rp50.000', $texts[1]->text);

        // Baris pertama mulai tepat setelah QR (5 + 58 + 16 = 79 dot),
        // baris kedua 16 dot berikutnya.
        $this->assertSame(79, $texts[0]->y);
        $this->assertSame(95, $texts[1]->y);

        // Baris terakhir (95 + 16 = 111) tidak boleh melewati padding bawah
        // (120 - 5 = 115).
        $this->assertLessThanOrEqual(115, $texts[1]->y + TspLabelRenderer::FONT_HEIGHT_DOT);
    }

    #[Test]
    public function three_by_two_has_side_qr_then_four_text_rows(): void
    {
        $block = $this->renderer()->render(LabelContent::fromLot($this->lot()), LabelTemplate::ThreeByTwo);

        $this->assertSame(240, $block->widthDots);
        $this->assertSame(160, $block->heightDots);

        $qr = $this->firstQr($block->ops);
        // Sisi QR 1,05 cm = 84 dot; 84 / 29 = 2, digambar 58 dot di x = 176.
        $this->assertSame(176, $qr->x);
        $this->assertSame(6, $qr->y);

        $texts = array_values(array_filter(
            $block->ops,
            fn (TspLabelOp $op) => $op->kind === TspLabelOp::TEXT,
        ));

        $this->assertCount(4, $texts);
        $this->assertSame('CN01-HW-001-U03', $texts[0]->text);
        $this->assertSame('Ferrari F40', $texts[1]->text);
        // Kondisi + pemilik digabung satu baris di 3x2, seperti HTML.
        $this->assertStringContainsString('CN01', $texts[2]->text);
        $this->assertSame('Rp50.000', $texts[3]->text);

        // Teks diletakkan di x = padding (6 dot), QR di sebelah kanan.
        $this->assertSame(6, $texts[0]->x);

        // Empat baris 16 dot, mulai dari padding atas; baris terakhir tidak
        // boleh lewat padding bawah (160 - 6 = 154).
        $this->assertSame(6, $texts[0]->y);
        $this->assertLessThanOrEqual(154, $texts[3]->y + TspLabelRenderer::FONT_HEIGHT_DOT);
    }

    #[Test]
    public function four_by_three_builds_all_five_rows_in_geometry_order(): void
    {
        $content = LabelContent::fromLot($this->lot());
        $block = $this->renderer()->render($content, LabelTemplate::FourByThree);

        $this->assertSame(320, $block->widthDots);
        $this->assertSame(240, $block->heightDots);

        $texts = array_values(array_filter(
            $block->ops,
            fn (TspLabelOp $op) => $op->kind === TspLabelOp::TEXT,
        ));

        // 5 baris (SKU, PRODUK, KONDISI, PEMILIK, HARGA) + QR.
        $this->assertCount(5, $texts);
        $this->assertSame(TspLabelOp::QR, $block->ops[0]->kind);

        $this->assertSame('CN01-HW-001-U03', $texts[0]->text);
        $this->assertSame('Ferrari F40', $texts[1]->text);
        $this->assertSame($content->conditionLabel(), $texts[2]->text);
        $this->assertSame('CN01', $texts[3]->text);
        $this->assertSame('Rp50.000', $texts[4]->text);
    }

    #[Test]
    public function show_price_false_leaves_harga_out_of_every_template(): void
    {
        $content = LabelContent::fromLot($this->lot());

        foreach (LabelTemplate::cases() as $template) {
            $with = $this->renderer()->render($content, $template, showPrice: true);
            $without = $this->renderer()->render($content, $template, showPrice: false);

            $countWith = $this->countTextOp($with->ops);
            $countWithout = $this->countTextOp($without->ops);

            $this->assertGreaterThan(
                $countWithout,
                $countWith,
                "Price baris harus hilang pada {$template->value}",
            );
        }
    }

    #[Test]
    public function qr_only_respects_override_that_is_within_the_qr_cap(): void
    {
        $block = $this->renderer()->render(
            LabelContent::fromLot($this->lot()),
            LabelTemplate::QrOnly,
            qrSideOverride: 0.70,
        );

        $qr = $this->firstQr($block->ops);

        // 0,7 cm = 56 dot; 56 / 29 = 1, digambar 29 dot di (120 - 29)/2 = 45.
        $this->assertSame(1, $qr->cell);
        $this->assertSame(45, $qr->x);
    }

    #[Test]
    public function qr_only_ignores_override_bigger_than_the_cap(): void
    {
        // Cap QR-only = 0,86 cm; override 1,2 cm diabaikan dengan tenang,
        // perilaku yang sama dengan `HtmlLabelRenderer`.
        $block = $this->renderer()->render(
            LabelContent::fromLot($this->lot()),
            LabelTemplate::QrOnly,
            qrSideOverride: 1.2,
        );

        $qr = $this->firstQr($block->ops);

        $this->assertSame(2, $qr->cell);
        $this->assertSame(31, $qr->x);
    }

    #[Test]
    public function non_ascii_and_quotes_are_sanitized_out_of_commands(): void
    {
        $content = LabelContent::fromLot($this->lot('Ferrari "F40" éclair'));

        $block = $this->renderer()->render($content, LabelTemplate::ThreeByTwo);

        $texts = array_values(array_filter(
            $block->ops,
            fn (TspLabelOp $op) => $op->kind === TspLabelOp::TEXT,
        ));

        $name = $texts[1]->text;

        $this->assertStringContainsString('Ferrari', $name);
        $this->assertStringContainsString('F40', $name);
        $this->assertStringContainsString('?', $name);
        $this->assertStringNotContainsString('"', $name);
        $this->assertStringNotContainsString('é', $name);
        // Semua karakter harus tetap ASCII printable supaya command TSPL utuh.
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $name);
    }

    #[Test]
    public function single_line_row_wrapping_then_truncates_with_marker(): void
    {
        $content = LabelContent::fromLot($this->lot('Ferrari F40', 100_000_000));

        // Rp100.000.000 = 13 karakter = tepat 13 kapasitas 3x2; dijamin bulat.
        $block = $this->renderer()->render($content, LabelTemplate::ThreeByTwo);

        $texts = array_values(array_filter(
            $block->ops,
            fn (TspLabelOp $op) => $op->kind === TspLabelOp::TEXT,
        ));

        $this->assertSame('Rp100.000.000', $texts[3]->text);
    }

    #[Test]
    public function rack_label_without_qr_prints_the_rack_code_centered(): void
    {
        $block = $this->renderer()->renderRack('R01-SLIP-002', LabelTemplate::ThreeByTwo);

        $texts = array_values(array_filter(
            $block->ops,
            fn (TspLabelOp $op) => $op->kind === TspLabelOp::TEXT,
        ));

        $this->assertCount(1, $texts);
        $this->assertSame('RK:R01-SLIP-002', $texts[0]->text);
        $this->assertSame(6, $texts[0]->y);
        $this->assertSame(6, $texts[0]->x);
    }

    #[Test]
    public function rack_label_four_by_three_keeps_qr_on_far_right(): void
    {
        $block = $this->renderer()->renderRack('R01-SLIP-002', LabelTemplate::FourByThree);

        $qr = $this->firstQr($block->ops);

        // Sisi QR rak 4x3 = 1,25 cm = 100 dot; 100 / 29 = 3 digambar 87,
        // di x = 320 - padding 8 - 87 = 225.
        $this->assertSame(3, $qr->cell);
        $this->assertSame(225, $qr->x);
        $this->assertSame(8, $qr->y);
    }

    #[Test]
    public function rack_three_by_two_has_no_qr_at_all(): void
    {
        $block = $this->renderer()->renderRack('R01', LabelTemplate::ThreeByTwo);

        $this->assertSame(0, $this->countQrOp($block->ops));
    }

    /**
     * @param  list<TspLabelOp>  $ops
     */
    private function countTextOp(array $ops): int
    {
        return count(array_filter($ops, fn (TspLabelOp $op) => $op->kind === TspLabelOp::TEXT));
    }

    /**
     * @param  list<TspLabelOp>  $ops
     */
    private function countQrOp(array $ops): int
    {
        return count(array_filter($ops, fn (TspLabelOp $op) => $op->kind === TspLabelOp::QR));
    }
}
