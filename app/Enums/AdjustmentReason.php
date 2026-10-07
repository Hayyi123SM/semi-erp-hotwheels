<?php

namespace App\Enums;

enum AdjustmentReason: string
{
    case Lost = 'LOST';
    case Damaged = 'DAMAGED';
    case Found = 'FOUND';
    case Miscount = 'MISCOUNT';
    case Reclass = 'RECLASS';

    /**
     * Alasan dalam bahasa sehari-hari untuk pilihan di form review.
     *
     * Kode tetap yang tersimpan di `opname_lines.reason` dan di gerakan stok;
     * yang berubah hanya cara membacanya di layar.
     */
    public function label(): string
    {
        return match ($this) {
            self::Lost => 'Hilang',
            self::Damaged => 'Rusak',
            self::Found => 'Ditemukan',
            self::Miscount => 'Salah hitung',
            self::Reclass => 'Reklasifikasi kepemilikan',
        };
    }
}
