<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaperSize;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\Print\PrintSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Halaman cetak struk POS (`pos.struk`) dan endpoint byte thermal-nya.
 *
 * Tiga hal yang dijaga di sini, dan ketiganya soal sesuatu yang tidak terlihat
 * di test view biasa:
 *
 * 1. `@page` ikut benar untuk setiap kertas. Ukuran yang salah tidak merusak
 *    tampilan, dan gejalanya baru muncul di depan printer: struk 80 mm keluar
 *    tercetak di tengah A4, lalu pemotong thermal memotong seluruh A4 itu.
 *
 * 2. Cetak ulang dari riwayat tidak pernah memunculkan dialog print sendiri;
 *    hanya `print_url` dari layar kasir yang membawa `?auto=1`.
 *
 * 3. Nota orang lain 404, bukan 403 -- aturan kepemilikan yang sama dengan
 *    halaman nota, supaya daftar dan halaman tidak pernah berbeda pendapat.
 */
class PosStrukPrintTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        return User::factory()->staff()->create();
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    private function sale(User $cashier, string $receipt = 'HW-20261006-0001'): Sale
    {
        $sale = Sale::factory()->create([
            'receipt_no' => $receipt,
            'shift_id' => Shift::factory()->forUser($cashier)->create()->id,
            'user_id' => $cashier->id,
        ]);

        SaleItem::factory()->create(['sale_id' => $sale->id, 'sku' => 'POS-HW-001']);

        SalePayment::factory()->create([
            'sale_id' => $sale->id,
            'amount' => $sale->total,
        ]);

        return $sale;
    }

    #[Test]
    public function the_page_carries_the_numbers_of_the_note(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier);

        $this->actingAs($cashier)
            ->get(route('pos.struk', $sale))
            ->assertOk()
            ->assertSee($sale->receipt_no)
            ->assertSee($sale->items()->sole()->sku)
            ->assertSee('Nota Kasir');
    }

    #[Test]
    #[DataProvider('paperCases')]
    public function the_print_dialog_gets_the_paper_the_owner_chose(PaperSize $paper, string $expected): void
    {
        Setting::set(PrintSettings::PAPER_KEY, $paper->value);

        $cashier = $this->staff();
        $sale = $this->sale($cashier);

        $response = $this->actingAs($cashier)
            ->get(route('pos.struk', $sale))
            ->assertOk();

        $this->assertStringContainsString('size: '.$expected, $response->getContent());
    }

    /**
     * @return array<string, array{0: PaperSize, 1: string}>
     */
    public static function paperCases(): array
    {
        return [
            'struk 58' => [PaperSize::Mm58, '58mm auto'],
            'struk 80' => [PaperSize::Mm80, '80mm auto'],
            'A4' => [PaperSize::A4, 'A4'],
        ];
    }

    #[Test]
    public function the_footer_names_when_and_by_whom_the_struk_was_printed(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier);

        // Pencetaknya Owner: yang dibaca `printedByName` adalah pengunjung yang
        // membuka halaman, dan kasir lain tidak akan bisa membukanya.
        $printer = $this->owner();
        $printer->update(['name' => 'Rangga Saputra']);

        $response = $this->actingAs($printer)
            ->get(route('pos.struk', $sale))
            ->assertOk();

        $response->assertSee('Dicetak:');
        $response->assertSee('oleh Rangga Saputra');
        $response->assertSee('WIB', escape: false);
    }

    #[Test]
    public function the_paper_does_not_print_its_own_toolbar(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier);

        $content = $this->actingAs($cashier)
            ->get(route('pos.struk', $sale))
            ->assertOk()
            ->getContent();

        // Toolbar ada di HTML, tapi disembunyikan saat print lewat CSS. Yang
        // diperiksa adalah bahwa toolbar itu ada sebagai elemen tersendiri --
        // kalau tidak, tidak ada yang bisa disembunyikan.
        $this->assertStringContainsString('receipt-toolbar', (string) $content);
    }

    #[Test]
    public function auto_print_only_appears_when_it_was_asked_for(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier);

        $withoutFlag = $this->actingAs($cashier)
            ->get(route('pos.struk', $sale))
            ->assertOk()
            ->getContent();

        // Yang diperiksa skrip auto-print-nya, bukan `window.print()` secara
        // umum: tombol "Cetak" memang memanggilnya, dan itu selalu ada. Yang
        // harus hilang tanpa `?auto=1` adalah pemanggilan berjadwal yang
        // membuka dialog print sendiri.
        $this->assertStringNotContainsString('window.setTimeout(() =>', (string) $withoutFlag);

        $withFlag = $this->actingAs($cashier)
            ->get(route('pos.struk', ['sale' => $sale, 'auto' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('window.setTimeout(() =>', (string) $withFlag);
        $this->assertStringContainsString('window.print()', (string) $withFlag);
    }

    #[Test]
    public function a_change_carried_from_checkout_lands_in_the_thermal_url(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier);

        // Cetakan ulang dari riwayat tidak membawa `change`, jadi tombol
        // thermal-nya pun tidak menyebut kembalian.
        $reprint = $this->actingAs($cashier)
            ->get(route('pos.struk', $sale))
            ->assertOk()
            ->getContent();

        $thermalUrl = route('pos.struk.thermal', $sale);
        $this->assertStringContainsString('data-url="'.$thermalUrl.'"', (string) $reprint);
        $this->assertStringNotContainsString('?change=', (string) $reprint);

        // Laluan checkout membawa `change` di `print_url`; halaman meneruskannya
        // ke tombol thermal supaya "Kembalian" ikut tercetak.
        $fromCheckout = $this->actingAs($cashier)
            ->get(route('pos.struk', ['sale' => $sale, 'auto' => 1, 'change' => 10_000]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-url="'.$thermalUrl.'?change=10000"', (string) $fromCheckout);
    }

    #[Test]
    public function another_cashiers_sale_is_not_there_at_all(): void
    {
        $cashier = $this->staff();
        $other = $this->staff();
        $sale = $this->sale($cashier);

        $this->actingAs($other)
            ->get(route('pos.struk', $sale))
            ->assertNotFound();
    }

    #[Test]
    public function the_owner_may_print_any_struk(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier);

        $this->actingAs($this->owner())
            ->get(route('pos.struk', $sale))
            ->assertOk()
            ->assertSee($sale->receipt_no);
    }

    #[Test]
    public function a_guest_cannot_reach_the_struk_page(): void
    {
        $sale = $this->sale($this->staff());

        $this->get(route('pos.struk', $sale))->assertRedirect(route('login'));
    }

    // ================= Endpoint byte thermal =================

    #[Test]
    public function the_thermal_endpoint_returns_esc_pos_bytes_for_a_sale(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier);

        $response = $this->actingAs($cashier)
            ->post(route('pos.struk.thermal', $sale))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('paper', '80mm')
            ->assertJsonPath('width', 48);

        $bytes = base64_decode((string) $response->json('bytesB64'), true);

        $this->assertNotFalse($bytes);
        $this->assertNotNull($bytes);
        $this->assertNotEmpty($bytes);
        // Semua sepaket ESC/POS mulai dari reset printer.
        $this->assertSame("\x1b", $bytes[0]);
        $this->assertStringContainsString($sale->receipt_no, $bytes);
    }

    #[Test]
    public function the_thermal_endpoint_follows_the_global_paper_setting(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier);

        Setting::set(PrintSettings::PAPER_KEY, PaperSize::Mm58->value);

        $this->actingAs($cashier)
            ->post(route('pos.struk.thermal', $sale))
            ->assertOk()
            ->assertJsonPath('paper', '58mm')
            ->assertJsonPath('width', 32);
    }

    #[Test]
    public function a4_is_refused_by_the_thermal_endpoint_instead_of_printing_garbage(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier);

        Setting::set(PrintSettings::PAPER_KEY, PaperSize::A4->value);

        $this->actingAs($cashier)
            ->post(route('pos.struk.thermal', $sale))
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);
    }

    #[Test]
    public function only_the_owner_and_the_cashier_can_fetch_thermal_bytes(): void
    {
        $cashier = $this->staff();
        $sale = $this->sale($cashier);

        $this->actingAs($this->staff())
            ->post(route('pos.struk.thermal', $sale))
            ->assertNotFound();

        $this->actingAs($this->owner())
            ->post(route('pos.struk.thermal', $sale))
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    #[Test]
    public function a_guest_cannot_fetch_thermal_bytes(): void
    {
        $sale = $this->sale($this->staff());

        $this->post(route('pos.struk.thermal', $sale))->assertRedirect(route('login'));
    }
}
