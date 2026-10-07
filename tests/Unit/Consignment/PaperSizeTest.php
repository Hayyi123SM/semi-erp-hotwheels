<?php

declare(strict_types=1);

namespace Tests\Unit\Consignment;

use App\Enums\PaperSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ukuran kertas untuk dokumen yang dicetak.
 *
 * Yang dijaga di sini bukan angka yang tampilannya enak dibaca, tapi dua hal
 * jenis kesalahan:
 *
 * 1. `@page size` yang salah. Salah di sini tidak merusak tampilan, dan
 *    gejalanya baru muncul di depan printer: struk 80 mm keluar tercetak di
 *    tengah A4, dan pemotong thermal memotong seluruh A4 itu jadi satu
 *    gulungan. Tidak ada test visual yang bisa menangkapnya, jadi nilainya
 *    diuji langsung.
 *
 * 2. Panjang thermal yang dipatok. Kalau panjangnya tetap, isi yang melewati
 *    satu halaman thermal terpotong di tengah, dan karena footer "Dicetak"
 *    serta baris tanda tangan berada di paling akhir, yang hilang justru
 *    bagian yang membuktikan struk itu asli.
 */
class PaperSizeTest extends TestCase
{
    #[Test]
    #[DataProvider('paperCases')]
    public function it_publishes_its_value_and_label(PaperSize $paper, string $value, string $label): void
    {
        self::assertSame($value, $paper->value);
        self::assertSame($label, $paper->label());
        self::assertSame($paper, PaperSize::from($value));
    }

    /**
     * @return array<string, array{0: PaperSize, 1: string, 2: string}>
     */
    public static function paperCases(): array
    {
        return [
            'struk 58' => [PaperSize::Mm58, '58mm', 'Struk 58 mm'],
            'struk 80' => [PaperSize::Mm80, '80mm', 'Struk 80 mm'],
            'A4' => [PaperSize::A4, 'a4', 'A4'],
        ];
    }

    #[Test]
    #[DataProvider('pageSizeCases')]
    public function it_publishes_the_page_size_for_the_print_dialog(PaperSize $paper, string $expected): void
    {
        self::assertSame($expected, $paper->pageSizeCss());
    }

    /**
     * @return array<string, array{0: PaperSize, 1: string}>
     */
    public static function pageSizeCases(): array
    {
        return [
            // Panjang `auto`, bukan angka: printer thermal menarik gulungan
            // sejauh isinya lalu memotong di ujungnya.
            'struk 58 thermal' => [PaperSize::Mm58, '58mm auto'],
            'struk 80 thermal' => [PaperSize::Mm80, '80mm auto'],
            'A4' => [PaperSize::A4, 'A4'],
        ];
    }

    #[Test]
    #[DataProvider('widthCases')]
    public function its_content_width_never_exceeds_its_page_width(PaperSize $paper, string $expected, float $pageWidthMm): void
    {
        self::assertSame($expected, $paper->contentWidthCss());

        // Lebar konten harus muat di lebar halaman. Kalau tidak, browser
        // membuat halaman kedua hanya karena satu huruf melewati batas, dan
        // pemotong thermal memotong di antara keduanya.
        self::assertLessThanOrEqual(
            $pageWidthMm,
            (float) str_replace('mm', '', $paper->contentWidthCss()),
            sprintf('Lebar %s melebihi lebar halamannya (%.0f mm).', $paper->value, $pageWidthMm),
        );
    }

    /**
     * Lebar halaman ditulis per kasus, bukan dihitung dari `pageSizeCss()`.
     *
     * `pageSizeCss()` untuk A4 bernilai `A4`, bukan angka millimeter, jadi
     * memcast-nya ke float menghasilkan 0 -- dan perbandingan "190 <= 0" itu
     * selalu benar tanpa membandingkan apa pun. Lebar halaman lebih baik
     * ditulis di sini sebagai angka, karena itulah yang sedang diperiksa.
     *
     * @return array<string, array{0: PaperSize, 1: string, 2: float}>
     */
    public static function widthCases(): array
    {
        return [
            'struk 58' => [PaperSize::Mm58, '58mm', 58.0],
            'struk 80' => [PaperSize::Mm80, '80mm', 80.0],
            // A4 pakai 190 mm, bukan 210 mm, karena `@page` bawaan A4 punya
            // margin dan margin itulah yang membuat isi melebar melewati halaman.
            'A4' => [PaperSize::A4, '190mm', 210.0],
        ];
    }

    #[Test]
    public function only_a4_is_not_thermal(): void
    {
        self::assertTrue(PaperSize::Mm58->isThermal());
        self::assertTrue(PaperSize::Mm80->isThermal());
        self::assertFalse(PaperSize::A4->isThermal());
    }

    #[Test]
    public function thermal_papers_get_an_esc_pos_char_width_but_a4_does_not(): void
    {
        self::assertSame(32, PaperSize::Mm58->escposColumnWidth());
        self::assertSame(48, PaperSize::Mm80->escposColumnWidth());
        self::assertNull(PaperSize::A4->escposColumnWidth());
    }

    #[Test]
    public function an_unknown_stored_value_falls_back_instead_of_throwing(): void
    {
        // `Setting` menyimpan teks bebas, jadi nilainya bisa saja hasil ketik
        // atau format lama. `from()` akan melempar ValueError di dalam halaman
        // cetak -- halaman yang sedang dipegang Staff untuk menyerahkan barang.
        self::assertNull(PaperSize::fromSetting('62mm'));
        self::assertNull(PaperSize::fromSetting(''));
        self::assertNull(PaperSize::fromSetting(null));

        self::assertSame(PaperSize::Mm80, PaperSize::fromSetting('80mm'));
    }

    #[Test]
    public function its_options_cover_every_case_so_the_form_cannot_offer_an_unprintable_paper(): void
    {
        $options = PaperSize::options();

        self::assertCount(count(PaperSize::cases()), $options);
        self::assertSame(
            array_map(static fn (PaperSize $paper): string => $paper->value, PaperSize::cases()),
            array_column($options, 'value'),
        );
    }
}
