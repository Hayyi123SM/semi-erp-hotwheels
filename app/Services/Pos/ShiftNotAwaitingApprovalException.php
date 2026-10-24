<?php

namespace App\Services\Pos;

use RuntimeException;

/**
 * Dilempar saat Owner menyetujui atau menolak shift yang tidak sedang menunggu.
 *
 * Dua orang bisa menekan setuju dan tolak hampir bersamaan, atau satu tab
 * dibiarkan terbuka setelah shiftnya sudah diputuskan. Keputusan kedua tidak
 * boleh diam-diam menimpa yang pertama, jadi baris barunya ditolak.
 */
class ShiftNotAwaitingApprovalException extends RuntimeException
{
    public function __construct(string $message = 'Shift ini tidak sedang menunggu persetujuan. Muat ulang halaman untuk melihat keputusan terakhir.')
    {
        parent::__construct($message);
    }
}
