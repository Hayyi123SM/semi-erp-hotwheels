<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ukuran kertas untuk dokumen yang dicetak.
 *
 * Mengganti `ReceiptPaper`: kertas bukan lagi milik bukti terima titipan, tapi
 * milik semua dokumen -- bukti terima sekarang, nota POS dan cetakan lain
 * kemudian. Nilai `case` tetap, jadi setting lama yang tersimpan di database
 * (`58mm`/`80mm`/`a4`) tetap terbaca tanpa migrasi. Mengubah nilai di sini akan
 * membuat setting lama menjadi nilai yang tidak dikenal, dan `fromSetting()`
 * akan mengembalikan null supaya halaman jatuh ke default, bukan ke ukuran
 * kertas yang tidak sengaja.
 */
enum PaperSize: string
{
    case Mm58 = '58mm';
    case Mm80 = '80mm';
    case A4 = 'a4';

    /**
     * Nilai untuk `<select>` dan tampilan ringkas.
     */
    public function label(): string
    {
        return match ($this) {
            self::Mm58 => 'Struk 58 mm',
            self::Mm80 => 'Struk 80 mm',
            self::A4 => 'A4',
        };
    }

    /**
     * Ukuran untuk `@page size`.
     *
     * Panjang `auto` untuk kertas thermal, bukan panjang tetap. Printer struk
     * menarik gulungan sejauh isinya dan memotong di ujungnya, jadi panjang
     * kertas ditentukan oleh isi struk. Kalau panjangnya dipatok, yang terpotong
     * adalah baris tanda tangan dan footer "dicetak", dan justru dua bagian itu
     * yang dipakai untuk membuktikan struk ini asli.
     */
    public function pageSizeCss(): string
    {
        return match ($this) {
            self::Mm58 => '58mm auto',
            self::Mm80 => '80mm auto',
            self::A4 => 'A4',
        };
    }

    /**
     * Lebar area cetak.
     *
     * Dipakai bersama `margin: 0` supaya isi tidak melewati area cetak dan tidak
     * memaksa browser membuat halaman kedua hanya karena satu huruf melewati
     * batas. A4 memakai lebar di bawah 210 mm karena `@page` A4 bawaan punya
     * margin, dan margin itu yang membuat isi melebar melewati halaman.
     */
    public function contentWidthCss(): string
    {
        return match ($this) {
            self::Mm58 => '58mm',
            self::Mm80 => '80mm',
            self::A4 => '190mm',
        };
    }

    /**
     * Thermal pakai satu kolom font monospace, A4 pakai tabel.
     *
     * Monospace dipakai untuk struk karena kolom angka harus sejajar untuk bisa
     * dibaca dan dihitung cepat. A4 bukan struk: isinya dokumen, dan tabel lebih
     * enak dibaca daripada baris teks yang membungkus.
     */
    public function isThermal(): bool
    {
        return $this !== self::A4;
    }

    /**
     * Jumlah kolom karakter yang muat di satu baris struk.
     *
     * Dipakai renderer ESC/POS: lebar 58 mm muat 32 karakter font A, lebar
     * 80 mm muat 48. Renderer yang mengikuti angka ini membuat kolom angka tidak
     * membungkus di tengah struk. `null` untuk A4, yang bukan struk dan tidak
     * punya renderer ESC/POS.
     */
    public function escposColumnWidth(): ?int
    {
        return match ($this) {
            self::Mm58 => 32,
            self::Mm80 => 48,
            self::A4 => null,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $paper): array => ['value' => $paper->value, 'label' => $paper->label()],
            self::cases(),
        );
    }

    /**
     * Ubah nilai mentah dari setting jadi enum, atau null kalau tidak dikenal.
     *
     * `tryFrom()`, bukan `from()`: setting ini teks bebas, jadi bisa berisi nilai
     * lama atau salah ketik. `from()` melempar `ValueError` di dalam halaman
     * cetak, dan halaman yang gagal dimuat adalah halaman yang sedang dipegang
     * Staff untuk menyerahkan barang ke penitip.
     */
    public static function fromSetting(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
