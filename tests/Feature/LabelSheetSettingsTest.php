<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Label\LabelPrinterSettings;
use App\Services\Label\LabelSheetSettings;
use App\Services\Label\LabelTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pembacaan ukuran kertas stiker dari tabel `settings`.
 *
 * Yang dijaga di sini adalah apa yang terjadi pada data yang sudah ada di
 * instalasi nyata, bukan hanya pada simpanan baru:
 *
 * - Instalasi yang sudah memilih kertas stiker lama hanya punya
 *   `label.sticker_sheet`, dan harus tetap mendapat grid yang sama.
 * - `Setting` menyimpan JSON, jadi angka Owner masuk sebagai teks. Pembacaan
 *   harus mengubahnya jadi angka, bukan membandingkan teks.
 * - Nilai rusak tidak boleh membuat halaman Pengaturan atau halaman cetak
 *   gagal dimuat. Yang terjadi harus begini: angka itu diabaikan, bawaannya
 *   dipakai, dan kejadiannya tercatat di log.
 *
 * Simpanan lewat form bukan diuji di sini. Yang diuji adalah apa yang terjadi
 * setelah angka menyentuh tabel -- di situlah bentuk datanya berubah.
 */
class LabelSheetSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_install_without_any_stored_setting_gets_the_verified_defaults(): void
    {
        $sheet = $this->printer()->sheet();

        $this->assertSame(100.0, $sheet->mediaWidthMm);
        $this->assertSame(150.0, $sheet->mediaHeightMm);
        $this->assertTrue($sheet->hasGap);
        $this->assertSame(2.0, $sheet->gapMm);
        $this->assertSame(48, $sheet->gridFor(LabelTemplate::QrOnly)->labelsPerSheet());
    }

    /**
     * Angka Owner disimpan sebagai teks dan dibaca kembali sebagai angka.
     *
     * `Setting` memakai JSON, dan Owner mengetik angka ke input number. Yang
     * sampai ke database adalah string, jadi pembacaan yang membandingkan
     * dengan `===` akan selalu gagal dan diam-diam jatuh ke bawaan.
     *
     * @param  array<string, string>  $stored
     */
    #[Test]
    #[DataProvider('storedSheetSettings')]
    public function stored_numbers_are_read_as_numbers(array $stored, float $mediaWidthMm, float $mediaHeightMm, bool $hasGap, float $gapMm): void
    {
        foreach ($stored as $key => $value) {
            Setting::set($key, $value);
        }

        $sheet = $this->printer()->sheet();

        $this->assertSame($mediaWidthMm, $sheet->mediaWidthMm, 'lebar kertas');
        $this->assertSame($mediaHeightMm, $sheet->mediaHeightMm, 'tinggi kertas');
        $this->assertSame($hasGap, $sheet->hasGap, 'centang celah');
        $this->assertSame($gapMm, $sheet->gapMm, 'angka celah');
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: float, 2: float, 3: bool, 4: float}>
     */
    public static function storedSheetSettings(): array
    {
        $keys = [
            LabelPrinterSettings::SHEET_MEDIA_WIDTH_MM_KEY,
            LabelPrinterSettings::SHEET_MEDIA_HEIGHT_MM_KEY,
            LabelPrinterSettings::SHEET_HAS_GAP_KEY,
            LabelPrinterSettings::SHEET_GAP_MM_KEY,
        ];

        return [
            'teks dari input form' => [
                array_combine($keys, ['100.0', '150.0', '1', '2.0']),
                100.0,
                150.0,
                true,
                2.0,
            ],
            'kertas lain' => [
                array_combine($keys, ['70.0', '30.0', '1', '1.5']),
                70.0,
                30.0,
                true,
                1.5,
            ],
            'celah dimatikan' => [
                array_combine($keys, ['100.0', '150.0', '0', '2.0']),
                100.0,
                150.0,
                false,
                2.0,
            ],
            'nol desimal tidak lengkap' => [
                array_combine($keys, ['70', '30', '1', '1.5']),
                70.0,
                30.0,
                true,
                1.5,
            ],
        ];
    }

    #[Test]
    public function the_grid_from_stored_numbers_is_the_same_as_from_numbers_in_code(): void
    {
        Setting::set(LabelPrinterSettings::SHEET_MEDIA_WIDTH_MM_KEY, '100.0');
        Setting::set(LabelPrinterSettings::SHEET_MEDIA_HEIGHT_MM_KEY, '150.0');
        Setting::set(LabelPrinterSettings::SHEET_HAS_GAP_KEY, '1');
        Setting::set(LabelPrinterSettings::SHEET_GAP_MM_KEY, '2.0');

        $fromDatabase = $this->printer()->sheet()->gridFor(LabelTemplate::QrOnly);
        $fromCode = LabelSheetSettings::default()->gridFor(LabelTemplate::QrOnly);

        $this->assertSame($fromCode->columns, $fromDatabase->columns);
        $this->assertSame($fromCode->rows, $fromDatabase->rows);
        $this->assertSame($fromCode->labelsPerSheet(), $fromDatabase->labelsPerSheet());
        $this->assertSame($fromCode->gridStyle(), $fromDatabase->gridStyle());
    }

    /**
     * Lebar cetak printer juga dibaca dari penyimpanan.
     *
     * Nilai ini bukan sekadar bawaan: kertas yang lebih lebar dari area cetak
     * akan memotong kolom paling kanan, dan itu baru terlihat setelah Owner
     * mencetak.
     */
    #[Test]
    public function the_print_limit_is_read_from_storage(): void
    {
        Setting::set(LabelPrinterSettings::MAX_PRINT_WIDTH_MM_KEY, '58.0');

        $sheet = new LabelSheetSettings(
            mediaWidthMm: 60.0,
            mediaHeightMm: 30.0,
            hasGap: true,
            gapMm: 2.0,
            maxPrintWidthMm: $this->printer()->sheet()->maxPrintWidthMm,
        );

        $this->assertSame(58.0, $sheet->maxPrintWidthMm);
        $this->assertNotSame([], $sheet->rejectionsFor(LabelTemplate::QrOnly));
    }

    /**
     * Instalasi lama dengan blueprint tersimpan harus dapat hasil yang sama.
     *
     * Inilah transisi yang paling berisiko: kalau angka hasil terjemahan tidak
     * sama dengan blueprint lama, jumlah label per halaman berubah diam-diam
     * tepat setelah fitur ini dipakai.
     */
    #[Test]
    public function an_install_with_the_legacy_blueprint_keeps_its_grid(): void
    {
        Setting::set(LabelPrinterSettings::PAPER_MODE_KEY, 'sheet');
        Setting::set(LabelPrinterSettings::STICKER_SHEET_KEY, 'bp-td110bt-100x150');

        $sheet = $this->printer()->sheet();

        $this->assertSame(100.0, $sheet->mediaWidthMm);
        $this->assertSame(150.0, $sheet->mediaHeightMm);
        $this->assertTrue($sheet->hasGap);
        $this->assertSame(2.0, $sheet->gapMm);

        $grid = $sheet->gridFor(LabelTemplate::QrOnly);

        $this->assertSame(6, $grid->columns);
        $this->assertSame(8, $grid->rows);
        $this->assertSame(48, $grid->labelsPerSheet());
    }

    /**
     * Key baru mengalahkan blueprint lama begitu Owner menyimpannya.
     *
     * Kalau tidak, blueprint lama akan selalu menang dan Owner tidak akan pernah
     * bisa mengganti ukuran kertasnya.
     */
    #[Test]
    public function the_new_keys_win_over_the_legacy_blueprint(): void
    {
        Setting::set(LabelPrinterSettings::STICKER_SHEET_KEY, 'bp-td110bt-100x150');
        Setting::set(LabelPrinterSettings::SHEET_MEDIA_WIDTH_MM_KEY, '70.0');
        Setting::set(LabelPrinterSettings::SHEET_MEDIA_HEIGHT_MM_KEY, '30.0');

        $sheet = $this->printer()->sheet();

        $this->assertSame(70.0, $sheet->mediaWidthMm);
        $this->assertSame(30.0, $sheet->mediaHeightMm);
    }

    /**
     * Key baru yang tersimpan sebagian tetap dipakai.
     *
     * Instalasi bisa punya key baru sebagian -- hanya tinggi kertas yang
     * terisi, misalnya. Kalau pembacaannya memakai "kalau semua key ada baru
     * pakai", Owner akan kehilangan lebar kertas yang sudah benar.
     */
    #[Test]
    public function a_partially_stored_sheet_keeps_the_defaults_for_the_rest(): void
    {
        Setting::set(LabelPrinterSettings::SHEET_MEDIA_HEIGHT_MM_KEY, '30.0');

        $sheet = $this->printer()->sheet();

        $this->assertSame(30.0, $sheet->mediaHeightMm);
        $this->assertSame(100.0, $sheet->mediaWidthMm);
        $this->assertTrue($sheet->hasGap);
        $this->assertSame(2.0, $sheet->gapMm);
    }

    /**
     * Angka yang tidak bisa dibaca menghasilkan bawaan, bukan halaman gagal.
     *
     * @param  array<string, mixed>  $stored
     */
    #[Test]
    #[DataProvider('unreadableSettings')]
    public function an_unreadable_number_falls_back_without_throwing(string $key, mixed $stored): void
    {
        Setting::set($key, $stored);

        $sheet = $this->printer()->sheet();

        $this->assertNotSame('', (string) $sheet->mediaWidthMm);
        $this->assertGreaterThan(0.0, $sheet->mediaWidthMm);
        $this->assertGreaterThan(0.0, $sheet->mediaHeightMm);
        $this->assertSame(48, $sheet->gridFor(LabelTemplate::QrOnly)->labelsPerSheet());
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function unreadableSettings(): array
    {
        return [
            'kata-kata' => [LabelPrinterSettings::SHEET_MEDIA_WIDTH_MM_KEY, 'seratus'],
            'string kosong' => [LabelPrinterSettings::SHEET_MEDIA_WIDTH_MM_KEY, ''],
            'nol' => [LabelPrinterSettings::SHEET_MEDIA_WIDTH_MM_KEY, '0'],
            'negatif' => [LabelPrinterSettings::SHEET_MEDIA_WIDTH_MM_KEY, '-100'],
            'boolean' => [LabelPrinterSettings::SHEET_MEDIA_HEIGHT_MM_KEY, true],
            'array' => [LabelPrinterSettings::SHEET_GAP_MM_KEY, ['2']],
            'centang bukan boolean' => [LabelPrinterSettings::SHEET_HAS_GAP_KEY, 'mungkin'],
        ];
    }

    /**
     * Nilai yang ditolak harus tercatat, bukan hilang diam-diam.
     *
     * Tanpa log, Owner hanya melihat grid bawaan yang tidak pernah ia pilih dan
     * tidak punya cara mencari tahu kenapa angkanya tidak dipakai.
     */
    #[Test]
    public function a_rejected_value_is_logged(): void
    {
        Log::spy();

        Setting::set(LabelPrinterSettings::SHEET_MEDIA_WIDTH_MM_KEY, 'seratus');

        $this->printer()->sheet();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'tidak bisa dibaca')
                && ($context['key'] ?? null) === LabelPrinterSettings::SHEET_MEDIA_WIDTH_MM_KEY);
    }

    /**
     * Nilai yang benar tidak boleh membanjiri log.
     *
     * `sheet()` dipanggil di setiap render label, jadi log untuk setiap
     * pembacaan yang wajar akan mengisi ribuan baris log dengan pesan yang
     * sebenarnya cuma informasi.
     */
    #[Test]
    public function valid_values_are_not_logged_as_problems(): void
    {
        Log::spy();

        Setting::set(LabelPrinterSettings::SHEET_MEDIA_WIDTH_MM_KEY, '100.0');
        Setting::set(LabelPrinterSettings::SHEET_MEDIA_HEIGHT_MM_KEY, '150.0');
        Setting::set(LabelPrinterSettings::SHEET_HAS_GAP_KEY, '1');
        Setting::set(LabelPrinterSettings::SHEET_GAP_MM_KEY, '2.0');

        $this->printer()->sheet();

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Nilai mm dibulatkan ke tiga desimal.
     *
     * Sisa pecahan dari aritmetika float akan memunculkan peringatan "bukan
     * kelipatan 1 dot" untuk selisih yang mustahil terlihat di printer, jadi
     * pembacaan harus membersihkannya di sini, bukan kalkulator berikutnya.
     */
    #[Test]
    public function millimetres_are_rounded_to_three_decimals(): void
    {
        Setting::set(LabelPrinterSettings::SHEET_MEDIA_WIDTH_MM_KEY, '99.99999999999');

        $sheet = $this->printer()->sheet();

        $this->assertSame(100.0, $sheet->mediaWidthMm);
        $this->assertSame([], $sheet->gridFor(LabelTemplate::QrOnly)->warnings);
    }

    private function printer(): LabelPrinterSettings
    {
        return new LabelPrinterSettings;
    }
}
