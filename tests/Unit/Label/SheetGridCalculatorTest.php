<?php

declare(strict_types=1);

namespace Tests\Unit\Label;

use App\Services\Label\SheetGrid;
use App\Services\Label\SheetGridCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Perhitungan grid label di atas kertas.
 *
 * Yang dikunci di sini adalah ANGKA, bukan hanya konsistensi internal. Test
 * yang hanya memeriksa bahwa `used == columns * label + (columns - 1) * gap`
 * akan tetap hijau kalau rumusnya salah, karena kedua sisi memakai rumus yang
 * sama. Yang berguna ditahan di sini adalah hubungannya dengan fisiknya:
 * kertas 100 x 150 mm dengan label 15 x 15 mm dan celah 2 mm harus jadi
 * 6 kolom x 8 baris, dan 6 x 15 + 5 x 2 harus sama dengan 100.
 *
 * Angka acuan diambil dari kertas stiker yang sungguhan dipakai: 100 x 150 mm,
 * label 15 x 15 mm, celah 2 mm. Kalau rumus atau parameternya diubah, test
 * ini gagal -- dan itu memang tujuannya, karena grid yang "benar secara
 * matematika tapi salah cetak" tidak bisa dibuktikan dari kode.
 */
class SheetGridCalculatorTest extends TestCase
{
    /**
     * Tabel acuan untuk kertas 100 x 150 mm dengan label 15 x 15 mm.
     *
     * Kolom selalu 6 untuk celah 0 sampai 2 mm dan turun ke 5 di celah 3 mm,
     * karena 6 x 15 + 5 x 2 = 100 mm pas -- begitu celah naik, kertas tidak
     * cukup lagi untuk enam label dan satu kolom hilang.
     *
     * Sisa horizontal di celah 0 dan 1 mm sengaja ikut dikunci. Pada celah 0
     * kertas 100 mm memuat 6 label 15 mm dan menyisakan 10 mm; pada celah 1 mm
     * menyisakan 5 mm. Keduanya grid yang sah, dan keduanya tidak bisa
     * dianggap "salah ketik" -- makanya tidak ada penolakan, hanya peringatan.
     *
     * @return array<string, array{0: float, 1: int, 2: int, 3: int, 4: float, 5: float}>
     */
    public static function referenceSheet(): array
    {
        return [
            'tanpa celah' => [0.0, 6, 10, 60, 10.0, 0.0],
            'celah 1 mm' => [1.0, 6, 9, 54, 5.0, 7.0],
            'celah 2 mm' => [2.0, 6, 8, 48, 0.0, 16.0],
            'celah 3 mm' => [3.0, 5, 8, 40, 13.0, 9.0],
            'celah 5 mm' => [5.0, 5, 7, 35, 5.0, 15.0],
        ];
    }

    #[Test]
    #[DataProvider('referenceSheet')]
    public function the_reference_sheet_matches_its_acuan(
        float $gapMm,
        int $columns,
        int $rows,
        int $labels,
        float $slackWidthMm,
        float $slackHeightMm,
    ): void {
        $grid = SheetGridCalculator::calculate(100.0, 150.0, 15.0, 15.0, $gapMm);

        $this->assertSame($columns, $grid->columns, 'jumlah kolom');
        $this->assertSame($rows, $grid->rows, 'jumlah baris');
        $this->assertSame($labels, $grid->labelsPerSheet(), 'jumlah label per lembar');
        $this->assertEqualsWithDelta($slackWidthMm, $grid->slackWidthMm, 0.0001, 'sisa lebar');
        $this->assertEqualsWithDelta($slackHeightMm, $grid->slackHeightMm, 0.0001, 'sisa tinggi');
    }

    /**
     * Kasus yang jadi acuan seluruh fitur: 6 x 15 mm + 5 x 2 mm = 100 mm.
     *
     * Ini yang membuat blueprint lama tidak bisa ditulis ulang diam-diam.
     * Kalau kolom atau celah berubah sehingga ada sisa horizontal, deret label
     * tidak lagi menutupi lebar kertas dan label bisa bergeser dari kolom
     * blueprint aslinya.
     */
    #[Test]
    public function six_columns_and_five_gaps_fill_the_sheet_width_exactly(): void
    {
        $grid = SheetGridCalculator::calculate(100.0, 150.0, 15.0, 15.0, 2.0);

        $this->assertSame(6, $grid->columns);
        $this->assertEqualsWithDelta(100.0, $grid->usedWidthMm, 0.0001);
        $this->assertEqualsWithDelta(0.0, $grid->slackWidthMm, 0.0001);
    }

    /**
     * Sisa tidak pernah negatif, untuk semua kombinasi yang masuk akal.
     *
     * Kalau sisa bisa negatif, grid menimpa sendiri: label ke-`n+1` dimulai
     * sebelum label ke-`n` selesai, dan hasilnya bukan "bergeser sedikit" tapi
     * dua label tercetak di atas area yang sama. Rumusnya sudah menjamin ini
     * secara matematis, jadi test ini menahan kalau suatu saat ada yang menulis
     * ulang `count()` tanpa `+ gap` di pembaginya.
     */
    #[Test]
    public function the_slack_is_never_negative(): void
    {
        foreach ([0.0, 0.5, 1.0, 2.0, 3.0, 5.0] as $gapMm) {
            foreach ([100.0, 150.0, 210.0, 297.0] as $mediaWidthMm) {
                foreach ([100.0, 150.0, 297.0] as $mediaHeightMm) {
                    foreach ([10.0, 15.0, 20.0, 30.0] as $labelMm) {
                        $grid = SheetGridCalculator::calculate(
                            $mediaWidthMm,
                            $mediaHeightMm,
                            $labelMm,
                            $labelMm,
                            $gapMm,
                        );

                        $this->assertGreaterThanOrEqual(
                            0.0,
                            $grid->slackWidthMm,
                            sprintf('sisa lebar negatif pada gap %s, kertas %sx%s, label %s', $gapMm, $mediaWidthMm, $mediaHeightMm, $labelMm),
                        );
                        $this->assertGreaterThanOrEqual(
                            0.0,
                            $grid->slackHeightMm,
                            sprintf('sisa tinggi negatif pada gap %s, kertas %sx%s, label %s', $gapMm, $mediaWidthMm, $mediaHeightMm, $labelMm),
                        );
                    }
                }
            }
        }
    }

    /**
     * Grid yang tidak bisa dicetak tidak boleh melaporkan label per lembar
     * yang tidak nol.
     *
     * Ini yang mencegah `LabelPage` menghitung halaman dari grid yang ditolak:
     * jumlah halaman harus turun, bukan ikut dikalikan label yang tidak pernah
     * keluar dari printer.
     */
    #[Test]
    public function an_unprintable_grid_reports_no_labels_at_all(): void
    {
        // Label lebih lebar dan lebih tinggi dari kertasnya.
        $grid = SheetGridCalculator::calculate(30.0, 40.0, 100.0, 150.0, 2.0);

        $this->assertFalse($grid->isPrintable());
        $this->assertSame(0, $grid->columns);
        $this->assertSame(0, $grid->rows);
        $this->assertSame(0, $grid->labelsPerSheet());
        $this->assertNotEmpty($grid->rejections);
    }

    /**
     * Label yang lebih lebar dari kertas ditolak dengan menyebut kedua ukuran.
     *
     * Pesannya harus menyebut angkanya, bukan hanya "kombinasi tidak valid" --
     * Owner tidak punya cara lain untuk tahu harus ganti yang mana.
     */
    #[Test]
    public function a_label_wider_than_the_paper_is_rejected_with_both_sizes(): void
    {
        $grid = SheetGridCalculator::calculate(30.0, 150.0, 40.0, 20.0, 2.0);

        $this->assertFalse($grid->isPrintable());
        $this->assertStringContainsString('Label selebar 40 mm tidak muat di kertas selebar 30 mm.', implode(' ', $grid->rejections));
        $this->assertSame(0, $grid->columns);
        // Tingginya muat, jadi hanya lebar yang jadi alasan.
        $this->assertSame(6, $grid->rows);
    }

    #[Test]
    public function a_label_taller_than_the_paper_is_rejected_with_both_sizes(): void
    {
        $grid = SheetGridCalculator::calculate(100.0, 20.0, 15.0, 40.0, 2.0);

        $this->assertFalse($grid->isPrintable());
        $this->assertStringContainsString('Label setinggi 40 mm tidak muat di kertas setinggi 20 mm.', implode(' ', $grid->rejections));
    }

    /**
     * Celah yang lebih besar dari labelnya sendiri tidak pernah ada di toko.
     *
     * Grid seperti itu secara matematika bisa dihitung dan kelihatan masuk akal,
     * masuk akal, jadi kalau hanya "bisa dihitung" yang diperiksa, simulator
     * akan melaporkan grid ini layak cetak untuk kombinasi yang tidak mungkin.
     */
    #[Test]
    public function a_gap_wider_than_the_label_is_rejected(): void
    {
        $grid = SheetGridCalculator::calculate(100.0, 150.0, 15.0, 15.0, 20.0);

        $this->assertFalse($grid->isPrintable());
        $this->assertStringContainsString('Celah 20 mm tidak mungkin', implode(' ', $grid->rejections));
        $this->assertSame(0, $grid->labelsPerSheet());
    }

    #[Test]
    public function a_zero_or_negative_size_is_rejected_without_dividing_by_zero(): void
    {
        foreach ([[0.0, 150.0, 15.0], [-5.0, 150.0, 15.0], [100.0, 0.0, 15.0], [100.0, 150.0, 0.0]] as [$mw, $mh, $lw]) {
            $grid = SheetGridCalculator::calculate($mw, $mh, $lw, $lw, 2.0);

            $this->assertFalse($grid->isPrintable());
            $this->assertNotEmpty($grid->rejections);
            $this->assertSame(0, $grid->labelsPerSheet());
        }
    }

    #[Test]
    public function a_negative_gap_is_rejected(): void
    {
        $grid = SheetGridCalculator::calculate(100.0, 150.0, 15.0, 15.0, -1.0);

        $this->assertFalse($grid->isPrintable());
        $this->assertStringContainsString('Celah tidak boleh negatif.', implode(' ', $grid->rejections));
    }

    /**
     * Sisa horizontal yang besar memberi peringatan, tapi tidak diblokir.
     *
     * Kertas 100 x 150 mm dengan label 15 mm dan tanpa celah memang menyisakan
     * 10 mm di kanan, dan itu bukan kesalahan -- ada sheet die-cut yang seperti
     * itu. Menolaknya akan membuat Owner tidak bisa menyimpan konfigurasi yang
     * sebenarnya benar.
     */
    #[Test]
    public function a_large_horizontal_slack_warns_without_blocking(): void
    {
        $grid = SheetGridCalculator::calculate(100.0, 150.0, 15.0, 15.0, 0.0);

        $this->assertTrue($grid->isPrintable(), 'sisa horizontal tidak boleh memblokir penyimpanan');
        $this->assertSame(60, $grid->labelsPerSheet());
        $this->assertStringContainsString('Sisa lebar 10 mm', implode(' ', $grid->warnings));
    }

    /**
     * Kasus acuan tidak boleh berwarning: sisanya nol dan semua angkanya
     * kelipatan 0,125 mm.
     */
    #[Test]
    public function the_reference_sheet_produces_no_warnings_at_all(): void
    {
        $grid = SheetGridCalculator::calculate(100.0, 150.0, 15.0, 15.0, 2.0);

        $this->assertSame([], $grid->warnings);
        $this->assertSame([], $grid->rejections);
        $this->assertTrue($grid->isPrintable());
    }

    /**
     * Sisa vertikal yang besar bukan peringatan.
     *
     * 16 mm sisa bawah pada kasus acuan itu ruang untuk kertas keluar dari
     * printer. Menandainya mencurigakan akan.train Owner mengabaikan
     * peringatan yang benar.
     */
    #[Test]
    public function a_large_vertical_slack_does_not_warn(): void
    {
        $grid = SheetGridCalculator::calculate(100.0, 150.0, 15.0, 15.0, 2.0);

        $this->assertEqualsWithDelta(16.0, $grid->slackHeightMm, 0.0001);
        $this->assertSame([], $grid->warnings);
    }

    /**
     * Angka yang tidak bisa digambar persis oleh printer 203 dpi diperingatkan.
     *
     * Satu dot = 0,125 mm, jadi 99,9 mm tidak akan pernah tercetak persis dan
     * printer akan membulatkan. Peringatan menyebut besar errornya supaya Owner
     * tahu ini bukan "kartu hanya bergeser sedikit".
     */
    #[Test]
    public function a_size_the_printer_cannot_represent_exactly_warns(): void
    {
        $grid = SheetGridCalculator::calculate(99.9, 150.0, 15.0, 15.0, 2.0);

        $this->assertTrue($grid->isPrintable(), 'hanya peringatan, bukan penolakan');
        $this->assertStringContainsString('Lebar kertas 99,9 mm bukan kelipatan 1 dot (0,125 mm)', implode(' ', $grid->warnings));
    }

    #[Test]
    public function sizes_that_are_exact_multiples_of_a_dot_do_not_warn(): void
    {
        // 0,125 mm = 1 dot, jadi semua kelipatan 0,125 harus lolos.
        foreach ([10.125, 15.0, 15.25, 99.875, 100.0] as $sizeMm) {
            $grid = SheetGridCalculator::calculate($sizeMm, 150.0, 15.0, 15.0, 2.0);

            $this->assertSame(
                [],
                array_values(array_filter(
                    $grid->warnings,
                    static fn (string $warning): bool => str_contains($warning, 'kelipatan'),
                )),
                sprintf('ukuran %s mm harus lolos dari peringatan kelipatan dot', $sizeMm),
            );
        }
    }

    /**
     * Lebar media jadi jumlah dot yang diukur operator dengan penggaris.
     *
     * 100 mm pada 203 dpi = 800 dot. Ini satu-satunya angka yang bisa dibandingkan
     * antara kenyataan dan hasil printer tanpa alat khusus.
     */
    #[Test]
    public function the_media_width_is_expressed_in_printer_dots(): void
    {
        $grid = SheetGridCalculator::calculate(100.0, 150.0, 15.0, 15.0, 2.0);

        $this->assertSame(800, $grid->mediaWidthDots());
        $this->assertSame(1200, $grid->mediaHeightDots());
    }

    /**
     * `@page` memakai ukuran MEDIA, bukan ukuran label.
     *
     * Salah satu di sini membuat dialog cetak menawarkan kertas 15 x 15 mm
     * untuk grid 100 x 150 mm, dan gejalanya 48 stiker keluar terpotong jadi
     * satu sudut halaman seukuran satu label.
     */
    #[Test]
    public function the_page_size_is_the_media_not_the_label(): void
    {
        $grid = SheetGridCalculator::calculate(100.0, 150.0, 15.0, 15.0, 2.0);

        $this->assertSame('100mm 150mm', $grid->pageSizeCss());
    }

    /**
     * Panjang CSS tidak boleh punya nol di belakang koma yang tidak perlu.
     *
     * `15.00mm` yang bocor ke `@page` membuat sebagian browser membulatkan
     * ukuran kertas dan memotong tepi kanan label.
     */
    #[Test]
    public function css_lengths_carry_no_trailing_zeroes(): void
    {
        $grid = SheetGridCalculator::calculate(100.0, 150.0, 15.5, 15.0, 2.0);

        $this->assertSame('100mm 150mm', $grid->pageSizeCss());

        $properties = $grid->gridCustomProperties();

        // Pitch 15,5 + 2 = 17,5 mm, jadi 102/17,5 = 5,8 -> 5 kolom.
        $this->assertSame('15.5mm', $properties['--label-w']);
        $this->assertSame('5', $properties['--cols']);
        $this->assertSame(8, (int) $properties['--rows']);
    }

    /**
     * Pemisah desimal CSS harus TITIK, bukan koma.
     *
     * Angka untuk dibaca orang memakai koma, dan pemformat yang sama tidak
     * boleh dipakai di sini: `15,5mm` bukan nilai CSS yang valid, jadi browser
     * membuang seluruh deklarasi itu dan grid runtuh tanpa pesan error. Test ini
     * yang menahan kedua pemformat tidak digabung.
     */
    #[Test]
    public function css_lengths_use_a_dot_decimal_separator(): void
    {
        $grid = SheetGridCalculator::calculate(100.0, 150.0, 15.5, 20.25, 2.5);

        $this->assertSame(
            '--sheet-w:100mm;--sheet-h:150mm;--label-w:15.5mm;--label-h:20.25mm;'
            .'--gap:2.5mm;--cols:5;--rows:6',
            $grid->gridStyle(),
        );
        $this->assertStringNotContainsString(',', $grid->gridStyle());
    }

    /**
     * Jumlah kolom dan baris ditulis tanpa satuan.
     *
     * `repeat(6, 15)` tanpa satuan tidak valid dan grid akan runtuh tanpa
     * pesan error apa pun.
     */
    #[Test]
    public function the_column_and_row_counts_are_written_without_units(): void
    {
        $grid = SheetGridCalculator::calculate(100.0, 150.0, 15.0, 15.0, 2.0);

        $this->assertSame(
            '--sheet-w:100mm;--sheet-h:150mm;--label-w:15mm;--label-h:15mm;--gap:2mm;--cols:6;--rows:8',
            $grid->gridStyle(),
        );
    }

    /**
     * Ringkasan yang dipakai badge di halaman cetak.
     *
     * Angka dan satuan saja. Yang dibutuhkan operator di depan printer bukan
     * teori gridnya tapi jumlah label yang akan keluar per halaman.
     */
    #[Test]
    public function the_summary_states_the_physical_grid(): void
    {
        $grid = SheetGridCalculator::calculate(100.0, 150.0, 15.0, 15.0, 2.0);

        $this->assertSame('100 x 150 mm · 6 kolom x 8 baris = 48 label per halaman', $grid->summary());
        $this->assertSame('16 mm', $grid->trailingSlackLabel());
    }

    /**
     * Angka untuk ditampilkan ke orang memakai koma dan tanpa nol di belakang.
     */
    #[Test]
    public function numbers_shown_to_humans_use_a_comma_decimal_separator(): void
    {
        $this->assertSame('0', SheetGrid::mm(0.0));
        $this->assertSame('12,5', SheetGrid::mm(12.5));
        $this->assertSame('15', SheetGrid::mm(15.0));
        $this->assertSame('12,54', SheetGrid::mm(12.54));
        $this->assertSame('2,5', SheetGrid::mm(2.5));
    }

    /**
     * Nilai untuk `<input type="number">` memakai titik, bukan koma.
     *
     * Berlawanan dengan angka yang dibaca manusia, dan itu wajib: kolom input
     * hanya menerima titik sebagai pemisah desimal, dan browser mengirim apa
     * adanya yang tertulis. Koma yang lolos ke server akan ditolak aturan
     * `numeric`, jadi Owner akan melihat "Celah harus berupa angka" untuk
     * angka yang dia ketik sendiri.
     *
     * Tiga angka desimal, karena satu dot printer = 0,125 mm. Celah 0,125 mm
     * harus bisa diketik persis -- dibulatkan jadi 0,13, jaraknya bukan lagi
     * kelipatan yang bisa digambar printer.
     */
    #[Test]
    public function input_values_use_a_dot_decimal_separator(): void
    {
        $this->assertSame('0', SheetGrid::inputValue(0.0));
        $this->assertSame('100', SheetGrid::inputValue(100.0));
        $this->assertSame('12.5', SheetGrid::inputValue(12.5));
        $this->assertSame('0.125', SheetGrid::inputValue(0.125));
        $this->assertSame('2.5', SheetGrid::inputValue(2.5));
        $this->assertSame('99.75', SheetGrid::inputValue(99.75));
    }

    /**
     * Pembulatan floating point tidak boleh menghilangkan satu baris atau kolom.
     *
     * Kertas yang pas persis harus menghasilkan jumlah bulat yang tepat, bukan
     * satu kurang karena `102 / 17` keluar sebagai 5,9999999999.
     */
    #[Test]
    public function an_exact_fit_is_not_lost_to_floating_point_rounding(): void
    {
        // 8 x 15 mm + 7 x 2 mm = 134 mm, jadi 134 mm adalah tinggi yang pas
        // persis. Pembaginya 136/17, dan inilah yang keluar sebagai
        // 7,999999999999999 kalau floating point ikut menentukan batasnya.
        $grid = SheetGridCalculator::calculate(100.0, 134.0, 15.0, 15.0, 2.0);

        $this->assertSame(6, $grid->columns);
        $this->assertSame(8, $grid->rows, '134 mm harus muat 8 baris, bukan 7');
        $this->assertEqualsWithDelta(0.0, $grid->slackHeightMm, 0.0001);
    }

    /**
     * Peringatan sisa lebar harus menyebut angka yang harus diubah.
     *
     * Ini kasus yang pernah terjadi sungguhan: kertas 100 x 150 mm, label
     * 15 mm, celah 2,1 mm hasil pengukuran fisik Owner. Enam kolom butuh
     * 100,5 mm, jadi grid keluar 5 x 8 dan delapan label per lembar hilang
     * tanpa jejak.
     *
     * Peringatan lama hanya berarti "biasanya salah satu ukuran keliru" -- benar,
     * tapi Owner tidak tahu angka mana. Di sinilah angka yang harus diubah ikut
     * disebut: celah 2 mm atau kurang.
     */
    #[Test]
    public function the_width_slack_warning_says_what_to_change(): void
    {
        $grid = SheetGridCalculator::calculate(100.0, 150.0, 15.0, 15.0, 2.1);

        $this->assertSame(5, $grid->columns, 'Celah 2,1 mm memang membuat kolom keenam tidak muat.');
        $this->assertStringContainsString(
            'dengan celah 2 mm atau kurang, muat 6 kolom (dipakai 100 mm)',
            $this->warningsAsText($grid),
        );
    }

    /**
     * Saran celah harus bisa digambar printer, bukan hanya masuk akal di
     * kalkulator.
     *
     * 2,1 mm bukan kelipatan 1 dot. Kalau sarannya dibulatkan ke kelipatan
     * terdekat yang lebih besar, yaitu 2,125 mm, kolom keenam hilang lagi --
     * dan Owner akan mengira sarannya salah.
     */
    #[Test]
    public function the_suggested_gap_is_rounded_down_to_a_whole_printer_dot(): void
    {
        // Batas kasarnya 1,9 mm: 6 x 15 mm + 5 x 1,9 mm = 99,5 mm. Bulatan ke
        // bawah yang kelipatan 1 dot adalah 1,875 mm.
        $grid = SheetGridCalculator::calculate(100.0, 150.0, 15.0, 15.0, 2.0);

        $this->assertNull(
            SheetGridCalculator::extraColumnGapCeilingMm(6, 100.0, 15.0),
            'Enam kolom sudah maksimum pada lebar 100 mm; tidak ada plafon lagi.',
        );

        $ceiling = SheetGridCalculator::extraColumnGapCeilingMm(5, 100.0, 15.0);

        $this->assertSame(2.0, $ceiling);
        $this->assertSame(
            0.0,
            fmod($ceiling * SheetGrid::DOTS_PER_MM, 1.0),
            'Plafon harus kelipatan bulat dalam dot, bukan sekadar kelipatan desimal.',
        );
    }

    /**
     * Bila baris ikut berubah pada celah yang disarankan, itu ikut disebut.
     *
     * Diam-diam menambah satu kolom sambil menambah satu baris yang tidak ada
     * di kertas fisiknya lebih buruk daripada tidak memberi saran sama sekali:
     * jumlah label per lembar jadi tidak cocok dengan isi laci.
     */
    #[Test]
    public function the_warning_mentions_when_the_rows_would_change_too(): void
    {
        $grid = SheetGridCalculator::calculate(40.0, 40.0, 12.0, 12.0, 2.5);

        $this->assertSame(2, $grid->columns);
        $this->assertSame(2, $grid->rows);
        $this->assertStringContainsString(
            'baris ikut menjadi 3',
            $this->warningsAsText($grid),
        );
    }

    /**
     * Tidak ada saran kalau labelnya sendiri sudah memenuhi lebar kertas.
     *
     * surfaced angka yang tidak bisa dipegang Owner membuat peringatan bagian
     * ini Trusted, dan peringatan yang tidak dipercaya diabaikan seluruhnya.
     */
    #[Test]
    public function no_gap_is_suggested_when_the_label_already_fills_the_paper(): void
    {
        $this->assertNull(SheetGridCalculator::extraColumnGapCeilingMm(2, 40.0, 38.0));
        $this->assertNull(SheetGridCalculator::extraColumnGapCeilingMm(0, 100.0, 15.0));
    }

    /**
     * Gabungan semua peringatan jadi satu string, supaya test bisa memeriksa
     * kalimatnya tanpa tahu urutannya.
     */
    private function warningsAsText(SheetGrid $grid): string
    {
        return implode(' ', $grid->warnings);
    }
}
