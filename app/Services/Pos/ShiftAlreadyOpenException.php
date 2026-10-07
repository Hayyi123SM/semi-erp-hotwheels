<?php

namespace App\Services\Pos;

use App\Models\Shift;
use RuntimeException;

/**
 * Dilempar saat ada shift yang masih terbuka sehingga shift baru tidak boleh dibuat.
 *
 * Sifatnya exception, bukan redirect di dalam controller, karena syarat "tidak ada
 * shift terbuka" harus benar saat dibaca dan saat ditulis. Kalau pemeriksaannya
 * hanya di controller, setiap jalur baru yang membuka shift -- import dari sistem
 * lama, perbaikan data, closures nanti -- akan melewati syarat yang sama tanpa
 * salah satu pun mengetahuinya.
 *
 * Shift yang bentrok ikut dibawa, supaya pemanggil bisa membedakan "tutup shift
 * itu dulu" dari "cari mesin lain" tanpa meminta kasir melewati halaman ini sekali
 * lagi untuk membaca kalimat yang sama.
 */
class ShiftAlreadyOpenException extends RuntimeException
{
    public function __construct(
        string $message = 'Masih ada shift yang belum ditutup.',
        public readonly ?Shift $conflictingShift = null,
    ) {
        parent::__construct($message);
    }
}
