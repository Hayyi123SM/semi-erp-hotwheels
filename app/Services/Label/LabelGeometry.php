<?php

declare(strict_types=1);

namespace App\Services\Label;

/**
 * Anggaran ruang label dalam sentimeter.
 *
 * Ukuran font label dulunya hanya ada di stylesheet, sehingga tidak ada yang
 * bisa mengecek apakah teksnya benar-benar muat di 3x2 cm. Setelah dihitung,
 * layout 3x2 cm memuat 2,01 cm teks di ruang yang hanya 1,80 cm -- teksnya
 * tergulir keluar stiker dan tercetak terpotong, tanpa ada test yang gagal.
 *
 * Jadi angkanya dipindah ke sini: satu tempat, dipakai renderer untuk menulis
 * ukuran, lalu diuji oleh test yang menghitung ulang pertidaksamaan "baris
 * muat di dalam label". Layout yang meluap akan membuat test merah, bukan
 * ditemukan operator di depan printer.
 *
 * Semua satuan sentimeter, sama dengan satuan yang dipakai printer thermal.
 */
final readonly class LabelGeometry
{
    /**
     * Kode rak terpanjang yang masih bisa tercetak utuh, termasuk prefix `RK:`.
     *
     * Dipakai sebagai batas keras, bukan sekadar saran: label rak yang terpotong
     * jadi lebih berbahaya daripada tidak ada label sama sekali, karena dua rak
     * bersebelahan bisa tampil sama persis lalu isinya tertukar saat put-away.
     */
    public const int LONGEST_RACK_CODE = 15;

    /**
     * Ukuran font terkecil yang masih dibaca orang di printer thermal.
     *
     * 0,20 cm sama dengan 5,7 pt. Di bawah ini modul yang tercetak terlalu rapat
     * dan operator mulai salah baca -- termasuk salah baca angka, yang jauh lebih
     * berbahaya daripada tidak terbaca. Batas ini dipakai test untuk menahan
     * `LabelRow` yang diperkecil terlalu jauh, jadi "perkecil font asalkan
     * terbaca" punya angka, bukan Taste.
     */
    public const float MIN_READABLE_FONT_CM = 0.20;

    /**
     * Panjang isi terburuk yang boleh sampai ke printer untuk setiap baris.
     *
     * Ini batas keras yang berasal dari data, bukan dari tebakan: SKU 15
     * karakter (regex `SkuService`), nama produk 24 karakter
     * (`LabelContent::shortProductName`), kondisi + pemilik 11 karakter
     * ("NM/CL CN999"), dan harga 13 karakter ("Rp100.000.000", batas atas
     * `items.*.list_price`).
     *
     * Dipakai test untuk memaksa tiap baris benar-benar memuat isinya. Dulu
     * test hanya menghitung ulang angka dari `LabelGeometry` sendiri -- jadi
     * begitu `capacityFor()` salah hitung (tidak memotong ruang keterangan),
     * test-nya ikut salah dan semuanya tetap hijau.
     */
    public const array WORST_CASE_VALUES = [
        'sku' => 15,
        'product' => 24,
        'condition' => 11,
        'price' => 13,
    ];

    /**
     * Sisi QR maksimum pada template QR-only, dalam sentimeter.
     *
     * Angka ini hasil sisa ruang, bukan pilihan bulat. Label QR-only 1,5 cm
     * dengan padding 0,06 cm per sisi menyisakan 1,38 cm. Dua baris teks di
     * bawah QR -- SKU dan harga, masing-masing setinggi 0,125 cm --
     * membutuhkan 0,25 cm. Biar baris kedua tidak menimpa modul, ruang bawah
     * QR harus lebih dari itu: 0,86 cm dipakai supaya sisa
     * `(1,38 - 0,86) / 2 = 0,26 cm` masih menyisakan ~0,01 cm untuk bernapas
     * antara QR dan teks.
     *
     * Mengecilkan QR untuk dua baris menurunkan ukuran modul: dari 0,362 mm
     * (sisi 1,05 cm, ~2,9 dot) menjadi 0,297 mm (sisi 0,86 cm, ~2,4 dot pada
     * printer 203 dpi). Ini harga yang diterima sejak harga ikut dicetak di
     * sini -- pemindaian sedikit lebih peka jarak, tapi isi label tidak lagi
     * menyembunyikan harga yang bisa diperiksa ulang di rak.
     *
     * Dipakai juga sebagai plafon ketika Owner mengetik sisi QR sendiri di
     * Pengaturan. Tanpa plafon ini, satu angka yang benar untuk label 3x2 dan
     * 4x3 (misalnya 1,24 cm) akan menutupi SKU dan harga pada QR-only yang
     * halamannya cuma 15 mm -- dan kerusakannya tidak terlihat di layar.
     */
    public const float QR_ONLY_MAX_SIDE_CM = 0.86;

    /**
     * Ukuran font baris teks di bawah QR pada label QR-only, dalam sentimeter.
     *
     * Dipakai untuk kedua barisnya -- SKU dan harga -- supaya anggaran ruang
     * vertikal tunggal: dua baris = 2 x 0,125 cm = 0,25 cm, dan
     * `LabelGeometryTest` membuktikan angka itu masih muat di bawah QR-only
     * yang sudah dikecilkan. Berada di sini, bukan di CSS, karena ruang label
     * QR-only tinggal beberapa milimeter dan harus bisa diuji: kalau angkanya
     * hanya hidup di `label__qr-sku`, tidak ada test yang bisa membuktikan
     * baris itu masih muat di bawah QR yang baru saja diperkecil.
     */
    public const float QR_ONLY_SKU_FONT_CM = 0.125;

    /**
     * Lantai ukuran modul QR pada label QR-only, dalam milimeter.
     *
     * Satu modul = sisi QR dibagi 29 (21 modul + 4 quiet zone per sisi, versi
     * 1). Dulu lantainya 0,35 mm (2,8 dot); sejak harga ikut dicetak, QR-only
     * menyusut ke 0,86 cm = 0,297 mm (2,4 dot pada 203 dpi), jadi lantainya
     * diturunkan ke 0,29 mm. Yang dijaga test bukan "sekecil mungkin", tapi
     * "jangan ikut menyusut diam-diam": kalau orang mengecilkan QR sekali lagi
     * demi baris baru, test ini merah.
     */
    public const float QR_ONLY_MIN_MODULE_MM = 0.29;

    /**
     * @param  list<LabelRow>  $rows
     * @param  float|null  $maxQrSideCm  plafon sisi QR template ini, `null`
     *                                   kalau yang membatasi hanya ukuran label
     */
    private function __construct(
        public float $widthCm,
        public float $heightCm,
        public float $paddingCm,
        public float $gutterCm,
        public float $qrSideCm,
        public array $rows,
        public ?float $maxQrSideCm = null,
    ) {}

    /**
     * Geometry label barang.
     *
     * `$qrSideOverride` datang dari pengaturan printer. Nilainya sudah lolos
     * validasi rentang dan uji muat di `SavePrinterSettingsRequest`, jadi di
     * sini tidak perlu diperiksa lagi -- tapi tetap ada penjaga, karena
     * pemanggil lain (test, nation's renderer langsung) tidak lewat form.
     *
     * Override diabaikan kalau template memang tidak punya QR: template rak
     * 3x2 sengaja membuang QR demi kode rak yang terbaca, dan angka Owner tidak
     * boleh membatalkannya.
     */
    public static function forSku(LabelTemplate $template, ?float $qrSideOverride = null): self
    {
        return self::withQrOverride(match ($template) {
            /**
             * 1,5 x 1,5 cm: QR dengan SKU dan harga di bawahnya.
             *
             * Label ini kecil karena teksnya dibatasi dua baris, bukan karena
             * semuanya diperkecil. Karena itu QR masih dapat hampir seluruh
             * label; modulnya turun ke 0,297 mm (lihat `QR_ONLY_MAX_SIDE_CM`)
             * tapi tetap lebih besar daripada di 3x2.
             *
             * Angka 1,38 cm dihitung dari payload terpanjang yang nyata
             * (`CN01-HW-001-U03`, 15 byte, QR versi 1 = 21 modul + 8 quiet zone
             * = 29 modul): 13,8 mm / 29 = 0,476 mm per modul, atau 3,8 dot pada
             * printer 203 dpi. QR di 3x2 hanya 2,9 dot.
             *
             * `rows: []` adalah inti template ini: produk dan kondisi tidak
             * punya tempat di sini; yang tercetak hanya QR, satu baris SKU,
             * dan harga -- semuanya digambar renderer. SKU dan harga memakai
             * `LabelGeometry::QR_ONLY_SKU_FONT_CM` (dua baris = 0,25 cm),
             * bukan lewat daftar baris. Karena itu `textWidthCm()` dan
             * `capacityFor()` tidak punya arti di sini dan tidak boleh
             * dipanggil. `LabelGeometryTest` menahan QR + padding supaya
             * tidak pernah melebihi lebar label, dan menahan ruang dua baris
             * itu supaya tidak pernah tertutup QR yang terlalu besar.
             */
            LabelTemplate::QrOnly => new self(
                widthCm: 1.5,
                heightCm: 1.5,
                // 0,06 cm, bukan 0,08 seperti label lain: pada label sekecil ini
                // setiap 0,1 cm padding langsung memotong QR yang justru dijaga
                // sebesar mungkin supaya tetap mudah discan.
                paddingCm: 0.06,
                gutterCm: 0.0,
                qrSideCm: self::QR_ONLY_MAX_SIDE_CM,
                rows: [],
                maxQrSideCm: self::QR_ONLY_MAX_SIDE_CM,
            ),

            /**
             * 3x2 cm hanya 6 cm persegi, dan QR memakan hampir seperempatnya.
             * Sisa kolom teks sekitar 1,7 cm, jadi isinya ditumpuk tanpa
             * keterangan kolom: keterangan justru membuat teks mengecil
             * sampai tidak terbaca. Baris paling bawah (harga) boleh hilang
             * kalau `show_price` mati.
             */
            LabelTemplate::ThreeByTwo => new self(
                widthCm: 3.0,
                heightCm: 2.0,
                paddingCm: 0.08,
                gutterCm: 0.10,
                qrSideCm: 1.05,
                rows: [
                    new LabelRow(0.30, 1.00, maxLines: 2, weight: 700, mono: true),
                    // Nama produk dua baris, bukan satu. Kolom teks 1,69 cm
                    // hanya memuat 12 karakter pada 0,24 cm, sedangkan
                    // `shortProductName` sending sampai 24 -- jadi dengan satu
                    // baris, nama produk selalu tercetak `Porsche 911 G...`.
                    // Dua baris memuat 24 karakter penuh dan biayanya hanya
                    // 0,25 cm: tinggi semua baris jadi 1,69 cm dari 1,84 cm
                    // yang tersedia.
                    new LabelRow(0.24, 1.05, maxLines: 2),
                    new LabelRow(0.24, 1.05, maxLines: 1),
                    // 0,23 cm, bukan 0,28. Kolom teks 1,69 cm hanya memuat
                    // 13 karakter pada 0,23, dan `Rp100.000.000` -- harga
                    // tertinggi yang masih boleh diisi di inbound -- tepat
                    // 13 karakter. Pada 0,28 kolomnya cuma 10, jadi harga
                    // tercetak `Rp100.000.…`: angka yang salah, bukan sekadar
                    // kecil. Harga tidak boleh melebar, jadi baris ini yang
                    // diturunkan, bukan lebar label.
                    new LabelRow(0.23, 1.05, maxLines: 1, weight: 700),
                ],
            ),

            /**
             * 4x3 cm: cukup untuk kelima isi label pada §1.4.2 sekaligus dengan
             * QR -- SKU, produk, kondisi, pemilik, dan harga. Semua baris muat
             * satu baris karena kolom teksnya lebih lebar.
             */
            LabelTemplate::FourByThree => new self(
                widthCm: 4.0,
                heightCm: 3.0,
                paddingCm: 0.10,
                gutterCm: 0.12,
                qrSideCm: 1.45,
                rows: [
                    // SKU 15 karakter tetap butuh dua baris bahkan di label
                    // besar: kolom teksnya hanya 2,23 cm, dan mengecilkan font
                    // sampai muat satu baris membuat identitas unit sulit
                    // dibaca. Dua baris di sini justru lebih terbaca.
                    new LabelRow(fontSizeCm: 0.34, lineHeight: 1.05, maxLines: 2, weight: 700, mono: true, caption: 'SKU'),
                    // Tiga baris, karena keterangan "PRODUK" memakan 0,79 cm
                    // dari kolom teks 2,23 cm. Yang tersisa hanya 1,44 cm, dan
                    // `shortProductName` bisa 24 karakter -- satu baris cuma
                    // memuat 7 dan namanya jadi tidak berguna.
                    new LabelRow(fontSizeCm: 0.28, lineHeight: 1.05, maxLines: 3, caption: 'PRODUK'),
                    new LabelRow(fontSizeCm: 0.28, lineHeight: 1.05, maxLines: 1, caption: 'KONDISI'),
                    new LabelRow(fontSizeCm: 0.28, lineHeight: 1.05, maxLines: 1, caption: 'PEMILIK'),
                    // Tanpa keterangan, dan ini keputusan yang bayar dirinya
                    // sendiri: kolom teks jadi penuh 2,23 cm, jadi font harga
                    // bisa 0,30 cm -- bukan 0,22 cm yang dipaksakan ketika
                    // keterangan "HARGA" memakan 0,66 cm dari lebar itu.
                    //
                    // Alasannya keterangan "HARGA" tidak perlu: nilainya berawalan
                    // "Rp", jadi orang tahu itu harga tanpa diberi tahu. Dan
                    // lebar yang dibebaskan ini juga yang membuat sisi QR dari
                    // pengaturan bisa naik sampai 1,48 cm tanpa harga terpotong.
                    new LabelRow(fontSizeCm: 0.30, lineHeight: 1.05, maxLines: 1, weight: 700),
                ],
            ),
        }, $qrSideOverride);
    }

    /**
     * Geometry label rak.
     *
     * Pengaturan ukuran QR Owner berlaku di sini juga, dengan satu pengecualian
     * yang sudah dijelaskan di `forSku()`: template yang `qrSideCm`-nya 0 tetap
     * tidak bisa dapat QR dari pengaturan.
     */
    public static function forRack(LabelTemplate $template, ?float $qrSideOverride = null): self
    {
        return self::withQrOverride(match ($template) {
            /**
             * 1,5 x 1,5 cm untuk rak: QR saja.
             *
             * Berlawanan dengan 3x2 di bawah yang sengaja membuang QR demi
             * kode rak yang terbaca mata, di sini tidak ada teks yang harus
             * dipertahankan -- jadi QR mengisi label, dan rak sempit yang
             * hanya menyimpan kode bisa tetap discan seperti label lot.
             *
             * Geometrinya sama persis dengan QR-only SKU: keduanya memakai
             * angka QR yang sama di stiker sebesar ini (label rak tidak punya
             * teks di bawah QR, label SKU menggambar SKU dan harga lewat
             * renderer, bukan lewat geometry), jadi angka yang sama dipakai
             * dua kali (`LabelGeometryTest` menahan keduanya).
             */
            LabelTemplate::QrOnly => new self(
                widthCm: 1.5,
                heightCm: 1.5,
                paddingCm: 0.06,
                gutterCm: 0.0,
                qrSideCm: self::QR_ONLY_MAX_SIDE_CM,
                rows: [],
                maxQrSideCm: self::QR_ONLY_MAX_SIDE_CM,
            ),

            /**
             * 3x2 cm: 3 cm lebar tidak bisa memuat QR yang bisa discan *dan*
             * kode rak yang masih terbaca manusia. Setelah QR 0,9 cm dan
             * padding dipotong, kolom teks tinggal ~1,86 cm; kode 15 karakter
             * di sana butuh font ~0,2 cm (5,7 pt) yang tidak terbaca di rak.
             *
             * Jadi label kecil tetap tanpa QR, tapi fontnya diturunkan supaya
             * kode tercetak utuh. Label rak yang butuh QR harus pakai 4x3.
             */
            LabelTemplate::ThreeByTwo => new self(
                widthCm: 3.0,
                heightCm: 2.0,
                paddingCm: 0.08,
                gutterCm: 0.0,
                qrSideCm: 0.0,
                rows: [new LabelRow(0.40, 1.10, maxLines: 2, weight: 700, mono: true, caption: 'RAK')],
            ),

            /**
             * 4x3 cm: satu-satunya label rak yang bisa membawa QR (FR-MD-21)
             * tanpa mengorbankan keterbacaan kode. Kolom teks 2,45 cm memberi
             * 9 karakter per baris x 2 baris = 18, cukup untuk kode 15
             * karakter terpanjang yang didukung.
             *
             * QR-only 1,5 cm di atas juga membawa QR, tapi tanpa kode rak. Itu
             * bukan pengganti, melainkan tambahan untuk rak sempit yang isinya
             * sudah diketahui dari sistem.
             */
            LabelTemplate::FourByThree => new self(
                widthCm: 4.0,
                heightCm: 3.0,
                paddingCm: 0.10,
                gutterCm: 0.10,
                qrSideCm: 1.25,
                // 0,38 cm, bukan 0,42 cm. Keterangan "RAK" memakan 0,39 cm,
                // jadi pada 0,42 cm hanya 7 karakter per baris = 14, dan kode
                // 15 karakter ke-15 terpotong -- dua rak bersebelahan bisa
                // tampil sama persis. Pada 0,38 cm muat 9 per baris = 18.
                // Efek sampingnya bagus: QR dari pengaturan bisa naik sampai
                // 1,48 cm tanpa kode rak ikut terpotong.
                rows: [new LabelRow(0.38, 1.10, maxLines: 2, weight: 700, mono: true, caption: 'RAK')],
            ),
        }, $qrSideOverride);
    }

    /**
     * Terapkan sisi QR dari pengaturan Owner ke geometry yang sudah jadi.
     *
     * Aturannya dua, dan keduanya sengaja ditegakkan di sini satu-satunya
     * tempat, bukan di setiap pemanggil:
     *
     * 1. Geometry yang tadinya tidak punya QR (`qrSideCm` 0) tetap tidak punya
     *    QR. Label rak 3x2 membuang QR supaya kode raknya tetap terbaca, dan
     *    pengaturan umum tidak boleh membatalkan keputusan itu -- kalau boleh,
     *    setiap Owner akan menemukan sendiri label rak yang kode terpotong.
     *
     * 2. Override tidak boleh membuat QR lebih besar dari label setelah
     *    padding. Form sudah menolaknya, tapi geometry juga dipanggil langsung
     *    oleh renderer dan test; penjaga di sini memastikan tidak ada jalan
     *    yang melewati validasi.
     */
    private static function withQrOverride(self $geometry, ?float $qrSideOverride): self
    {
        if ($qrSideOverride === null || $qrSideOverride <= 0.0) {
            return $geometry;
        }

        if ($geometry->qrSideCm <= 0.0) {
            return $geometry;
        }

        $largestSide = min($geometry->widthCm, $geometry->heightCm) - (2 * $geometry->paddingCm);

        if ($geometry->maxQrSideCm !== null) {
            $largestSide = min($largestSide, $geometry->maxQrSideCm);
        }

        if ($qrSideOverride > $largestSide) {
            // Diabaikan, dan pemanggilnya yang melaporkan -- lihat
            // `LabelPrinterSettings::warnIfQrIsIgnored()`. Sengaja tidak
            // dilog dari sini: kelas ini nilai murni tanpa framework, dan
            // test unit memakainya tanpa aplikasi yang hidup.
            return $geometry;
        }

        return new self(
            widthCm: $geometry->widthCm,
            heightCm: $geometry->heightCm,
            paddingCm: $geometry->paddingCm,
            gutterCm: $geometry->gutterCm,
            qrSideCm: round($qrSideOverride, 2),
            rows: $geometry->rows,
            maxQrSideCm: $geometry->maxQrSideCm,
        );
    }

    /**
     * Lebar kolom teks setelah padding, QR, dan ruang di antaranya dipotong.
     */
    public function textWidthCm(): float
    {
        return $this->widthCm - (2 * $this->paddingCm) - $this->qrSideCm - $this->gutterCm;
    }

    /**
     * Tinggi area teks yang boleh dipakai, termasuk ruang antargap.
     */
    public function textHeightCm(): float
    {
        return $this->heightCm - (2 * $this->paddingCm);
    }

    /**
     * Tinggi yang dibutuhkan semua baris.
     *
     * Jarak antargap ikut dihitung karena `justify-content` yang dipakai tidak
     * boleh membuat jarak melebihi yang ada di CSS.
     */
    public function requiredHeightCm(): float
    {
        $total = 0.0;
        $count = count($this->rows);

        foreach ($this->rows as $index => $row) {
            $total += $row->heightCm();

            if ($index < $count - 1) {
                $total += $this->rowGapCm();
            }
        }

        return $total;
    }

    /**
     * Sisa tinggi yang masih boleh dipakai. Nilai negatif berarti layout
     * meluap dan label akan tercetak terpotong.
     */
    public function spareHeightCm(): float
    {
        return $this->textHeightCm() - $this->requiredHeightCm();
    }

    public function fits(): bool
    {
        return $this->spareHeightCm() >= 0;
    }

    private function rowGapCm(): float
    {
        return $this->heightCm <= 2.5 ? 0.03 : 0.06;
    }

    /**
     * Lebar yang benar-benar tersedia untuk teks nilai di baris ini.
     *
     *Yang dipakai bukan seluruh `textWidthCm()`: keterangan kolom memakai
     * `flex: none`, jadi sebagian lebar kolom teks sudah dimakan keterangan dan
     * nilai tinggal sisanya.
     *
     * Ini akar bug harga terpotong di 4x3. `capacityFor()` dulu membagi seluruh
     * `textWidthCm()` dengan lebar karakter, padahal yang tercetak cuma sisa
     * setelah keterangan. Baris HARGA dikira berkapasitas 13 karakter
     * (`Rp100.000.000` muat, test hijau) sementara yang benar-benar muat 8 --
     * jadi harga tertinggi tercetak `Rp100.0...`, angka yang salah. Test-nya
     * tidak menangkap apa pun karena `LabelGeometryTest` menghitung ulang
     * angka dari `LabelGeometry` yang sama, jadi kesalahan mengplicate.
     */
    public function valueWidthCm(LabelRow $row): float
    {
        return max(0.0, $this->textWidthCm() - $row->captionWidthCm());
    }

    /**
     * Berapa karakter yang muat dalam seluruh baris milik satu baris label.
     *
     * Dipakai untuk menahan panjang kode rak sebelum dicetak. Label yang
     * terpotong lebih berbahaya daripada tidak tercetak: dua rak bersebelahan
     * bisa tampil sama persis lalu isinya tertukar saat put-away.
     */
    public function capacityFor(LabelRow $row): int
    {
        return $this->charsPerLine($row) * $row->maxLines;
    }

    /**
     * Berapa karakter yang muat dalam satu baris milik `row`.
     *
     * Parameter font dan rasio lebar tidak lagi bisa ditentukan pemanggil
     * sendiri. Itu justru celah yang dipakai selama ini: karena
     * `charsPerLine()` menerima dua `float` bebas, setiap pemanggil ikut
     * memotong lebar dengan aturan yang salahnya juga. Sekarang satu-satunya
     * sumber kebenaran adalah `LabelRow`-nya, jadi tidak ada lagi cara menghitung
     * kapasitas yang berbeda dari yang dicetak renderer.
     *
     * Dipakai renderer untuk memotong teks secara eksplisit, dan test untuk
     * memeriksa bahwa isi label benar-benar muat.
     */
    public function charsPerLine(LabelRow $row): int
    {
        $charWidth = $row->fontSizeCm * $row->charWidthRatio();

        if ($charWidth <= 0.0) {
            return 0;
        }

        return max(1, (int) floor($this->valueWidthCm($row) / $charWidth));
    }
}
