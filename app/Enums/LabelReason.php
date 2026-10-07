<?php

namespace App\Enums;

enum LabelReason: string
{
    case Initial = 'INITIAL';
    case LabelDamaged = 'LABEL_DAMAGED';
    case LabelLost = 'LABEL_LOST';
    case Misprint = 'MISPRINT';
    case AdditionalUnits = 'ADDITIONAL_UNITS';
    case PriceChange = 'PRICE_CHANGE';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Label Awal',
            self::LabelDamaged => 'Label Rusak',
            self::LabelLost => 'Label Hilang',
            self::Misprint => 'Cetak Salah',
            self::AdditionalUnits => 'Unit Tambahan',
            self::PriceChange => 'Harga Berubah',
        };
    }

    /**
     * Alasan yang boleh dipilih saat membuat job cetak ulang.
     *
     * `INITIAL` sengaja tidak ada di sini: label awal dibuat otomatis saat
     * commit, bukan diminta operator. Kalau `INITIAL` bisa dipilih, `reprint_count`
     * akan naik untuk cetakan yang bukan cetakan ulang, dan laporan "berapa
     * label yang hilang" jadi tidak dipercaya.
     */
    public function isReprint(): bool
    {
        return $this !== self::Initial;
    }

    /**
     * Alasan yang berarti label lama hilang dari barang, bukan hanya perlu
     * diganti.
     *
     * Bedanya penting untuk karantina: `LABEL_LOST` dan `LABEL_DAMAGED`
     * berarti ada unit yang tidak bisa dipindai sampai label baru menempel,
     * sedangkan `MISPRINT` atau `PRICE_CHANGE` hanya soal informasi yang salah
     * baca dan barangnya tetap terpindai.
     *
     * @return array<int, string>
     */
    public static function reprints(): array
    {
        return array_values(array_map(
            static fn (self $reason): string => $reason->value,
            array_filter(self::cases(), static fn (self $reason): bool => $reason->isReprint()),
        ));
    }
}
