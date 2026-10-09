<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LabelPrintJob;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Label\LabelContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Uji cetak label (FR-IB-25).
 *
 * Uji cetak dipakai untuk mengukur gauge printer dan jarak antar label, jadi
 * halaman ini sengaja TIDAK mencatat apa pun: tidak membuat `label_print_jobs`,
 * tidak menaikkan `labels_printed`, tidak menulis audit. Kalau mencatat, setiap
 * penyetelan printer akan menambah cetakan palsu, dan lot bisa terlihat sudah
 * berlabel padahal tidak ada label yang ditempel.
 *
 * Isinya diambil dari `LabelContent::sample()` -- kasus terburuk yang mungkin
 * muncul di label asli -- supaya layout yang meluap ketahuan di printer.
 */
class LabelTestPrintTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function templates(): array
    {
        return [
            '3x2' => ['3x2'],
            '4x3' => ['4x3'],
        ];
    }

    /**
     * Halaman uji cetak bukan rute POS, jadi STAFF ditolak middleware owner.
     */
    #[Test]
    #[DataProvider('templates')]
    public function staff_cannot_open_the_test_print_page(string $template): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get(route('inbound.cetak-label.test-print', ['template' => $template]))
            ->assertForbidden();
    }

    #[Test]
    public function a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('inbound.cetak-label.test-print'))->assertRedirect(route('login'));
    }

    /**
     * Tanpa parameter, halaman tetap terbuka dengan ukuran default supaya
     * operator tidak menemui 500 saat menekan tombol dari antrean.
     */
    #[Test]
    public function the_test_print_page_has_a_default_template(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.cetak-label.test-print'))
            ->assertOk()
            ->assertViewHas('total', 1);
    }

    #[Test]
    public function the_large_template_includes_the_calibration_qr(): void
    {
        // Label barang selalu ber-QR, jadi uji cetak harus menunjukkan QR-nya
        // juga: tanpa itu ukuran QR tidak ikut terkalibrasi.
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.cetak-label.test-print', ['template' => '3x2']))
            ->assertSee('label__qr', escape: false)
            ->assertSee('HW-2024-000123X', escape: false);
    }

    #[Test]
    public function an_unknown_template_is_rejected(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.cetak-label.test-print', ['template' => '10x10']))
            ->assertSessionHasErrors('template');
    }

    #[Test]
    public function zero_copies_are_rejected(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.cetak-label.test-print', ['copies' => 0]))
            ->assertSessionHasErrors('copies');
    }

    /**
     * Jumlah label harus benar-benar dikalikan. Uji cetak dipakai juga untuk
     * mengukur panjang gulir kertas, jadi satu label saja tidak pernah
     * menjawab pertanyaan itu.
     */
    #[Test]
    public function copies_multiply_the_test_labels(): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.cetak-label.test-print', ['template' => '3x2', 'copies' => 5]));

        $response->assertOk()->assertViewHas('total', 5);
        $this->assertSame(5, substr_count((string) $response->getContent(), 'label__qr'));
    }

    /**
     * Ini inti FR-IB-25: mengukur printer tidak boleh mengubah data apa pun.
     */
    #[Test]
    public function the_test_print_creates_no_job_and_leaves_stock_untouched(): void
    {
        $lot = StockLot::factory()->create([
            'labels_printed' => 3,
            'reprint_count' => 2,
        ]);

        $before = $lot->only(['labels_printed', 'reprint_count', 'qty_on_hand']);

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.cetak-label.test-print'))
            ->assertOk();

        $this->assertSame(0, LabelPrintJob::count());
        $this->assertSame(0, AuditLog::count());
        $this->assertSame($before, $lot->fresh()->only(['labels_printed', 'reprint_count', 'qty_on_hand']));
    }

    // ------------------------------------------------------------------
    // Jalur TSPL uji cetak: perintah yang sama, tanpa dialog browser.
    //
    // Margin, skala, dan pemilihan kertas di dialog peramban ikut menggeser
    // label -- dan yang diukur operator di halaman ini justru jarak antar
    // label. Endpoint ini menyusun perintah dari `LabelContent::sample()`
    // yang sama dengan jalur HTML, supaya kedua jalur bisa dibandingkan.
    // ------------------------------------------------------------------

    #[Test]
    public function the_tspl_endpoint_returns_commands_for_the_sample_label(): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())
            ->postJson(route('inbound.cetak-label.test-print-tsp'), ['template' => '3x2', 'copies' => 2])
            ->assertOk()
            ->json();

        $this->assertTrue($response['ok']);
        $this->assertSame(2, $response['total']);
        $this->assertSame('roll', $response['paper']);
        $this->assertStringContainsString('SIZE ', $response['text']);
        // Isi label harus label contoh, bukan data lot sungguhan apa pun.
        $this->assertStringContainsString(LabelContent::sample()->sku, $response['text']);

        // Jumlah salinan benar-benar dikalikan: satu perintah PRINT per label.
        $prints = array_filter(
            explode("\n", trim($response['text'])),
            fn (string $line) => str_starts_with($line, 'PRINT'),
        );
        $this->assertSame(2, count($prints));
    }

    /**
     * Pasangan dari `the_test_print_creates_no_job_and_leaves_stock_untouched`
     * untuk jalur TSPL: mengukur printer lewat jalur langsung juga tidak boleh
     * meninggalkan jejak.
     */
    #[Test]
    public function the_tspl_test_print_records_nothing(): void
    {
        $lot = StockLot::factory()->create([
            'labels_printed' => 3,
            'reprint_count' => 2,
        ]);

        $before = $lot->only(['labels_printed', 'reprint_count', 'qty_on_hand']);

        $this->actingAs(User::factory()->owner()->create())
            ->postJson(route('inbound.cetak-label.test-print-tsp'))
            ->assertOk();

        $this->assertSame(0, LabelPrintJob::count());
        $this->assertSame(0, AuditLog::count());
        $this->assertSame($before, $lot->fresh()->only(['labels_printed', 'reprint_count', 'qty_on_hand']));
    }

    /**
     * Validasi identik dengan jalur GET: satu halaman tidak boleh bisa
     * meminta dua ukuran berbeda tergantung tombol mana yang ditekan.
     */
    #[Test]
    public function the_tspl_test_print_rejects_invalid_input(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->postJson(route('inbound.cetak-label.test-print-tsp'), ['copies' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('copies');

        $this->actingAs($owner)
            ->postJson(route('inbound.cetak-label.test-print-tsp'), ['template' => '10x10'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('template');
    }

    #[Test]
    public function a_guest_cannot_fetch_the_tspl_test_print(): void
    {
        $this->post(route('inbound.cetak-label.test-print-tsp'))
            ->assertRedirect(route('login'));
    }

    /**
     * Tombol jalur langsung harus ada di halaman uji cetak beserta payload
     * yang benar -- tanpanya operator tidak pernah tahu ada jalur kedua, dan
     * margin dialog browser tetap jadi satu-satunya pilihan.
     */
    #[Test]
    public function the_test_print_page_offers_the_direct_thermal_path(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.cetak-label.test-print', ['template' => '3x2', 'copies' => 2]))
            ->assertOk()
            ->assertSee('data-thermal-label', escape: false)
            ->assertSee(route('inbound.cetak-label.test-print-tsp'), escape: false)
            ->assertSee('&quot;template&quot;:&quot;3x2&quot;', escape: false)
            ->assertSee('&quot;copies&quot;:2', escape: false);
    }
}
