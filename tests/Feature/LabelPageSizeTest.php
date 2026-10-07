<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LabelPaperMode;
use App\Models\Setting;
use App\Models\User;
use App\Services\Label\LabelPrinterSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ukuran kertas untuk dialog cetak harus ikut template yang merender labelnya.
 *
 * Tanpa `@page`, browser memakai ukuran kertas terakhir yang dipilih pengguna
 * (biasanya A4). Label 3 x 2 cm tetap tercetak 3 x 2 cm -- yang bergeser hanya
 * posisinya di kertas -- jadi ukurannya "salah" tanpa symptom yang jelas, dan
 * operator tidak punya cara mengukur gauge printer dari hasilnya.
 *
 * Ini masuk akal hanya karena CSS cetak memutus satu label satu halaman
 * (`.label { break-after: page }`) untuk kertas continuous. Kalau aturan itu
 * dihapus, `@page` di sini ikut salah: seluruh sheet label akan mencoba muat di
 * satu halaman 3 x 2 cm.
 *
 * Kelas ini sengaja dipatok ke mode gulungan. Mode stiker punya ukuran halaman
 * yang lain -- ukuran media, bukan ukuran satu label -- dan diuji terpisah di
 * `LabelStickerSheetTest`. Kalau tidak dipatok, test di sini akan ikut hijau
 * atau ikut merah mengikuti mode bawaan tanpa memberitahu apa yang berubah.
 */
class LabelPageSizeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set(LabelPrinterSettings::PAPER_MODE_KEY, LabelPaperMode::Roll->value);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function templates(): array
    {
        return [
            '3x2' => ['3x2', '3cm', '2cm'],
            '4x3' => ['4x3', '4cm', '3cm'],
            '1.5x1.5' => ['1.5x1.5', '1.5cm', '1.5cm'],
        ];
    }

    #[Test]
    #[DataProvider('templates')]
    public function at_page_ikut_ukuran_template(string $template, string $width, string $height): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.cetak-label.test-print', ['template' => $template]))
            ->assertOk()
            ->assertSee('size: '.$width.' '.$height, false);
    }

    #[Test]
    public function margin_halaman_dimatikan_agar_tidak_menggeser_label(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.cetak-label.test-print'))
            ->assertOk()
            ->assertSee('margin: 0', false);
    }

    #[Test]
    public function ukuran_aktif_ditampilkan_di_layar(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.cetak-label.test-print'))
            ->assertOk()
            ->assertSee('Ukuran label aktif:')
            ->assertSee('3 x 2 cm');
    }

    #[Test]
    public function ukuran_aktif_mengikuti_template_yang_dipakai(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.cetak-label.test-print', ['template' => '4x3']))
            ->assertOk()
            ->assertSee('4 x 3 cm');
    }
}
