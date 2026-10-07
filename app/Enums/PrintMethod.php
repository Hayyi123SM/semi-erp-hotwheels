<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cara dokumen dikirim ke printer.
 *
 * - `Browser`: dialog cetak browser (`window.print()`). Browser yang bicara ke
 *   printer lewat driver sistem, termasuk printer Bluetooth yang sudah
 *   terpasang. Cara lama, dan satu-satunya yang dulu dipakai aplikasi.
 * - `Thermal`: byte ESC/POS ditulis langsung ke printer tanpa dialog. Aplikasi
 *   sendiri yang menyusun dan mengirimkan isi struk, jadi tidak ada jendela
 *   cetak yang muncul.
 *
 * Default-nya `Browser`. Itu satu-satunya cara yang dipakai sistem sebelum fitur
 * ini ada, jadi instalasi lama tidak ikut berubah hanya karena Owner membuka
 * halaman Pengaturan.
 *
 * Nilai disimpan sebagai setting, jadi nilai `case` adalah format yang tersimpan
 * di database -- persis seperti `PaperSize`. Mengubahnya berarti setting lama
 * menjadi nilai yang tidak dikenal.
 */
enum PrintMethod: string
{
    case Browser = 'browser';
    case Thermal = 'thermal';

    public function label(): string
    {
        return match ($this) {
            self::Browser => 'Cetak lewat browser (dialog cetak)',
            self::Thermal => 'Cetak thermal (langsung ke printer)',
        };
    }

    /**
     * Penjelasan untuk halaman Pengaturan.
     */
    public function description(): string
    {
        return match ($this) {
            self::Browser => 'Browser membuka dialog cetaknya sendiri. Satu-satunya cara yang bekerja untuk semua printer, tetapi butuh satu klik lagi.',
            self::Thermal => 'Dokumen dikirim langsung ke printer struk ESC/POS tanpa dialog. Butuh printer struk yang mendukung perintah ESC/POS dan terhubung dari perangkat yang mencetak.',
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $method): array => [
                'value' => $method->value,
                'label' => $method->label(),
                'description' => $method->description(),
            ],
            self::cases(),
        );
    }

    /**
     * Ubah nilai mentah dari setting jadi enum, atau null kalau tidak dikenal.
     *
     * Sama seperti `PaperSize::fromSetting()`: setting ini teks bebas, jadi bisa
     * berisi nilai lama atau salah ketik. Nilai yang tidak dikenal menghasilkan
     * null, bukan `ValueError` di tengah halaman cetak.
     */
    public static function fromSetting(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
