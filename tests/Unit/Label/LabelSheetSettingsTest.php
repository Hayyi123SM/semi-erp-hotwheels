<?php

declare(strict_types=1);

namespace Tests\Unit\Label;

use App\Services\Label\LabelSheetSettings;
use App\Services\Label\LabelTemplate;
use App\Services\Label\StickerSheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pengaturan kertas stiker: ukuran kertas, celah, dan batas cetak printer.
 *
 * Yang dijaga di sini ada dua lapis. Lapis pertama geometri: angka yang
 * disimpan harus menghasilkan grid yang sama persis dengan yang sudah
 * diverifikasi terhadap printer nyata -- 100 x 150 mm, celah 2 mm, label 15 x
 * 15 mm harus tetap 6 x 8 = 48 label. Lapis kedua adalah apa yang terjadi
 * pada angka yang tidak masuk akal, karena isi `Setting` tidak pernah bisa
 * diverifikasi dan pembacaannya tidak boleh melempar.
 */
class LabelSheetSettingsTest extends TestCase
{
    #[Test]
    public function the_defaults_reproduce_the_sheet_that_was_already_verified(): void
    {
        $sheet = LabelSheetSettings::default();

        $this->assertSame(100.0, $sheet->mediaWidthMm);
        $this->assertSame(150.0, $sheet->mediaHeightMm);
        $this->assertTrue($sheet->hasGap);
        $this->assertSame(2.0, $sheet->gapMm);
        $this->assertSame(108.0, $sheet->maxPrintWidthMm);

        $grid = $sheet->gridFor(LabelTemplate::QrOnly);

        $this->assertSame(6, $grid->columns);
        $this->assertSame(8, $grid->rows);
        $this->assertSame(48, $grid->labelsPerSheet());
        $this->assertEqualsWithDelta(16.0, $grid->slackHeightMm, 0.0001);
    }

    /**
     * Ukuran label tetap dari preset, bukan dari angka Owner.
     *
     * Ini yang menjaga isi label tetap muat: font, padding, dan QR terikat ke
     * ukuran fisiknya, jadi 1,5 x 1,5 cm tidak boleh jadi 2 x 2 cm hanya
     * karena Owner sengaja mengetik angka yang lebih besar.
     */
    #[Test]
    public function the_label_size_always_comes_from_the_preset(): void
    {
        $sheet = LabelSheetSettings::default();

        $expected = [
            '1.5x1.5' => [15.0, 15.0],
            '3x2' => [30.0, 20.0],
            '4x3' => [40.0, 30.0],
        ];

        foreach (LabelTemplate::cases() as $template) {
            $grid = $sheet->gridFor($template);
            [$widthMm, $heightMm] = $expected[$template->value];

            $this->assertSame($widthMm, $grid->labelWidthMm, "lebar label {$template->value}");
            $this->assertSame($heightMm, $grid->labelHeightMm, "tinggi label {$template->value}");
        }
    }

    /**
     * Ukuran label berbeda memberi jumlah label berbeda pada kertas yang sama.
     *
     * Angka kolom dan baris di sini hasil hitung tangan dari 100 x 150 mm dan
     * celah 2 mm, bukan hasil kalkulator yang sedang diuji: 102/32 = 3,19 -> 3
     * kolom untuk label 3 x 2 cm, dan 152/22 = 6,90 -> 6 baris.
     *
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function templatePresets(): array
    {
        return [
            '1,5 x 1,5 cm' => ['1.5x1.5', 6, 8],
            '3 x 2 cm' => ['3x2', 3, 6],
            '4 x 3 cm' => ['4x3', 2, 4],
        ];
    }

    #[Test]
    #[DataProvider('templatePresets')]
    public function each_label_preset_gets_its_own_grid_on_the_same_paper(
        string $template,
        int $columns,
        int $rows,
    ): void {
        $grid = LabelSheetSettings::default()->gridFor(LabelTemplate::from($template));

        $this->assertSame($columns, $grid->columns);
        $this->assertSame($rows, $grid->rows);
        $this->assertSame([], $grid->rejections);
        $this->assertTrue($grid->isPrintable());
    }

    /**
     * Mematikan celah membuat grid rapat, bukan memakai angka lama.
     *
     * Ini bugs yang paling mungkin terjadi di langkah berikutnya: centang
     * dimatikan, angka 2 mm masih tersimpan, dan kalau pembacaan mengambil
     * angka itu saja grid tidak berubah sama sekali -- Owner menekan simpan
     * dan tidak melihat apa pun bergerak.
     */
    #[Test]
    public function switching_the_gap_off_makes_the_grid_tight(): void
    {
        $withGap = LabelSheetSettings::default();
        $withoutGap = new LabelSheetSettings(
            mediaWidthMm: 100.0,
            mediaHeightMm: 150.0,
            hasGap: false,
            gapMm: 2.0,
            maxPrintWidthMm: 108.0,
        );

        $this->assertSame(2.0, $withGap->effectiveGapMm());
        $this->assertSame(0.0, $withoutGap->effectiveGapMm());

        $grid = $withoutGap->gridFor(LabelTemplate::QrOnly);

        $this->assertSame(6, $grid->columns, 'tanpa celah kolom tetap 6');
        $this->assertSame(10, $grid->rows, 'tanpa celah baris naik dari 8 ke 10');
        $this->assertSame(60, $grid->labelsPerSheet());
        $this->assertSame(0.0, $grid->gapMm);
    }

    #[Test]
    public function a_grid_that_fits_produces_no_rejections_at_all(): void
    {
        $sheet = LabelSheetSettings::default();

        foreach (LabelTemplate::cases() as $template) {
            $this->assertSame([], $sheet->rejectionsFor($template), $template->label());
            $this->assertNotNull($sheet->usableGridFor($template), $template->label());
        }
    }

    /**
     * Kertas yang lebih lebar dari area cetak printer ditolak.
     *
     * Kertas 118 mm masih bisa dimuat ke printer, tapi hanya 108 mm yang bisa
     * dicetak. Kalau ini lolos, kolom paling kanan keluar terpotong dan tidak
     * ada yang mengukurnya sampai Owner complain stiker paling kanan rusak.
     */
    #[Test]
    public function paper_wider_than_the_print_area_is_rejected(): void
    {
        $sheet = new LabelSheetSettings(
            mediaWidthMm: 120.0,
            mediaHeightMm: 150.0,
            hasGap: true,
            gapMm: 2.0,
            maxPrintWidthMm: 108.0,
        );

        $this->assertNotNull($sheet->gridFor(LabelTemplate::QrOnly), 'grid-nya sendiri tetap bisa dihitung');
        $this->assertNull($sheet->usableGridFor(LabelTemplate::QrOnly), 'tapi tidak boleh ikut dicetak');

        $rejections = $sheet->rejectionsFor(LabelTemplate::QrOnly);

        $this->assertStringContainsString('Kertas 120 mm lebih lebar dari area cetak printer 108 mm', implode(' ', $rejections));
    }

    #[Test]
    public function paper_exactly_at_the_print_limit_is_accepted(): void
    {
        $sheet = new LabelSheetSettings(
            mediaWidthMm: 108.0,
            mediaHeightMm: 150.0,
            hasGap: true,
            gapMm: 2.0,
            maxPrintWidthMm: 108.0,
        );

        $this->assertSame([], $sheet->rejectionsFor(LabelTemplate::QrOnly));
    }

    /**
     * Penolakan geometri ikut masuk ke daftar yang sama.
     *
     * Kalau hanya batas printer yang diperiksa, kertas 100 x 10 mm akan lolos ke
     * printer padahal tidak ada satu pun label yang muat ke tingginya.
     */
    #[Test]
    public function geometry_rejections_are_reported_alongside_the_printer_limit(): void
    {
        $sheet = new LabelSheetSettings(
            mediaWidthMm: 100.0,
            mediaHeightMm: 10.0,
            hasGap: true,
            gapMm: 2.0,
            maxPrintWidthMm: 108.0,
        );

        $rejections = $sheet->rejectionsFor(LabelTemplate::QrOnly);

        $this->assertNotSame([], $rejections);
        $this->assertNull($sheet->usableGridFor(LabelTemplate::QrOnly));
    }

    /**
     * Blueprint lama diterjemahkan ke ukuran kertas dengan angka yang sama.
     *
     * Inilah yang membuat transisi tidak mengubah hasil cetak: Owner yang
     * sudah memakai kertas 100 x 150 mm dengan celah 2 mm harus mendapat grid
     * yang persis sama setelah key baru dipakai.
     */
    #[Test]
    public function a_legacy_blueprint_translates_to_the_same_numbers(): void
    {
        $sheet = LabelSheetSettings::fromBlueprint(StickerSheet::BpTd110BtA6);

        $this->assertSame(100.0, $sheet->mediaWidthMm);
        $this->assertSame(150.0, $sheet->mediaHeightMm);
        $this->assertTrue($sheet->hasGap);
        $this->assertSame(2.0, $sheet->gapMm);

        $this->assertEquals(
            LabelSheetSettings::default()->gridFor(LabelTemplate::QrOnly)->labelsPerSheet(),
            $sheet->gridFor(LabelTemplate::QrOnly)->labelsPerSheet(),
        );
    }

    #[Test]
    public function the_default_printer_limit_comes_from_the_verified_printer(): void
    {
        $this->assertSame(
            LabelSheetSettings::DEFAULT_MAX_PRINT_WIDTH_MM,
            LabelSheetSettings::fromBlueprint(StickerSheet::BpTd110BtA6)->maxPrintWidthMm,
        );
    }

    /**
     * Kertas yang jauh lebih besar dari kertas bawaan tetap bisa dihitung.
     *
     * Batas atas kertas sengaja tidak ditegakkan di sini: yang ditolak adalah
     * lebar yang melebihi area cetak printer, bukan kertas A4 yang tidak
     * pernah dipakai. Menolak kertas besar berarti Owner tidak bisa menyiapkan
     * printer yang lebih lebar tanpa menunggu kode diubah.
     */
    #[Test]
    public function large_paper_is_not_rejected_when_the_printer_can_handle_it(): void
    {
        $sheet = new LabelSheetSettings(
            mediaWidthMm: 210.0,
            mediaHeightMm: 297.0,
            hasGap: true,
            gapMm: 2.0,
            maxPrintWidthMm: 216.0,
        );

        $this->assertSame([], $sheet->rejectionsFor(LabelTemplate::QrOnly));
        $this->assertNotNull($sheet->usableGridFor(LabelTemplate::QrOnly));
    }
}
