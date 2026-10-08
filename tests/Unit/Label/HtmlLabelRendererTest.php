<?php

declare(strict_types=1);

namespace Tests\Unit\Label;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\ConsignmentStatus;
use App\Enums\LotStatus;
use App\Enums\OwnerType;
use App\Enums\SchemeType;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\StockLot;
use App\Services\Label\HtmlLabelRenderer;
use App\Services\Label\LabelContent;
use App\Services\Label\LabelGeometry;
use App\Services\Label\LabelPrinterSettings;
use App\Services\Label\LabelRow;
use App\Services\Label\LabelTemplate;
use App\Services\Label\QrCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class HtmlLabelRendererTest extends TestCase
{
    private HtmlLabelRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = $this->rendererWith();
    }

    /**
     * Renderer dengan ukuran QR tertentu.
     *
     * Renderer dibangun langsung, bukan lewat container, supaya test ini tetap
     * murni unit: tidak ada database yang perlu disiapkan hanya untuk tahu
     * ukuran QR. `QR_SIDE_KEY` selalu ada di setelan yang diberikan -- kalau
     * tidak, pembacaan akan jatuh ke `Setting` dan test unit ini butuh database
     * hanya untuk hal yang sudah diketahui di sini.
     */
    private function rendererWith(?float $qrSideCm = null): HtmlLabelRenderer
    {
        return new HtmlLabelRenderer(new QrCode, new LabelPrinterSettings([
            LabelPrinterSettings::QR_SIDE_KEY => $qrSideCm,
        ]));
    }

    /**
     * Lot asli, tanpa menyentuh database.
     *
     * `LabelContent` sengaja konstrukternya private: satu-satunya jalan masuk
     * adalah `fromLot()` atau `fromPayload()`. Test pun sebaiknya lewat
     * `fromLot()` -- jadi ia ikut menguji pemangkasan nama produk dan aturan
     * privasi, bukan hanya merakit objek yang sudah jadi benar.
     */
    private function lot(array $overrides = []): StockLot
    {
        $product = new Product(['name' => $overrides['product_name'] ?? 'Ferrari F40']);
        $product->setRelation('series', new ProductSeries(['name' => 'HW']));

        $lot = new StockLot([
            'sku' => $overrides['sku'] ?? 'CN01-HW-001-U03',
            'owner_code' => $overrides['owner_code'] ?? 'CN01',
            'owner_type' => $overrides['owner_type'] ?? OwnerType::Consign,
            'card_condition' => $overrides['card_condition'] ?? CardCondition::NearMint,
            'blister_condition' => $overrides['blister_condition'] ?? BlisterCondition::Clear,
            'list_price' => $overrides['list_price'] ?? 50_000,
            'qty_received' => $overrides['quantity'] ?? 12,
            'scheme_type' => SchemeType::Percentage,
            'status' => LotStatus::Available,
        ]);

        $lot->setRelation('product', $product);
        $lot->setRelation('consignment', new Consignment([
            'doc_no' => 'CN-2026-0001',
            'status' => ConsignmentStatus::Committed,
        ]));

        $consignor = new Consignor(['name' => 'Budi Santoso', 'consignor_code' => 'CN01']);
        $lot->setRelation('consignor', $consignor);

        return $lot;
    }

    private function content(array $overrides = []): LabelContent
    {
        return LabelContent::fromLot($this->lot($overrides));
    }

    /**
     * @return array<string, array{0: LabelTemplate, 1: string, 2: string}>
     */
    public static function templateCases(): array
    {
        return [
            '1.5x1.5' => [LabelTemplate::QrOnly, 'width:1.5cm;height:1.5cm', 'width:1.5cm'],
            '3x2' => [LabelTemplate::ThreeByTwo, 'width:3cm;height:2cm', 'width:3cm'],
            '4x3' => [LabelTemplate::FourByThree, 'width:4cm;height:3cm', 'width:4cm'],
        ];
    }

    #[Test]
    #[DataProvider('templateCases')]
    public function the_label_is_exactly_the_size_of_its_template(LabelTemplate $template, string $expected, string $needle): void
    {
        $html = $this->renderer->render($this->content(), $template);

        $this->assertStringContainsString($expected, $html);
        $this->assertStringContainsString($needle, $html);
    }

    /**
     * Ukuran dalam cm harus lengkap, bukansetengah: printer thermal kepotong
     * tepat di tepi, jadi 3.0cm yang tertulis `3cm` dan 3cm yang tertulis
     * `3.0cm` sama saja, tapi `3.2cm` berarti label meleset.
     */
    #[Test]
    public function the_size_written_is_never_rounded_away(): void
    {
        $html = $this->renderer->render($this->content(), LabelTemplate::ThreeByTwo);

        $this->assertStringNotContainsString('3.00cm', $html);
        $this->assertStringNotContainsString('2.00cm', $html);
    }

    #[Test]
    public function the_sku_is_printed_as_plain_readable_text(): void
    {
        $html = $this->renderer->render($this->content(), LabelTemplate::ThreeByTwo);

        // Teks hasil escaping, bukan dieksekusi sebagai HTML.
        $this->assertStringContainsString('CN01-HW-001-U03', $html);
    }

    /**
     * §1.4.2: isi label adalah SKU, nama singkat produk, kondisi, harga, dan
     * kode pemilik. Kalau ada yang hilang, stiker tidak bisa dipakai untuk
     * identifikasi.
     */
    #[Test]
    public function every_field_the_spec_lists_is_printed(): void
    {
        $html = $this->renderer->render($this->content(), LabelTemplate::ThreeByTwo);

        $this->assertStringContainsString('CN01-HW-001-U03', $html, 'SKU');
        $this->assertStringContainsString('Ferrari F40', $html, 'nama produk');
        $this->assertStringContainsString('NM/CL', $html, 'kondisi');
        $this->assertStringContainsString('Rp50.000', $html, 'harga');
        $this->assertStringContainsString('CN01', $html, 'kode pemilik');
    }

    #[Test]
    public function the_price_can_be_left_off(): void
    {
        $html = $this->renderer->render($this->content(), LabelTemplate::ThreeByTwo, showPrice: false);

        $this->assertStringNotContainsString('Rp50.000', $html);
        $this->assertStringNotContainsString('HARGA', $html);
        // Field lain harus tetap ada.
        $this->assertStringContainsString('CN01-HW-001-U03', $html);
        $this->assertStringContainsString('NM/CL', $html);
    }

    /**
     * QR harus punya ukuran yang pasti.
     *
     * Kalau lebar QR berasal dari stylesheet, changes di sana bisa membuat
     * kolom teks meluber keluar stiker tanpa ada test yang gagal. Sisi QR
     * ditulis renderer sebagai inline style supaya geometri label ikut
     * ter-cover di sini.
     */
    #[Test]
    #[DataProvider('templateCases')]
    public function the_qr_block_is_given_an_explicit_size(LabelTemplate $template, string $expected = '', string $needle = ''): void
    {
        $html = $this->renderer->render($this->content(), $template);

        $this->assertMatchesRegularExpression(
            '/<div class="label__qr" style="width:[\\d.]+cm;height:[\\d.]+cm">/',
            $html,
        );
    }

    /**
     * Label 3x2 cm hanya 6 cm persegi. Kalau tata letak yang lebih besar
     * dipaksakan ke sana, teks tergulir keluar stiker dan tercetak terpotong.
     * Karena itu label kecil tidak boleh memakai kolom berketerangan.
     */
    #[Test]
    public function the_small_label_uses_the_compact_layout_without_column_captions(): void
    {
        $html = $this->renderer->render($this->content(), LabelTemplate::ThreeByTwo);

        $this->assertStringContainsString('label--compact', $html);
        $this->assertStringNotContainsString('label--full', $html);

        // Tidak ada keterangan kolom: "PRODUK:" dan sejenisnya memakan ruang
        // yang membuat teksnya mengecil sampai tidak terbaca.
        foreach (['PRODUK', 'KONDISI', 'PEMILIK', 'HARGA'] as $caption) {
            $this->assertStringNotContainsString('>'.$caption.'<', $html);
        }
    }

    /**
     * Label besar cukup untuk kelima isi §1.4.2 sekaligus dengan QR, jadi
     * keterangan kolomnya dipakai -- kecuali baris harga.
     *
     * Baris harga sengaja tanpa keterangan. "HARGA" memakan 0,66 cm dari kolom
     * teks 2,23 cm, dan sisa itu hanya 8 karakter: `Rp100.000.000` tercetak
     * `Rp100.0...`, angka yang salah. Tanpa keterangan, font harga naik dari
     * 0,22 cm ke 0,30 cm dan muat 13 karakter utuh. Angkanya sendiri berawalan
     * "Rp", jadi tidak ada yang perlu diberi tahu bahwa itu harga.
     */
    #[Test]
    public function the_large_label_uses_the_full_layout_with_captions(): void
    {
        $html = $this->renderer->render($this->content(), LabelTemplate::FourByThree);

        $this->assertStringContainsString('label--full', $html);

        foreach (['SKU', 'PRODUK', 'KONDISI', 'PEMILIK'] as $caption) {
            $this->assertStringContainsString('>'.$caption.'<', $html);
        }

        $this->assertStringNotContainsString(
            '>HARGA<',
            $html,
            'Keterangan "HARGA" memakan lebar yang membuat harga terpotong jadi angka salah.',
        );
    }

    /**
     * Baris yang boleh membungkus harus benar-benar dapat kelas CSS-nya.
     *
     * SKU dan nama produk dianggarkan dua-tiga baris oleh `LabelGeometry`, jadi
     * keduanya harus dapat `label__value--wrap`. Dulu kelas itu tidak pernah
     * ada dan stylesheet memaksa `nowrap` -- hasil akhirnya SKU terpotong
     * persis di template yang paling butuh dia utuh.
     */
    #[Test]
    public function a_wrapping_row_gets_the_class_that_allows_wrapping(): void
    {
        foreach ([LabelTemplate::ThreeByTwo, LabelTemplate::FourByThree] as $template) {
            $html = $this->renderer->render($this->content(), $template);

            // SKU dan nama produk membungkus; sisanya tidak.
            $this->assertSame(
                2,
                substr_count($html, 'label__value--wrap'),
                "Template {$template->value} harus punya tepat dua baris yang boleh membungkus.",
            );
        }
    }

    /**
     * Ukuran font keterangan ditulis inline, bukan dari stylesheet.
     *
     * Angka itu ikut menentukan `captionWidthCm()`, jadi kalau hanya ada di
     * CSS, mengubah CSS diam-diam membuat hitungan geometri tidak lagi cocok
     * dengan yang dicetak -- persis kelas bug yang sudah diperbaiki.
     */
    #[Test]
    public function the_caption_font_size_is_written_inline(): void
    {
        $html = $this->renderer->render($this->content(), LabelTemplate::FourByThree);

        $this->assertStringContainsString(
            sprintf('style="font-size:%scm"', rtrim(rtrim(number_format(LabelRow::CAPTION_FONT_SIZE_CM, 2, '.', ''), '0'), '.')),
            $html,
        );
    }

    /**
     * Kode rak adalah satu-satunya isi label rak, jadi harus boleh jauh lebih
     * besar daripada isi label barang. Label rak dibaca manusia saat menata rak.
     */
    #[Test]
    public function a_rack_label_gives_its_code_the_most_room(): void
    {
        $html = $this->renderer->renderRack('RK:A-01-03', LabelTemplate::ThreeByTwo);

        $this->assertStringContainsString('label--rack', $html);
        // Tidak ada QR sama sekali untuk label rak.
        $this->assertStringNotContainsString('label__qr', $html);
    }

    /**
     * Ini yang paling penting dari semua test di file ini.
     *
     * Nama penitip adalah data pribadi. §1.4.2 melarangnya masuk label, karena
     * stiker menempel di barang yang dijual ke orang. Yang dicetak hanya
     * `owner_code`. Kalau `LabelContent` nanti ditambah field `consignorName`,
     * test ini harus langsung merah.
     */
    #[Test]
    public function the_consignor_name_is_never_printed(): void
    {
        $html = $this->renderer->render($this->content(), LabelTemplate::ThreeByTwo);

        $this->assertStringNotContainsString('Budi Santoso', $html);
    }

    /**
     * Label 1,5 cm mencetak QR, satu baris SKU, dan harga.
     *
     * Template itu ada justru karena stikernya hanya punya ruang untuk QR dan
     * dua baris teks. Kalau isi lot lain bocor ke sana, QR tergeser dan
     * modulnya berkurang -- setiap isi label diperiksa satu per satu supaya
     * tidak ada yang ikut lolos diam-diam.
     */
    #[Test]
    public function the_qr_only_label_prints_the_qr_sku_and_harga(): void
    {
        $html = $this->renderer->render($this->content(), LabelTemplate::QrOnly);

        $this->assertStringContainsString('label__qr', $html, 'Label QR-only harus tetap membawa QR.');
        $this->assertStringContainsString('<svg', $html);

        foreach (['label--qr-only', 'label--sku'] as $class) {
            $this->assertStringContainsString($class, $html);
        }

        // Wadah baris kosong punya `flex: 1` dan akan merebut ruang dari QR,
        // jadi kemunculannya di sini berarti layout-nya salah meski teksnya
        // memang tidak ada.
        $this->assertStringNotContainsString('label__rows', $html);

        /*
         * Dua baris di bawah QR: SKU dan harga.
         *
         * QR menempel padding atas dan dibatasi sampai 1,05 cm supaya sisa
         * ruang bawah `(1,38 - 1,05) = 0,33 cm` cukup untuk dua baris @
         * 0,15 cm (lihat `QR_ONLY_MAX_SIDE_CM` di `LabelGeometry`). Harganya
         * berasal dari snapshot payload, bukan harga lot saat ini.
         */
        $this->assertStringContainsString('label__qr-text', $html);
        $this->assertStringContainsString('label__qr-sku', $html);
        $this->assertStringContainsString('label__qr-price', $html, 'Baris harga ikut tercetak.');
        $this->assertStringContainsString('CN01-HW-001-U03', $html);
        $this->assertStringContainsString('Rp50.000', $html, 'Harga ikut tercetak di QR-only.');

        // Nama produk tidak ditampilkan di QR-only.
        $this->assertStringNotContainsString(
            'Ferrari F40',
            $html,
            "Nilai 'Ferrari F40' tidak boleh tercetak di label QR-only.",
        );
    }

    /**
     * Toggle harga berlaku juga di QR-only: baris harga dihapus begitu mati.
     *
     * Stiker yang isinya berubah harus dicetak ulang supaya harga di rak tidak
     * menyesatkan -- sama seperti template lain yang mematikan harga.
     */
    #[Test]
    public function the_qr_only_price_line_follows_the_show_price_flag(): void
    {
        $html = $this->renderer->render($this->content(), LabelTemplate::QrOnly, showPrice: false);

        $this->assertStringContainsString('label__qr-sku', $html);
        $this->assertStringContainsString('CN01-HW-001-U03', $html);
        $this->assertStringNotContainsString('label__qr-price', $html, 'Baris harga ikut toggle.');
        $this->assertStringNotContainsString('Rp50.000', $html);
    }

    /**
     * Label rak QR-only hanya boleh membawa QR, bukan kode rak.
     *
     * `renderRack` membaca `$geometry->rows[0]`, dan pada template QR-only
     * baris itu tidak ada. Kalau barisnya dibiarkan saja, kode rak akan
     * dirender tanpa ruang -- atau lebih buruk, tercetak sebagai `RK:A-` yang
     * sama persis dengan rak sebelahnya.
     */
    #[Test]
    public function the_qr_only_rack_label_prints_the_qr_without_the_rack_code(): void
    {
        $html = $this->renderer->renderRack('RK:A-01-03', LabelTemplate::QrOnly);

        $this->assertStringContainsString('label__qr', $html);
        $this->assertStringContainsString('label--qr-only', $html);
        $this->assertStringNotContainsString('label__rows', $html);
        $this->assertStringNotContainsString('RK:A-01-03', $html);
    }

    /**
     * Label rak yang bukan QR-only tetap harus mencetak kodenya.
     *
     * Penegasan untuk test di atas: perubahan pada `renderRack` tidak boleh
     * ikut membuat label rak biasa kehilangan kode rak.
     */
    #[Test]
    public function a_normal_rack_label_still_prints_its_code(): void
    {
        $html = $this->renderer->renderRack('RK:A-01-03', LabelTemplate::FourByThree);

        $this->assertStringContainsString('RK:A-01-03', $html);
        $this->assertStringContainsString('label__rows', $html);
    }

    #[Test]
    public function a_qr_code_is_printed_for_the_sku(): void
    {
        $html = $this->renderer->render($this->content(), LabelTemplate::ThreeByTwo);

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('label__qr', $html);
    }

    /**
     * Label rak selalu berawalan RK: supaya tidak tertukar dengan SKU saat
     * scan (§1.4.2). Kalau caller mengirim `A-01-03` polos, tugas menambahkan
     * prefiksnya ada di sini -- bukan di caller yang bisa terlupa.
     */
    #[Test]
    public function a_rack_label_always_carries_the_rk_prefix(): void
    {
        $fromBare = $this->renderer->renderRack('A-01-03', LabelTemplate::ThreeByTwo);
        $fromPrefixed = $this->renderer->renderRack('RK:A-01-03', LabelTemplate::ThreeByTwo);

        $this->assertStringContainsString('RK:A-01-03', $fromBare);

        // Tidak boleh jadi RK:RK:A-01-03 kalau pemanggil sudah memberikannya.
        $this->assertStringNotContainsString('RK:RK:', $fromPrefixed);
    }

    /**
     * Isi label masuk ke HTML. Nama produk dari katalog adalah input bebas,
     * jadi harus di-escape; kalau tidak, karakter `<` dan `&` di nama produk
     * bisa merusak layout label atau menyisipkan markup.
     */
    #[Test]
    public function values_from_the_catalog_are_escaped(): void
    {
        $html = $this->renderer->render(
            $this->content(['product_name' => 'Ferrari <script>alert(1)</script>']),
            LabelTemplate::ThreeByTwo,
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * Setiap ukuran yang ditulis ke CSS harus berasal dari `LabelGeometry`.
     *
     * Ruang label dihitung dari angka yang sama sebelum dirender, lalu angka itu
     * ditulis ulang ke CSS supaya ikut ke kertas apa adanya. Dua penulisan dari
     * satu sumber hanya aman kalau tidak ada yang berubah di antara keduanya.
     *
     * Dan ada yang berubah: pembulatan ke dua desimal. Baris SKU 0,15 cm bisa
     * keluar sebagai 0,15 cm, tapi angka dengan desimal ketiga -- misalnya
     * 0,504 cm -- akan dibulatkan ke atas, 0,01 mm lebih besar dari yang
     * dianggarkan. Di label 15 mm itu tidak terlihat dari bentuknya; yang
     * terlihat hanya teks yang menutupi quiet zone QR dan pemindaian yang
     * gagal tanpa sebab yang bisa ditebak.
     *
     * Jadi yang diperiksa di sini bukan "font-nya cukup kecil", melainkan
     * "font-nya angka yang memang dikenal geometry". Pembulatan ke atas tidak
     * akan cocok dengan satu pun angka itu, sehingga tidak bisa lolos.
     */
    #[Test]
    public function every_written_font_size_is_a_number_the_geometry_declares(): void
    {
        foreach ([LabelTemplate::QrOnly, LabelTemplate::ThreeByTwo, LabelTemplate::FourByThree] as $template) {
            $html = $this->renderer->render($this->content(), $template);
            preg_match_all('/font-size:([0-9.]+)cm/', $html, $matches);

            $this->assertNotEmpty($matches[1], "{$template->name} harus menulis ukuran fontnya sendiri.");

            $declared = $this->declaredSizes($template);

            foreach ($matches[1] as $written) {
                $this->assertContains(
                    $written,
                    $declared,
                    "{$template->name}: {$written} cm bukan angka yang dideklarasikan geometry.",
                );
            }
        }
    }

    /**
     * Semua angka yang boleh keluar sebagai `font-size`, persis seperti ditulis
     * renderer: empat desimal, dipangkas, dan tidak pernah lebih besar dari
     * nilai aslinya.
     *
     * @return list<string>
     */
    private function declaredSizes(LabelTemplate $template): array
    {
        $values = [
            LabelGeometry::QR_ONLY_SKU_FONT_CM,
            LabelRow::CAPTION_FONT_SIZE_CM,
        ];

        foreach (LabelGeometry::forSku($template)->rows as $row) {
            $values[] = $row->fontSizeCm;
        }

        return array_values(array_unique(array_map($this->flooredCm(...), $values)));
    }

    private function flooredCm(float $value): string
    {
        return rtrim(rtrim(number_format(floor($value * 10_000) / 10_000, 4, '.', ''), '0'), '.');
    }

    /**
     * Font baris SKU harus persis angka di `LabelGeometry`.
     *
     * Angka ini menentukan apakah barisnya masih muat di bawah QR. Kalau
     * renderer menuliskan versinya sendiri, tidak ada yang lagi bisa
     * membuktikan ruang sisa label benar-benar memuatinya -- persis yang
     * terjadi ketika font dibulatkan ke atas, membuat teks memakai ruang yang
     * tidak pernah dianggarkan dan menimpa quiet zone QR.
     */
    #[Test]
    public function the_qr_only_sku_line_uses_the_font_size_the_geometry_budgeted(): void
    {
        $html = $this->renderer->render($this->content(), LabelTemplate::QrOnly);

        // Satu angka yang sama untuk dua baris: SKU dan harga.
        $font = $this->flooredCm(LabelGeometry::QR_ONLY_SKU_FONT_CM);

        $this->assertStringContainsString(
            'font-size:'.$font.'cm',
            $html,
        );
        $this->assertStringContainsString(
            'class="label__qr-price" style="font-size:'.$font.'cm"',
            $html,
            'Baris harga harus memakai font yang sama dengan SKU.',
        );
    }
}
