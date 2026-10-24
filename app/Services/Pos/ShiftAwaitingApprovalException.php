<?php

namespace App\Services\Pos;

use RuntimeException;

/**
 * Dilempar saat kasir mengirim hitungan laci untuk shift yang sudah diajukan.
 *
 * Angkanya sudah tersimpan dan sedang menunggu Owner. Menutupnya lagi akan
 * menimpa angka yang mungkin sudah dibaca Owner, dan mengirim kasir ke dialog
 * yang sama berulang kali tanpa menjelaskan bahwa bukan hitungannya yang salah.
 */
class ShiftAwaitingApprovalException extends RuntimeException
{
    public function __construct(string $message = 'Shift ini sedang menunggu persetujuan Owner. Hitungan laci yang baru tidak bisa dikirim sampai Owner memutuskan.')
    {
        parent::__construct($message);
    }
}
