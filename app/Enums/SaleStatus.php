<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Paid = 'PAID';
    case Voided = 'VOIDED';
    case SyncConflict = 'SYNC_CONFLICT';
    case TermsStale = 'TERMS_STALE';

    /**
     * Status dalam kalimat kasir, bukan kode yang sama dengan nama kolomnya.
     *
     * `Format::statusLabel()` bisa menangani `PAID`, tapi tidak dua status lain:
     * `VOIDED` akan tampil sebagai "Voided" dan `SYNC_CONFLICT` sebagai
     * "Sync Conflict". Dua bentuk yang berbeda untuk satu daftar status berarti
     * kasir harus berhenti membaca dan menebak apakah "Voided" itu bug atau
     * memang begitu -- dan daftar ini dibaca sambil menghitung uang.
     */
    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Lunas',
            self::Voided => 'Dibatalkan',
            self::SyncConflict => 'Bentrok Sinkron',
            self::TermsStale => 'Ketentuan Basi',
        };
    }

    /**
     * `SyncConflict` dan `TermsStale` sengaja `warning`, bukan `error`.
     *
     * Keduanya berarti nota itu tercatat tapi belum layak dipercaya -- uangnya
     * benar dan barangnya sudah keluar. Warna merah membuat kasir memanggil
     * Owner, padahal yang dibutuhkan adalah peninjauan. Voided satu-satunya yang
     * benar-benar kehilangan uangnya.
     */
    public function type(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Voided => 'error',
            self::SyncConflict, self::TermsStale => 'warning',
        };
    }

    /**
     * Nota yang sahih: uang diterima dan tercatat utuh.
     *
     * Halaman riwayat hanya menawarkan void untuk yang ini. Membatalkan nota yang
     * belum sinkron akan menghilang dari laci kasir sebelum perangkatnya pernah
     * mengirimnya.
     */
    public function isSettled(): bool
    {
        return $this === self::Paid;
    }
}
