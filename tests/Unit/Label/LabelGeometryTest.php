<?php

declare(strict_types=1);

namespace Tests\Unit\Label;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\OwnerType;
use App\Models\Product;
use App\Models\StockLot;
use App\Services\Label\LabelContent;
use App\Services\Label\LabelGeometry;
use App\Services\Label\LabelRow;
use App\Services\Label\LabelTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test yang menjaga label tidak keluar dari stikernya.
 *
 * Ukuran font label dulunya hanya ada di stylesheet, sehingga tidak ada yang
 * bisa memastikan teksnya muat di 3x2 cm. Setelah dihitung, layout yang ada
 * memakai 2,01 cm teks di ruang 1,80 cm: teksnya tergulir keluar stiker dan
 * tercetak terpotong, dan tidak ada satu pun test yang gagal. Test di bawah
 * dibuat supaya itu tidak bisa terjadi diam-diam.
 */
class LabelGeometryTest extends TestCase
{
    /**
     * SKU terpanjang yang dipakai sistem adalah 15 karakter
     * (`CN01-HW-001-U03`).
     */
    private const LONGEST_SKU_LENGTH = 15;

    /**
     * Karakter minimum per baris untuk baris yang boleh membungkus.
     *
     * Di bawah angka ini isi terpotong jadi berkeping, bukan kata. Angka ini
     * bukan hasil pengukuran font, tapi lantai yang masih bisa dibaca operator:
     * baris narasimu yang paling rapat sekarang 9 karakter per baris.
     */
    private const MINIMUM_READABLE_CHARS_PER_LINE = 8;

    /**
     * Panjang isi terburuk per baris, disalin dari
     * `LabelGeometry::WORST_CASE_VALUES` supaya test dan geometriathons
     * menguji sumber yang sama.
     *
     * @var array<string, int>
     */
    private const WORST_CASE_VALUES = [
        'sku' => 15,
        'product' => 24,
        'condition' => 11,
        'price' => 13,
    ];

    /**
     * Template yang mencetak teks, jadi punya baris untuk diperiksa.
     *
     * Diturunkan dari `cases()` dengan menyaring QR-only, bukan ditulis manual.
     * Kalau template teks baru ditambahkan lalu lupa didaftarkan di sini, test
     * akan lulus tanpa memeriksa apa pun -- dan layout yang meluap kembali tidak
     * ketahuan. Dengan filter, kelalaian itu jadi test yang gagal.
     *
     * @return array<string, array{0: LabelTemplate}>
     */
    public static function skuTemplates(): array
    {
        $templates = [];

        foreach (LabelTemplate::cases() as $template) {
            if (! $template->isQrOnly()) {
                $templates[$template->value] = [$template];
            }
        }

        return $templates;
    }

    /**
     * @return array<string, array{0: LabelTemplate}>
     */
    public static function rackTemplates(): array
    {
        return self::skuTemplates();
    }

    /**
     * @return array<string, array{0: LabelTemplate}>
     */
    public static function qrOnlyTemplates(): array
    {
        return ['1.5x1.5' => [LabelTemplate::QrOnly]];
    }

    #[Test]
    #[DataProvider('skuTemplates')]
    public function the_rows_of_a_sku_label_fit_inside_the_label(LabelTemplate $template): void
    {
        $geometry = LabelGeometry::forSku($template);

        $this->assertTrue(
            $geometry->fits(),
            sprintf(
                'Baris label %s butuh %.2f cm, ruang hanya %.2f cm. Sisa %.2f cm -- teks akan keluar stiker.',
                $template->value,
                $geometry->requiredHeightCm(),
                $geometry->textHeightCm(),
                $geometry->spareHeightCm(),
            ),
        );
    }

    #[Test]
    #[DataProvider('rackTemplates')]
    public function the_rows_of_a_rack_label_fit_inside_the_label(LabelTemplate $template): void
    {
        $geometry = LabelGeometry::forRack($template);

        $this->assertTrue($geometry->fits(), "Baris label rak {$template->value} keluar dari stiker.");
    }

    /**
     * Kode rak WAJIB muat utuh, bukan dipotong.
     *
     * Label rak pernah tidak diuji sama sekali karena `renderRack` tidak
     * dipanggil dari mana pun. Saat dihitung, font 0,80 cm di 3x2 cm hanya
     * menampung 5 karakter, sedangkan `RK:A-01-03` sudah 10 karakter: label
     * akan tercetak "RK:A-" dan dua rak bersebelahan tampil sama persis.
     *
     * Dijaga untuk SEMUA ukuran, sama seperti batas panjang SKU.
     */
    #[Test]
    #[DataProvider('rackTemplates')]
    public function a_rack_label_can_hold_the_longest_supported_code(LabelTemplate $template): void
    {
        $geometry = LabelGeometry::forRack($template);
        $row = $geometry->rows[0];
        $capacity = $geometry->capacityFor($row);

        $this->assertGreaterThanOrEqual(
            LabelGeometry::LONGEST_RACK_CODE,
            $capacity,
            sprintf(
                'Kode rak %d karakter tidak muat di %s: %d karakter per baris x %d baris = %d.',
                LabelGeometry::LONGEST_RACK_CODE,
                $template->value,
                $geometry->charsPerLine($row),
                $row->maxLines,
                $capacity,
            ),
        );
    }

    /**
     * QR put-away (FR-MD-21) ada di label rak 4x3 dan label rak QR-only.
     *
     * 3x2 cm tidak bisa memuat QR yang bisa dipindai *dan* kode rak yang masih
     * terbaca manusia. Ini bukan kelalaian, tapi batas fisik: setelah QR
     * dipotong, kode 15 karakter hanya muat memakai font ~0,2 cm. Kalau suatu
     * saat ada yang menambahkan QR ke 3x2, test ini memaksa dia mengubah
     * angka capacity di atas lebih dulu.
     *
     * QR-only 1,5 cm boleh punya QR justru karena tidak ada kode rak yang harus
     * dipertahankan: isinya cuma QR, dan rak yang memakai template ini dibaca
     * dengan scanner.
     */
    #[Test]
    public function the_rack_label_carries_a_qr_only_when_it_can_keep_the_code(): void
    {
        $this->assertSame(0.0, LabelGeometry::forRack(LabelTemplate::ThreeByTwo)->qrSideCm);
        $this->assertGreaterThan(0.0, LabelGeometry::forRack(LabelTemplate::FourByThree)->qrSideCm);
        $this->assertGreaterThan(0.0, LabelGeometry::forRack(LabelTemplate::QrOnly)->qrSideCm);
    }

    /**
     * QR label rak harus muat di lebar label, sama seperti QR label barang.
     *
     * Dijaga untuk semua template yang punya QR, bukan hanya 4x3. Pada label
     * sekecil QR-only, QR yang melebihi lebar akan terpotong tepat di tepi dan
     * modul yang hilang membuat kode tidak bisa discan sama sekali.
     *
     * Tepat sama dengan lebar label tetap diterima: pada QR-only, QR memang
     * sengaja mengisi label sampai batas padding, jadi 1,38 + 2 x 0,06 = 1,50
     * adalah hasil yang dimaksud, bukan kesalahan. Yang dilarang adalah QR
     * melewati label, dan itu yang diperiksa test margin di bawah.
     */
    #[Test]
    #[DataProvider('qrOnlyTemplates')]
    public function the_qr_only_rack_qr_fits_within_the_width_of_the_label(LabelTemplate $template): void
    {
        $geometry = LabelGeometry::forRack($template);

        $this->assertLessThanOrEqual(
            $geometry->widthCm,
            $geometry->qrSideCm + (2 * $geometry->paddingCm),
            "QR {$geometry->qrSideCm} cm dan padding {$geometry->paddingCm} cm melebihi label {$geometry->widthCm} cm.",
        );
    }

    /**
     * QR harus menyisakan margin di tepi stiker.
     *
     * Padding yang nilainya nol tidak berarti aman. Cetakan thermal sering
     * bergeser beberapa tenth milimeter, dan tepat di tepilah kertas tidak rata
     * -- modul yang jatuh di sana bisa hilang. Jadi QR tidak boleh sama dengan
     * lebar label; harus ada sisa yang paling sedikit setebal satu padding.
     */
    #[Test]
    #[DataProvider('qrOnlyTemplates')]
    public function the_qr_only_label_keeps_a_printable_margin_at_the_edge(LabelTemplate $template): void
    {
        foreach ([LabelGeometry::forSku($template), LabelGeometry::forRack($template)] as $geometry) {
            $marginCm = ($geometry->widthCm - $geometry->qrSideCm) / 2;

            $this->assertGreaterThanOrEqual(
                $geometry->paddingCm,
                $marginCm,
                sprintf(
                    'QR %s cm hanya menyisakan %.3f cm per tepi di label %s cm; cetakan bisa bergeser dan memotong modul.',
                    number_format($geometry->qrSideCm, 2, ',', '.'),
                    $marginCm,
                    number_format($geometry->widthCm, 2, ',', '.'),
                ),
            );
        }
    }

    /**
     * Kolom teks harus punya sisa lebar yang masuk akal. Kalau QR terlalu
     * besar, kolom teks menyisakan beberapa milimeter dan tidak ada yang bisa
     * ditulis di sana.
     */
    #[Test]
    #[DataProvider('skuTemplates')]
    public function there_is_still_room_for_text_next_to_the_qr(LabelTemplate $template): void
    {
        $geometry = LabelGeometry::forSku($template);

        $this->assertGreaterThan(
            1.0,
            $geometry->textWidthCm(),
            "Kolom teks label {$template->value} terlalu sempit untuk ditulis.",
        );
    }

    /**
     * QR harus benar-benar muat di lebar label, kalau tidak ada bagian QR
     * yang terpotong dan QR jadi tidak bisa dipindai.
     */
    #[Test]
    #[DataProvider('skuTemplates')]
    public function the_qr_fits_within_the_width_of_the_label(LabelTemplate $template): void
    {
        $geometry = LabelGeometry::forSku($template);

        $this->assertLessThan($geometry->widthCm, $geometry->qrSideCm + (2 * $geometry->paddingCm));
    }

    /**
     * Baris yang boleh membungkus harus punya ruang vertikal untuknya.
     *
     * Dulu aturannya "hanya SKU yang boleh membungkus", dan itu yang membuat
     * nama produk terpotong: kolom teks 3x2 hanya memuat 12 karakter satu
     * baris, sedangkan `shortProductName` mengirim sampai 24. Aturan itu
     * tidak diperdebatkan -- dicek ulang lewat pengukuran ruang, bukan tebakan.
     *
     * Yang dijaga di sini bukan "baris mana boleh", tapi dua hal yang bisa
     * diukur: `maxLines` tidak melebihi ruang yang ada, dan baris harga tetap
     * satu baris karena pemengatan di tengah angka membuat harga salah dibaca.
     */
    #[Test]
    #[DataProvider('skuTemplates')]
    public function a_wrapping_row_has_room_for_the_lines_it_asks_for(LabelTemplate $template): void
    {
        $geometry = LabelGeometry::forSku($template);

        foreach ($geometry->rows as $index => $row) {
            $this->assertGreaterThanOrEqual(
                1,
                $row->maxLines,
                "Baris {$index} di {$template->value} harus punya minimal satu baris.",
            );

            $this->assertLessThanOrEqual(
                $geometry->textHeightCm(),
                $row->heightCm(),
                "Baris {$index} di {$template->value} melebihi tinggi area teks.",
            );
        }

        $priceRow = $geometry->rows[array_key_last($geometry->rows)];

        $this->assertSame(
            1,
            $priceRow->maxLines,
            'Harga tidak boleh terbelah dua baris: pemengatan di tengah angka membuat harga salah dibaca.',
        );
    }

    /**
     * Setiap baris harus benar-benar memuat isinya -- bukan cuma muat secara
     * aritmetika.
     *
     * Ini test yang selama ini hilang, dan ketiadaannya yang membiarkan harga
     * tercetak `Rp100.0...` selama ini: `the_worst_price_we_allow_fits_the_price_row_on_one_line`
     * terlihat benar, tapi ia menghitung ulang angka dari `LabelGeometry` yang
     * sama -- dan `capacityFor()` tidak memotong ruang keterangan. Jadi
     * kesalahan geometrinya tercermin jadi test yang hijau.
     *
     * Test ini memakai panjang isi terburuk yang berasal dari data
     * (`LabelGeometry::WORST_CASE_VALUES`), bukan angka yang dipilih test
     * sendiri. Kalau keterangan, font, atau QR berubah, test
     * inilah yang akan lebih dulu merah.
     */
    #[Test]
    #[DataProvider('skuTemplates')]
    public function every_row_fits_the_worst_case_value_on_every_template(LabelTemplate $template): void
    {
        $geometry = LabelGeometry::forSku($template);

        $values = self::WORST_CASE_VALUES;
        $index = 0;

        // 3x2 menggabung kondisi dan pemilik dalam satu baris, 4x3 memisahkannya.
        $rows = $geometry->rows;
        $isCompact = $template === LabelTemplate::ThreeByTwo;

        foreach ($rows as $row) {
            $expected = match (true) {
                $index === 0 => $values['sku'],
                $index === 1 => $values['product'],
                $isCompact && $index === 2 => $values['condition'],
                $isCompact && $index === 3 => $values['price'],
                $index === 2 => 5,
                $index === 3 => 5,
                default => $values['price'],
            };

            $this->assertGreaterThanOrEqual(
                $expected,
                $geometry->capacityFor($row),
                sprintf(
                    'Baris %d (%s) di %s hanya memuat %d karakter, padahal isi terburuk %d karakter.',
                    $index,
                    $row->caption !== '' ? $row->caption : 'tanpa keterangan',
                    $template->value,
                    $geometry->capacityFor($row),
                    $expected,
                ),
            );

            $index++;
        }
    }

    /**
     * Tidak ada font yang boleh turun di bawah batas baca manusia.
     *
     * Batas ini menjaga permintaan "perkecil font asalkan terbaca" tetap punya
     * angka: tanpa test ini, perbaikan satu baris bisa membuat
     * baris lain 0,16 cm tanpa ada yang mengeluh sampai label keluar printer.
     */
    #[Test]
    #[DataProvider('skuTemplates')]
    public function no_row_uses_a_font_below_the_readable_floor(LabelTemplate $template): void
    {
        foreach ([LabelGeometry::forSku($template), LabelGeometry::forRack($template)] as $geometry) {
            foreach ($geometry->rows as $row) {
                $this->assertGreaterThanOrEqual(
                    LabelGeometry::MIN_READABLE_FONT_CM,
                    $row->fontSizeCm,
                    sprintf(
                        'Font %.2f cm di %s di bawah batas baca %.2f cm.',
                        $row->fontSizeCm,
                        $template->value,
                        LabelGeometry::MIN_READABLE_FONT_CM,
                    ),
                );
            }
        }
    }

    /**
     * SKU adalah identitas unit. Kalau tidak muat dalam jumlah baris yang
     * dizizinkan, bagian sisanya terpotong dan stiker tidak bisa dipindai --
     * jadi ini dicek untuk kedua ukuran, bukan hanya yang kecil.
     */
    #[Test]
    #[DataProvider('skuTemplates')]
    public function a_full_length_sku_fits_within_its_allowed_lines_on_every_template(LabelTemplate $template): void
    {
        $geometry = LabelGeometry::forSku($template);
        $skuRow = $geometry->rows[0];

        $perLine = $geometry->charsPerLine($skuRow);
        $capacity = $geometry->capacityFor($skuRow);

        $this->assertGreaterThanOrEqual(
            self::LONGEST_SKU_LENGTH,
            $capacity,
            sprintf(
                'SKU 15 karakter tidak muat di %s: %d karakter per baris x %d baris = %d.',
                $template->value,
                $perLine,
                $skuRow->maxLines,
                $capacity,
            ),
        );
    }

    /**
     * Baris yang boleh membungkus jangan sampai shredded jadi potongan yang
     * tidak terbaca.
     *
     * Kapasitas total sudah dijaga `every_row_fits_the_worst_case_value_on_every_template`.
     * Test ini menjaga hal yang berbeda: waktu isi 24 karakter harus dibagikan ke
     * tiga baris, tiap barisnya jangan cuma dapat enam karakter -- kalanya jadi
     * berkeping, bukan kata, dan operator berhenti membaca.
     *
     * Lantainya hanya berlaku untuk baris yang memang membungkus. Baris yang
     * tidak membungkus tidak perlu lebar mininum: isinya pendek dan
     * kecukupannya sudah dijaga `every_row_fits_the_worst_case_value_on_every_template`.
     * Baris KONDISI di 4x3 hanya seven karakter per baris, tapi isinya
     * "NM/CL" -- lima karakter, jadi tidak pernah jadi tujuh karakter yang
     * terbaca.
     */
    #[Test]
    #[DataProvider('skuTemplates')]
    public function no_wrapping_line_is_too_narrow_to_read(LabelTemplate $template): void
    {
        $geometry = LabelGeometry::forSku($template);

        foreach ($geometry->rows as $index => $row) {
            if (! $row->wraps()) {
                continue;
            }

            $this->assertGreaterThanOrEqual(
                self::MINIMUM_READABLE_CHARS_PER_LINE,
                $geometry->charsPerLine($row),
                sprintf(
                    'Baris %d di %s cuma memuat %d karakter per baris, di bawah %d yang masih terbaca.',
                    $index,
                    $template->value,
                    $geometry->charsPerLine($row),
                    self::MINIMUM_READABLE_CHARS_PER_LINE,
                ),
            );
        }
    }

    /**
     * Harga tidak boleh melebar. Baris harga yang terlalu sempit tidak
     * menghemat ruang apa pun -- `text-overflow: ellipsis` memotong
     * `Rp100.000.000` jadi `Rp100.000.…`, dan itu angka yang salah, bukan
     * sekadar kurang terbaca.
     *
     * Yang dijaga di sini harga tertinggi yang masih boleh diisi di inbound
     * (`items.*.list_price` maksimum 100.000.000), karena harga di bawahnya
     * selalu muat dan tidak pernah menemukan masalah.
     */
    #[Test]
    #[DataProvider('skuTemplates')]
    public function the_worst_price_we_allow_fits_the_price_row_on_one_line(LabelTemplate $template): void
    {
        $geometry = LabelGeometry::forSku($template);
        $priceRow = $geometry->rows[array_key_last($geometry->rows)];

        $worst = LabelContent::sample()->priceLabel();

        $this->assertSame(1, $priceRow->maxLines, 'Harga tidak boleh terbelah dua baris: pemengatan di tengah angka membuat harga salah dibaca.');
        $this->assertGreaterThanOrEqual(
            mb_strlen($worst),
            $geometry->capacityFor($priceRow),
            "Harga tertinggi ({$worst}) melebihi kapasitas baris harga label {$template->value}.",
        );
    }

    /**
     * Konten label yang benar-benar dipakai harus muat di template kecil.
     * Ini yang menghubungkan anggaran ruang dengan isi yang akan dicetak.
     */
    #[Test]
    public function a_real_label_content_fits_the_small_template(): void
    {
        $content = LabelContent::fromLot($this->typicalLot());
        $geometry = LabelGeometry::forSku(LabelTemplate::ThreeByTwo);

        $this->assertTrue($geometry->fits());

        $values = [
            $content->sku,
            $content->productName,
            $content->conditionLabel().' '.$content->ownerLabel(),
            $content->priceLabel(),
        ];

        foreach ($geometry->rows as $index => $row) {
            $capacity = $geometry->capacityFor($row);
            $this->assertLessThanOrEqual(
                $capacity,
                mb_strlen($values[$index]),
                "Isi baris {$index} ('{$values[$index]}') melebihi kapasitas {$capacity} karakter.",
            );
        }
    }

    /**
     * Template QR-only tidak boleh punya baris teks sama sekali.
     *
     * Bukan berarti stiker ini kosong -- SKU dan harga digambar renderer
     * langsung di bawah QR, bukan lewat daftar baris (lihat
     * `LabelGeometry::QR_ONLY_SKU_FONT_CM`). Kalau isi lot mulai bocor ke
     * `rows`, artinya geometri menyerahkan kontrol ukurannya ke teks: stiker
     * 1,5 cm tidak punya ruang untuk baris yang dikelola lewat kapasitas,
     * dan QR-nya ikut terseret oleh isi lot.
     */
    #[Test]
    #[DataProvider('qrOnlyTemplates')]
    public function the_qr_only_template_has_no_text_rows_at_all(LabelTemplate $template): void
    {
        $this->assertSame([], LabelGeometry::forSku($template)->rows);
        $this->assertSame([], LabelGeometry::forRack($template)->rows);
    }

    /**
     * QR-only harus cukup kasar untuk dibaca, dan menyisakan ruang untuk dua
     * baris teks: SKU dan harga.
     *
     * Kedua hal itu tarik-tarik ke arah berlawanan, jadi diuji bersama. QR
     * yang diperbesar tanpa menyisakan tempat teks berarti teksnya menimpa
     * modul; QR yang dikecilkan untuk muat dua baris menurunkan modulnya dan
     * pemindaian jadi lebih peka jarak. Ukuran sekarang menjaga modul di
     * 0,362 mm (~2,9 dot) -- di atas ambang baca pada 203 dpi.
     */
    #[Test]
    public function the_qr_only_qr_leaves_room_for_sku_and_price(): void
    {
        $qrOnly = LabelGeometry::forSku(LabelTemplate::QrOnly);

        /*
         * Dua syarat yang saling menjaga. QR harus cukup besar supaya modulnya
         * tidak terlalu halus untuk scanner, dan sisanya harus persis cukup
         * untuk dua baris teks -- tidak sampai muat baris ketiga, karena sisa
         * yang lebih besar hanya berarti QR bisa dibesarkan.
         *
         * Ruang di bawah QR dihitung dari geometry yang sama dengan yang
         * dicetak: label dikurangi dua kali padding dan QR, lalu tanpa dibagi
         * dua karena QR sekarang menempel padding atas, bukan dipusatkan.
         */
        $roomBelowQr = $qrOnly->heightCm - (2 * $qrOnly->paddingCm) - $qrOnly->qrSideCm;

        $this->assertGreaterThanOrEqual(
            LabelGeometry::QR_ONLY_SKU_FONT_CM * 2,
            $roomBelowQr,
            'Ruang di bawah QR harus cukup untuk dua baris (SKU + harga).',
        );

        $this->assertLessThan(
            LabelGeometry::QR_ONLY_SKU_FONT_CM * 3,
            $roomBelowQr,
            'Ruang sisa yang muat baris ketiga berarti QR bisa diperbesar lagi.',
        );

        /*
         * Lantai ketelitian modul.
         *
         * Version 1 adalah 21 x 21 modul dan empat modul quiet zone di setiap
         * sisi, jadi 29 satuan total. Angka itu yang membagi sisi QR di sini.
         * QR-only 1,05 cm = 0,362 mm per modul (~2,9 dot pada 203 dpi) -- di
         * atas `QR_ONLY_MIN_MODULE_MM` yang menjaga QR tidak menyusut
         * diam-diam demi baris baru.
         */
        $modulePitchMm = ($qrOnly->qrSideCm * 10) / 29;

        $this->assertGreaterThanOrEqual(
            LabelGeometry::QR_ONLY_MIN_MODULE_MM,
            $modulePitchMm,
            sprintf('Modul QR %.2f mm sudah terlalu halus untuk 203 dpi.', $modulePitchMm),
        );
    }

    /**
     * Angka sisi QR milik Owner tidak boleh menaikkan QR-only kembali ke
     * ukuran yang menimpa teks.
     *
     * Sisi QR di form berlaku untuk semua template, dan 1,24 cm -- angka yang
     * benar untuk 3x2 dan 4x3 -- akan menutupi SKU dan harga kalau ikut dipakai
     * di label 1,5 cm. Kerusakannya tidak terlihat di layar: QR-nya tetap
     * digambar utuh, hanya teksnya yang tertutup.
     */
    #[Test]
    public function the_owner_sided_qr_cannot_make_the_qr_only_label_bigger_again(): void
    {
        $template = LabelTemplate::QrOnly;

        $this->assertSame(
            LabelGeometry::QR_ONLY_MAX_SIDE_CM,
            LabelGeometry::forSku($template, 1.24)->qrSideCm,
            'Override yang melebihi plafon harus ditolak.',
        );

        $this->assertSame(
            LabelGeometry::QR_ONLY_MAX_SIDE_CM,
            LabelGeometry::forSku($template)->qrSideCm,
            'Bawaannya harus sama dengan plafonnya.',
        );

        // Template lain tidak terpengaruh: plafonnya hanya berlaku di sini.
        $this->assertSame(
            1.24,
            LabelGeometry::forSku(LabelTemplate::ThreeByTwo, 1.24)->qrSideCm,
            '3x2 harus tetap menerima ukuran QR milik Owner.',
        );
    }

    /**
     * QR-only harus muat di labelnya sendiri, untuk barang maupun rak.
     */
    #[Test]
    public function the_qr_only_qr_fits_inside_its_own_label(): void
    {
        foreach ([LabelGeometry::forSku(LabelTemplate::QrOnly), LabelGeometry::forRack(LabelTemplate::QrOnly)] as $geometry) {
            $this->assertLessThanOrEqual(
                $geometry->widthCm,
                $geometry->qrSideCm + (2 * $geometry->paddingCm),
                "QR {$geometry->qrSideCm} cm tidak muat di label {$geometry->widthCm} cm bersama padding.",
            );
            $this->assertLessThanOrEqual(
                $geometry->heightCm,
                $geometry->qrSideCm + (2 * $geometry->paddingCm),
                "QR {$geometry->qrSideCm} cm tidak muat di tinggi label {$geometry->heightCm} cm.",
            );
        }
    }

    /**
     * Ukuran QR dari Owner masuk ke geometry, tapi hanya sepanjang ruang yang
     * benar-benar ada. Ini tiga aturan yang diuji di sini sekaligus, karena
     * ketiganya soal hal yang sama: siapa yang berhak mengubah QR, dan sampai
     * sejauh mana.
     */
    #[Test]
    public function the_owner_qr_size_is_applied_when_it_fits_and_ignored_when_it_does_not(): void
    {
        // Mukat di label 4x3: override dipakai.
        $shrunk = LabelGeometry::forSku(LabelTemplate::FourByThree, 1.20);
        $this->assertSame(1.20, $shrunk->qrSideCm, 'QR Owner yang muat harus dipakai.');

        // Tidak muat di label 1,5 cm: ditolak tanpa error, karena pemanggil
        // lain (test, renderer) tidak lewat form. Formnya menolak lebih dulu.
        $tooBig = LabelGeometry::forSku(LabelTemplate::QrOnly, 1.90);
        $this->assertSame(
            LabelGeometry::forSku(LabelTemplate::QrOnly)->qrSideCm,
            $tooBig->qrSideCm,
            'QR yang melewati tepi label harus ditolak, bukan dicetak terpotong.',
        );

        // `null` berarti "pakai bawaan", jadi geometry tidak boleh berubah.
        $untouched = LabelGeometry::forSku(LabelTemplate::ThreeByTwo, null);
        $this->assertSame(
            LabelGeometry::forSku(LabelTemplate::ThreeByTwo)->qrSideCm,
            $untouched->qrSideCm,
        );
    }

    /**
     * Label rak 3x2 sengaja tidak punya QR supaya kode raknya terbaca. Setting
     * umum tidak boleh membatalkannya.
     */
    #[Test]
    public function a_global_qr_size_never_creates_a_qr_on_a_rack_label_without_one(): void
    {
        $geometry = LabelGeometry::forRack(LabelTemplate::ThreeByTwo, 1.80);

        $this->assertSame(0.0, $geometry->qrSideCm, 'Label rak tanpa QR harus tetap tanpa QR.');
    }

    #[Test]
    public function the_owner_qr_size_still_lands_on_a_rack_label_that_has_a_qr(): void
    {
        $geometry = LabelGeometry::forRack(LabelTemplate::FourByThree, 1.60);

        $this->assertSame(1.60, $geometry->qrSideCm);
        $this->assertGreaterThan(
            0.0,
            $geometry->rows[0]->fontSizeCm,
            'Baris kode rak harus tetap ada; hanya QR yang berubah ukuran.',
        );
    }

    /**
     * Override Owner tidak boleh mengubah apa pun selain sisi QR. Kalau iya,
     * label yang sama bisa keluar dengan isi berbeda tergantung setelan yang
     * tidak terlihat di kertasnya.
     */
    #[Test]
    public function the_owner_qr_size_changes_nothing_else_about_the_geometry(): void
    {
        $builtIn = LabelGeometry::forSku(LabelTemplate::ThreeByTwo);
        $overridden = LabelGeometry::forSku(LabelTemplate::ThreeByTwo, 0.90);

        $this->assertSame($builtIn->widthCm, $overridden->widthCm);
        $this->assertSame($builtIn->heightCm, $overridden->heightCm);
        $this->assertSame($builtIn->paddingCm, $overridden->paddingCm);
        $this->assertSame($builtIn->gutterCm, $overridden->gutterCm);

        // Barisnya dibandingkan per nilai, bukan per objek: setiap panggilan
        // `forSku()` membangun instance `LabelRow` baru, jadi `assertSame` atas
        // dua daftar objek akan membandingkan identitas dan selalu gagal.
        $this->assertSame(
            array_map(self::rowValues(...), $builtIn->rows),
            array_map(self::rowValues(...), $overridden->rows),
            'Override QR tidak boleh mengubah baris teks.',
        );
    }

    /**
     * @return array{float, float, int, int, bool, string} nilai baris tanpa identitas objek
     */
    private static function rowValues(LabelRow $row): array
    {
        return [
            $row->fontSizeCm,
            $row->lineHeight,
            $row->maxLines,
            $row->weight,
            $row->mono,
            $row->caption,
        ];
    }

    private function typicalLot(): StockLot
    {
        $lot = new StockLot([
            'sku' => 'CN01-HW-001-U03',
            'owner_code' => 'CN01',
            'owner_type' => OwnerType::Consign,
            'card_condition' => CardCondition::NearMint,
            'blister_condition' => BlisterCondition::Clear,
            'list_price' => 50_000,
            'qty_received' => 12,
        ]);

        $lot->setRelation('product', new Product(['name' => 'Ferrari F40']));

        return $lot;
    }
}
