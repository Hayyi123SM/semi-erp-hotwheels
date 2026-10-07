<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaperSize;
use App\Enums\PrintMethod;
use App\Models\Setting;
use App\Services\Print\PrintSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sumber tunggal kertas dan cara cetak.
 *
 * Sebelumnya kertas punya dua rumah (`receipt.paper` dan `pos.receipt_paper`)
 * yang bisa saling bertentangan -- struk kasir dan bukti terima tidak pernah
 * berdebat lewat UI, tapi lewat database. Kelas ini menutup celah itu: kertas
 * tinggal satu di `print.paper`, dan `print.method` menentukan alur cetaknya.
 */
class PrintSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function nothing_saved_means_browser_printing_and_80mm_paper(): void
    {
        $settings = app(PrintSettings::class);

        $this->assertSame(PrintMethod::Browser, $settings->method());
        $this->assertSame(PaperSize::Mm80, $settings->paper());
        $this->assertNull($settings->storedMethod());
        $this->assertNull($settings->storedPaper());
    }

    #[Test]
    public function stored_values_are_what_are_read_back(): void
    {
        Setting::set(PrintSettings::PAPER_KEY, PaperSize::Mm58->value);
        Setting::set(PrintSettings::METHOD_KEY, PrintMethod::Thermal->value);

        $this->assertSame(PaperSize::Mm58, app(PrintSettings::class)->paper());
        $this->assertSame(PrintMethod::Thermal, app(PrintSettings::class)->method());
    }

    #[Test]
    public function the_global_paper_wins_over_either_legacy_key(): void
    {
        Setting::set(PrintSettings::LEGACY_RECEIPT_PAPER_KEY, '58mm');
        Setting::set(PrintSettings::LEGACY_POS_PAPER_KEY, 'a4');
        Setting::set(PrintSettings::PAPER_KEY, '80mm');

        $this->assertSame(PaperSize::Mm80, app(PrintSettings::class)->paper());
    }

    #[Test]
    public function the_legacy_keys_stay_valid_fallbacks_until_the_global_is_saved(): void
    {
        // Instalasi lama menyimpan di `receipt.paper` (bukti terima) atau
        // `pos.receipt_paper` (struk POS). Keduanya tetap dibaca supaya toko
        // yang baru upgrade tidak kehilangan pengaturannya. Kertas bukti terima
        // diutamakan -- itulah setelan yang selama ini paling sering dipegang.
        Setting::set(PrintSettings::LEGACY_RECEIPT_PAPER_KEY, '58mm');

        $this->assertSame(PaperSize::Mm58, app(PrintSettings::class)->paper());

        // Begitu yang lama tak terbaca (atau kosong), `pos.receipt_paper`
        // yang dijadikan cadangan.
        Setting::set(PrintSettings::LEGACY_RECEIPT_PAPER_KEY, 'roll-thermal');
        Setting::set(PrintSettings::LEGACY_POS_PAPER_KEY, 'a4');

        $this->assertSame(PaperSize::A4, app(PrintSettings::class)->paper());
    }

    #[Test]
    public function stored_paper_ignores_legacy_keys_so_the_form_shows_the_truth(): void
    {
        // `storedPaper()` hanya melihat key global. Menganggap legacy sebagai
        // "tersimpan" akan membuat form Perangkat menampilkan pilihan yang
        // belum pernah dipilih Owner di tempat itu.
        Setting::set(PrintSettings::LEGACY_RECEIPT_PAPER_KEY, 'a4');

        $this->assertNull(app(PrintSettings::class)->storedPaper());
        $this->assertSame(PaperSize::A4, app(PrintSettings::class)->paper());
    }

    #[Test]
    public function unreadable_stored_values_fall_back_instead_of_throwing(): void
    {
        Setting::set(PrintSettings::PAPER_KEY, 'roll-thermal');
        Setting::set(PrintSettings::METHOD_KEY, 'kirim-saja');

        $this->assertSame(PaperSize::Mm80, app(PrintSettings::class)->paper());
        $this->assertSame(PrintMethod::Browser, app(PrintSettings::class)->method());
        $this->assertNull(app(PrintSettings::class)->storedPaper());
        $this->assertNull(app(PrintSettings::class)->storedMethod());
    }
}
