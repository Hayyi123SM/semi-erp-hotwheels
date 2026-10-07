<?php

namespace App\Services\Pos;

use RuntimeException;

/**
 * Dilempar saat shift yang akan ditutup sudah tertutup.
 *
 * Bedanya dari `ShiftAlreadyOpenException` penting untuk pesan yang muncul di
 * browser. Di situ, "shift ini sudah ditutup" adalah kabar baik: uang laci sudah
 * tercatat, dan yang perlu dilakukan kasir adalah membuka shift baru untuk
 * penjualan berikutnya. Menanganinya seperti kesalahan akan membuat kasir mengulang
 * tutup shift dan mencari uang yang sudah tercatat.
 *
 * `@see ShiftService::close()` mengunci barisnya sebelum membaca status, jadi
 * dua permintaan tutup yang datang bersamaan menghasilkan satu penutupan dan satu
 * exception ini -- bukan dua penutupan dengan angka yang berbeda.
 */
class ShiftAlreadyClosedException extends RuntimeException
{
    public function __construct(string $message = 'Shift ini sudah ditutup.')
    {
        parent::__construct($message);
    }
}
