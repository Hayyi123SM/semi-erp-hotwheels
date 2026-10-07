<?php

declare(strict_types=1);

namespace App\Services\Label;

/**
 * Satu perintah gambar pada label TSPL.
 *
 * Renderer label TSPL tidak langsung menulis teks perintah: hasilnya adalah
 * daftar operasi terstruktur. Alasan memakai bentuk perantara, bukan teks:
 * koordinat setiap operasi relatif terhadap pojok label sendiri, sedangkan
 * pada kertas stiker label digeser ke posisi kolom/barisnya. Kalau renderer
 * langsung menulis `TEXT` dengan koordinat mutlak, pemindahan label ke sel
 * grid berarti mengurai ulang teks perintah untuk menggeser angkanya -- dan
 * setiap rahasia pengurai adalah bug yang menunggu.
 *
 * Dengan operasi terstruktur, renderer cukup menambahkan `dx`/`dy` ke
 * koordinat yang sudah ada dan menulis baris TSPL yang benar. Kelas ini juga
 * menghapus perbedaan output QR (`QRCODE ...`) dan teks (`TEXT ...`) agar
 * pemanggil tidak perlu tahu sintaks TSPL.
 */
final readonly class TspLabelOp
{
    public const string QR = 'qr';

    public const string TEXT = 'text';

    /**
     * Lebar sel QR dalam piksel, kelipatan dari `QRCODE`.
     *
     * Diisi hanya pada operasi QR. Nilainya dipilih renderer dari ruang yang
     * tersedia; pemanggil (builder lembar) tidak boleh menentukan sendiri
     * karena itu artinya renderer dan builder menebak geometri dengan angka
     * yang berbeda.
     */
    public function __construct(
        public string $kind,
        public int $x,
        public int $y,
        public ?string $text = null,
        public ?int $cell = null,
        public string $font = '1',
        public int $multiplier = 1,
    ) {}

    public static function qr(int $x, int $y, int $cell, string $text): self
    {
        return new self(self::QR, $x, $y, text: $text, cell: $cell);
    }

    public static function text(int $x, int $y, string $text, string $font = '1', int $multiplier = 1): self
    {
        return new self(self::TEXT, $x, $y, text: $text, font: $font, multiplier: $multiplier);
    }

    public function withOffset(int $dx, int $dy): self
    {
        return new self(
            $this->kind,
            $this->x + $dx,
            $this->y + $dy,
            $this->text,
            $this->cell,
            $this->font,
            $this->multiplier,
        );
    }
}
