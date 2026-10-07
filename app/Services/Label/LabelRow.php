<?php

declare(strict_types=1);

namespace App\Services\Label;

/**
 * Satu baris teks di dalam label, beserta anggaran ruangnya.
 *
 * `maxLines` membatasi berapa baris yang boleh dipakai baris ini, dan angka itu
 * yang dipatuhi CSS lewat kelas `label__value--wrap` -- bukan sebaliknya.
 *
 * Dulu tidak begitu. `.label--compact .label__value` memaksa `white-space:
 * nowrap` dengan specificity (0,2,0) dan mengalahkan `.label__value--sku`
 * (0,1,0), sehingga SKU yang sudah dianggarkan dua baris di `LabelGeometry`
 * tetap tercetak satu baris dan terpotong di tengah. Sekarang stylesheet tidak
 * lagi memutuskan sendiri; `LabelRow` yang menentukan.
 */
final readonly class LabelRow
{
    /**
     * Ukuran font keterangan kolom.
     *
     * Dikecilkan dari 0,26 cm karena keterangan itu penanda, bukan isi yang
     * dicari orang: yang dicari adalah nilai di sebelahnya. Ruang yang dipinjam
     * dari keterangan jauh lebih berharga daripada yang hilang darinya.
     *
     * Angka ini ikut menentukan kapasitas baris (lihat `captionWidthCm()`),
     * jadi mengubahnya mengubah hitungan seluruh label -- dan
     * `LabelGeometryTest` akan gagal kalau hasilnya tidak muat.
     */
    public const float CAPTION_FONT_SIZE_CM = 0.22;

    /**
     * Jarak antar huruf keterangan. Di CSS ini `letter-spacing`, jadi satu
     * tambahan untuk setiap karakter.
     */
    public const float CAPTION_LETTER_SPACING_CM = 0.01;

    /**
     * Perkiraan lebar satu karakter font teks biasa, terhadap ukuran font.
     *
     * Angka ini adalah perkiraan, bukan hasil pengukuran: 0,55 diambil dari
     * lebar rata-rata huruf kecil di DejaVu Sans dan Arial. Karena jadi dasar
     * keputusan memotong atau tidak, renderer juga memotong teks secara
     * eksplisit -- jadi kalau perkiraannya meleset, yang terjadi adalah teks
     * terpotong dengan titik tiga yang disengaja dan teruji, bukan ellipsis
     * diam-diam dari CSS.
     */
    public const float CHAR_WIDTH_RATIO = 0.55;

    public function __construct(
        public float $fontSizeCm,
        public float $lineHeight,
        public int $maxLines,
        public int $weight = 400,
        public bool $mono = false,
        /**
         * Keterangan kolom. Kosong pada layout kecil, yang tidak punya
         * keterangan karena kolom teksnya terlalu sempit.
         */
        public string $caption = '',
    ) {}

    public function heightCm(): float
    {
        return $this->fontSizeCm * $this->lineHeight * $this->maxLines;
    }

    /**
     * Ruang yang dipakai keterangan di lebar kolom teks.
     *
     * `.label__caption` memakai `flex: none`, jadi keterangan benar-benar
     * memakan lebar dan nilai di sebelahnya tinggal sisanya. Dulu lebar ini
     * tidak dihitung sama sekali: `capacityFor()` memakai seluruh lebar kolom
     * teks, padahal dicetaknya keterangan sudah memakan sebagian. Akibatnya
     * semua test hijau tapi label 4x3 tercetak `Rp100.0...` -- harga yang
     * salah, bukan sekadar kecil.
     */
    public function captionWidthCm(): float
    {
        if ($this->caption === '') {
            return 0.0;
        }

        return mb_strlen($this->caption) * (
            self::CAPTION_FONT_SIZE_CM * self::CHAR_WIDTH_RATIO
            + self::CAPTION_LETTER_SPACING_CM
        );
    }

    /**
     * Apakah baris ini boleh memakai lebih dari satu baris teks.
     *
     * Renderer memakainya untuk memutuskan kelas CSS, supaya teks boleh
     * membungkus hanya di baris yang `LabelGeometry` memang menganggarkan
     * ruangnya. Baris harga tetap satu baris: pemengatan di tengah angka
     * membuat harga salah dibaca.
     */
    public function wraps(): bool
    {
        return $this->maxLines > 1;
    }

    /**
     * Perkiraan lebar satu karakter. Monospace lebih lebar daripada font teks
     * biasa, jadi angka dipisah supaya pemangkasan nama produk tidak
     * membuang ruang.
     */
    public function charWidthRatio(): float
    {
        return $this->mono ? 0.60 : self::CHAR_WIDTH_RATIO;
    }
}
