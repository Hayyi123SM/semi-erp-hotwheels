<?php

declare(strict_types=1);

namespace Tests\Unit\Label;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\LabelReason;
use App\Enums\OwnerType;
use App\Models\LabelPrintJob;
use App\Models\Product;
use App\Models\Rack;
use App\Models\StockLot;
use App\Services\Label\HtmlLabelRenderer;
use App\Services\Label\LabelPage;
use App\Services\Label\LabelPrinterSettings;
use App\Services\Label\LabelRenderer;
use App\Services\Label\LabelTemplate;
use App\Services\Label\QrCode;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LabelPageTest extends TestCase
{
    /**
     * Setelan Owner yang sudah dibaca, dengan QR dibiarkan kosong.
     *
     * Kunci `QR_SIDE_KEY` sengaja diisi `null`: `LabelPrinterSettings` memakai
     * `array_key_exists`, jadi kunci yang ada -- walaupun dengan nilai kosong --
     * berarti "sudah dibaca" dan `Setting` tidak pernah disentuh. Tanpa ini test
     * unit ini butuh database hanya untuk mengetahui bahwa Owner belum
     * mengubah apa pun.
     */
    private const OWNER_DEFAULTS = [
        LabelPrinterSettings::QR_SIDE_KEY => null,
    ];

    private LabelPage $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->page = new LabelPage(new HtmlLabelRenderer(new QrCode, new LabelPrinterSettings(self::OWNER_DEFAULTS)));
    }

    private function lot(string $sku = 'CN01-HW-001-U03', int $price = 50_000): StockLot
    {
        $lot = new StockLot([
            'sku' => $sku,
            'owner_code' => 'CN01',
            'owner_type' => OwnerType::Consign,
            'card_condition' => CardCondition::NearMint,
            'blister_condition' => BlisterCondition::Clear,
            'list_price' => $price,
            'qty_received' => 12,
        ]);

        $lot->setRelation('product', new Product(['name' => 'Ferrari F40']));

        return $lot;
    }

    private function job(int $copies = 1, string $template = '3x2', ?array $payload = null, bool $showPrice = true): LabelPrintJob
    {
        $job = new LabelPrintJob([
            'copies' => $copies,
            'reason' => LabelReason::Initial,
            'template' => $template,
            'show_price' => $showPrice,
        ]);

        $job->setRelation('lot', $this->lot());
        $job->payload = $payload;

        return $job;
    }

    /**
     * Ukuran yang dipakai kalau sebuah test tidak sedang menguji ukuran.
     *
     * `forJobs()` menerimanya dari pemanggil, jadi setiap test yang concerned
     * dengan jumlah label atau isi label tidak perlu menyebut ukuran sama
     * sekali. Yang sedang menguji ukuran mention sendiri.
     */
    private function template(): LabelTemplate
    {
        return LabelTemplate::ThreeByTwo;
    }

    #[Test]
    public function one_job_produces_one_label_per_copy(): void
    {
        $html = $this->page->forJobs(collect([$this->job(copies: 5)]), $this->template());

        $this->assertSame(5, substr_count($html, 'label__body'));
    }

    #[Test]
    public function several_jobs_are_all_printed(): void
    {
        $html = $this->page->forJobs(collect([$this->job(copies: 2), $this->job(copies: 3)]), $this->template());

        $this->assertSame(5, substr_count($html, 'label__body'));
    }

    #[Test]
    public function an_empty_selection_still_renders_a_valid_empty_page(): void
    {
        $html = $this->page->forJobs(collect(), $this->template());

        $this->assertStringContainsString('label-sheet', $html);
        $this->assertStringNotContainsString('label__body', $html);
    }

    /**
     * Ini alasan kolom `payload` ada.
     *
     * Job lama disimpan bersama isi labelnya. Kalau halaman ini membaca lot
     * langsung, harga yang sudah naik akan ikut tercetak, dan re-print dengan
     * alasan `PRICE_CHANGE` akan menghasilkan label yang isinya sama saja
     * dengan cetakan pertama. Alasan re-print jadi tidak berarti.
     */
    #[Test]
    public function a_job_that_was_already_printed_reuses_its_stored_payload(): void
    {
        $job = $this->job(
            copies: 1,
            payload: [
                'sku' => 'CN01-HW-001-U03',
                'product_name' => 'Ferrari F40',
                'owner_code' => 'CN01',
                'owner_type' => OwnerType::Consign->value,
                'card_condition' => CardCondition::NearMint->value,
                'blister_condition' => BlisterCondition::Clear->value,
                'list_price' => 75_000,
                'quantity' => 12,
            ],
        );

        $html = $this->page->forJobs(collect([$job]), $this->template());

        // Lot sekarang masih Rp50.000; yang harus tercetak Rp75.000.
        $this->assertStringContainsString('Rp75.000', $html);
        $this->assertStringNotContainsString('Rp50.000', $html);
    }

    /**
     * Job yang belum pernah dicetak belum punya payload, jadi halaman ini
     * harus tetap bisa membuat label dari data lot.
     */
    #[Test]
    public function a_job_without_a_payload_falls_back_to_the_lot(): void
    {
        $html = $this->page->forJobs(collect([$this->job(copies: 1)]), $this->template());

        $this->assertStringContainsString('Rp50.000', $html);
        $this->assertStringContainsString('CN01-HW-001-U03', $html);
    }

    #[Test]
    public function the_template_passed_in_by_the_caller_is_used(): void
    {
        $html = $this->page->forJobs(collect([$this->job(copies: 1)]), LabelTemplate::FourByThree);

        $this->assertStringContainsString('width:4cm;height:3cm', $html);
    }

    /**
     * Ini yang membuat satu sheet tidak pernah berisi dua ukuran kertas.
     *
     * Antrean label bisa menahan job dari sebelum dan sesudah Owner mengganti
     * ukuran. Kalau `template` milik job ikut dipakai, job lama memakai ukuran
     * lamanya dan hasilnya bercampur di satu halaman -- yang keluar dari
     * printer bergantian ukuran, dan operator tidak bisa mengukur gauge-nya.
     *
     * Kolom `template` di job tetap ditulis, tapi hanya sebagai catatan: nilai
     * ini menguji bahwa ukurannya TIDAK ikut menentukan cetakan.
     */
    #[Test]
    public function the_template_stored_on_a_job_does_not_decide_its_label_size(): void
    {
        $stale = $this->job(copies: 1, template: '4x3');
        $fresh = $this->job(copies: 1, template: '1.5x1.5');

        $html = $this->page->forJobs(collect([$stale, $fresh]), LabelTemplate::ThreeByTwo);

        $this->assertStringNotContainsString('width:4cm', $html);
        $this->assertStringNotContainsString('width:1.5cm', $html);
        $this->assertSame(2, substr_count($html, 'width:3cm;height:2cm'));
    }

    #[Test]
    public function the_price_toggle_reaches_the_label(): void
    {
        $html = $this->page->forJobs(collect([$this->job(copies: 1, showPrice: false)]), $this->template());

        $this->assertStringNotContainsString('Rp50.000', $html);
    }

    /**
     * Halaman dirender penuh di memori. 600 label sudah menghasilkan halaman
     * yang sangat besar; lebih dari itu sebaiknya ditolak, karena memotongnya
     * diam-diam membuat operator mengira semua label tercetak padahal tidak.
     */
    #[Test]
    public function an_enormous_selection_is_refused_instead_of_being_silently_trimmed(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/melebihi batas/');

        $this->page->forJobs(collect([$this->job(copies: LabelPage::MAX_LABELS_PER_PAGE + 1)]), $this->template());
    }

    /**
     * Pengecekannya harus terjadi sebelum satu label pun digambar.
     *
     * Tes di atas membuktikan exception-nya muncul, tapi tidak membuktikan
     * urutannya. Kalau guard-nyataruh setelah render, 601 label sudah jadi
     * HTML yang bisa berukuran ratusan megabyte sebelum ditolak -- dan di
     * environment shared hosting yang kehabisan memori, operator melihat
     * "Allowed memory size exhausted", bukan pesan yang menyebut batas label.
     *
     * Karena itu renderer-nya diganti spy: kalau ada yang memanggil
     * `render()`, tes ini gagal di tempat yang salah, bukan operator yang
     * benar-benar menolak.
     */
    #[Test]
    public function the_refusal_happens_before_a_single_label_is_rendered(): void
    {
        $renderer = $this->createMock(LabelRenderer::class);
        $renderer->expects($this->never())->method('render');
        $renderer->expects($this->never())->method('renderRack');

        $page = new LabelPage($renderer);

        try {
            $page->forJobs(collect([$this->job(copies: LabelPage::MAX_LABELS_PER_PAGE + 1)]), $this->template());
            $this->fail('Pengecekan batas tidak dijalankan.');
        } catch (LogicException) {
            // Pesan penolaknya sudah diuji tes di atas; yang penting di sini
            // renderer tidak sempat dipanggil.
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function a_rack_page_repeats_the_label_the_requested_number_of_times(): void
    {
        $html = $this->page->forRack(new Rack(['code' => 'A-01-03']), LabelTemplate::ThreeByTwo, copies: 4);

        $this->assertSame(4, substr_count($html, 'label--rack'));
        $this->assertStringContainsString('RK:A-01-03', $html);
    }

    #[Test]
    public function a_rack_page_needs_at_least_one_label(): void
    {
        $this->expectException(LogicException::class);

        $this->page->forRack(new Rack(['code' => 'A-01-03']), LabelTemplate::ThreeByTwo, copies: 0);
    }
}
