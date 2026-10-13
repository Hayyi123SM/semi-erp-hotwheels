<?php

namespace App\Enums;

/**
 * Alasan mengapa satu unit masuk karantina.
 *
 * Nilai tersimpan apa adanya (memakai value enum, bukan teks bebas) supaya
 * laporan "kenapa barang tidak teridentifikasi" bisa dipecah per penyebab,
 * bukan dibaca satu-satu dari kalimat orang.
 */
enum QuarantineReason: string
{
    case TanpaLabel = 'TANPA_LABEL';
    case BarcodeTidakTerbaca = 'BARCODE_TIDAK_TERBACA';
    case LabelRusak = 'LABEL_RUSAK';
    case DosPack = 'DOS_PACK';
    case BlisterRusak = 'BLISTER_RUSAK';

    public function label(): string
    {
        return match ($this) {
            self::TanpaLabel => 'Tanpa label',
            self::BarcodeTidakTerbaca => 'Barcode tidak terbaca',
            self::LabelRusak => 'Label rusak',
            self::DosPack => 'Dus produk (dos pack)',
            self::BlisterRusak => 'Blister rusak',
        };
    }
}
