<?php

declare(strict_types=1);

namespace App\Services\Label;

use InvalidArgumentException;

/**
 * Ukuran label dalam sentimeter, sesuai FR-IB-15.
 *
 * Nilai sentimeter, bukan milimeter, karena seluruh spesifikasi di dokumen
 * ditulis dalam sentimeter./CSS `cm` menerima pecahan, jadi nilainya bisa
 * langsung dipakai tanpa konversi -- dan yang tersimpan di database tetap
 * `3x2` / `4x3` seperti di UI.
 */
enum LabelTemplate: string
{
    /**
     * QR dengan SKU dan harga di bawahnya, untuk rak sempit dan lot yang
     * dibaca dengan scanner.
     *
     * Nilai ditulis sebagai sentimeter desimal, bukan milimeter bulat, supaya
     * seragam dengan dua template lain: `widthCm()` dan `heightCm()` tetap bisa
     * memecah string dengan satu aturan untuk semua case.
     */
    case QrOnly = '1.5x1.5';

    case ThreeByTwo = '3x2';
    case FourByThree = '4x3';

    public function widthCm(): float
    {
        return (float) explode('x', $this->value)[0];
    }

    public function heightCm(): float
    {
        return (float) explode('x', $this->value)[1];
    }

    /**
     * Nama untuk ditampilkan di antarmuka.
     *
     * Diturunkan dari nilai enum, bukan ditulis manual per case: nama yang
     * tidak cocok dengan ukurannya akan muncul sebagai teks yang salah di
     * halaman pengaturan dan di dropdown cetak, lalu dipakai operator untuk
     * memilih kertas yang keliru.
     *
     * Nol di belakang koma dibuang supaya tertulis `4 x 3 cm`, bukan
     * `4,0 x 3,0 cm`. Tidak ada yang mencari label 4,0 cm di rak tempat
     * tumpukan stikernya bertuliskan 4 x 3.
     */
    public function label(): string
    {
        return sprintf(
            '%s x %s cm',
            $this->formatCm($this->widthCm()),
            $this->formatCm($this->heightCm()),
        );
    }

    /**
     * Ukuran halaman untuk `@page` pada mode gulungan, dalam CSS.
     *
     * Mode gulungan memakai ukuran label sebagai ukuran halaman: printer thermal
     * mencetak satu label satu halaman dan tidak ada lagi yang perlu membuat
     * halaman. Mode stiker memakai ukuran media kertasnya -- lihat
     * `SheetGrid::pageSizeCss()`.
     *
     * Pemisah desimal memakai titik, bukan koma seperti `formatCm()` di atas.
     * `formatCm()` sengaja memakai koma karena hasilnya untuk ditampilkan ke
     * orang; di sini hasilnya dibaca mesin, dan `1,5cm` tidak valid di CSS --
     * browser akan mengabaikannya lalu memakai ukuran kertas bawaan, yang
     * gejalanya label tercetak di pojok atas halaman A4.
     */
    public function pageSizeCss(): string
    {
        return $this->cssLength($this->widthCm(), 'cm').' '.$this->cssLength($this->heightCm(), 'cm');
    }

    /**
     * Panjang CSS tanpa nol di belakang koma: `4`, bukan `4,00` atau `4.00`.
     */
    private function cssLength(float $value, string $unit): string
    {
        $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $formatted === '' ? "0{$unit}" : "{$formatted}{$unit}";
    }

    /**
     * Sentimeter tanpa nol di belakang koma: `4`, bukan `4,00`.
     */
    private function formatCm(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }

    /**
     * Apa isi label ini, untuk dipilih di halaman pengaturan printer.
     */
    public function description(): string
    {
        return match ($this) {
            self::QrOnly => 'QR + SKU dan harga. Untuk rak sempit dan lot yang dibaca dengan scanner.',
            self::ThreeByTwo => 'QR + SKU, produk, kondisi, dan harga. Ukuran standar untuk label barang.',
            self::FourByThree => 'QR + isi lengkap dengan keterangan kolom. Untuk informasi yang lebih banyak.',
        };
    }

    /**
     * Sisi QR bawaan dari geometry, terpisah dari pengaturan Owner.
     *
     * Nilai ini ditampilkan di halaman pengaturan sebagai titik awal, bukan
     * sebagai angka yang sedang berlaku. Yang berlaku adalah hasil
     * `LabelPrinterSettings::qrSideCm()`.
     */
    public function defaultQrSideCm(): float
    {
        return LabelGeometry::forSku($this)->qrSideCm;
    }

    /**
     * Template yang dipakai saat label dibuat tanpa pilihan eksplisit.
     *
     * Disimpan di sini, bukan ditulis sebagai string `'3x2'` di beberapa
     * tempat, supaya penggantian template default cuma satu baris dan tidak
     * ada call site yang diam-diam masih memakai nilai lama.
     */
    public static function default(): self
    {
        return self::ThreeByTwo;
    }

    /**
     * Label rak tidak punya ukuran/template sendiri: ukuran yang sama, isi
     * yang berbeda. Yang mengendalikan jarak antar label adalah `$gutter`,
     * bukan template -- supaya 3x2 dan 4x3 bisa dicampur dalam satu lembar
     * dengan jarak yang tetap.
     *
     * Template QR-only memakai gutter paling rapat: label sekecil 1,5 cm tidak
     * menyisakan ruang untuk jarak antar label yang lebar. `match` di sini
     * eksklusif, jadi case baru wajib ikut diisi -- PHP fatal kalau ada
     * template tanpa gutter, dan itu memang lebih baik daripada jarak yang
     * diam-diam memakai default.
     */
    public function defaultGutterMm(): float
    {
        return match ($this) {
            self::QrOnly => 0.5,
            self::ThreeByTwo => 2.0,
            self::FourByThree => 2.5,
        };
    }

    /**
     * Template yang geometrinya tidak punya baris teks `rows: []`.
     *
     * Bukan berarti stiker ini kosong: SKU dan harga tetap tercetak, tapi
     * digambar renderer di bawah QR dari `LabelGeometry::QR_ONLY_SKU_FONT_CM`,
     * bukan lewat daftar baris. Renderer memintanya untuk melewati penghitung
     * baris
     * sepenuhnya: pada layout ini `$rows` memang kosong, jadi `capacityFor()`
     * tidak punya baris untuk dihitung dan tidak boleh dipanggil.
     */
    public function isQrOnly(): bool
    {
        return $this === self::QrOnly;
    }

    /**
     * Apakah label rak pada ukuran ini punya QR.
     *
     * Dijawab dari geometry, bukan dari daftar case di sini. Label rak 3x2
     * membuang QR supaya kode raknya tetap terbaca, dan itu keputusan milik
     * `LabelGeometry::forRack()` -- kalau daftar template QR di sini ditulis
     * ulang, halaman cetak bisa menawarkan ukuran yang geometry-nya sudah
     * berubah tanpa ada yang gagal.
     */
    public function hasRackQr(): bool
    {
        return LabelGeometry::forRack($this)->qrSideCm > 0.0;
    }

    /**
     * @throws InvalidArgumentException bila template tidak dikenal. Data dari
     *                                  database bisa saja rusak atau hasil editan
     *                                  lama, jadi nilai asing tidak boleh diam-diam
     *                                  jatuh ke default.
     */
    public static function parse(?string $value): self
    {
        $candidate = self::tryFrom((string) $value);

        if ($candidate === null) {
            throw new InvalidArgumentException("Template label tidak dikenal: '{$value}'.");
        }

        return $candidate;
    }
}
