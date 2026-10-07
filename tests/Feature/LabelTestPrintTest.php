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

    #[Test]
    #[DataProvider('templates')]
    public function staff_can_open_the_test_print_page(string $template): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get(route('inbound.cetak-label.test-print', ['template' => $template]))
            ->assertOk()
            ->assertViewIs('pages.inbound.label-print')
            ->assertViewHas('isTestPrint', true)
            ->assertSee('Uji cetak', escape: false)
            ->assertSee(LabelContent::sample()->sku, escape: false);
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
        $this->actingAs(User::factory()->staff()->create())
            ->get(route('inbound.cetak-label.test-print'))
            ->assertOk()
            ->assertViewHas('total', 1);
    }

    #[Test]
    public function the_large_template_includes_the_calibration_qr(): void
    {
        // Label barang selalu ber-QR, jadi uji cetak harus menunjukkan QR-nya
        // juga: tanpa itu ukuran QR tidak ikut terkalibrasi.
        $this->actingAs(User::factory()->staff()->create())
            ->get(route('inbound.cetak-label.test-print', ['template' => '3x2']))
            ->assertSee('label__qr', escape: false)
            ->assertSee('HW-2024-000123X', escape: false);
    }

    #[Test]
    public function an_unknown_template_is_rejected(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get(route('inbound.cetak-label.test-print', ['template' => '10x10']))
            ->assertSessionHasErrors('template');
    }

    #[Test]
    public function zero_copies_are_rejected(): void
    {
        $this->actingAs(User::factory()->staff()->create())
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
        $response = $this->actingAs(User::factory()->staff()->create())
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

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('inbound.cetak-label.test-print'))
            ->assertOk();

        $this->assertSame(0, LabelPrintJob::count());
        $this->assertSame(0, AuditLog::count());
        $this->assertSame($before, $lot->fresh()->only(['labels_printed', 'reprint_count', 'qty_on_hand']));
    }
}
