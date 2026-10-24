<?php

namespace App\Enums;

/**
 * Keadaan satu shift kasir.
 *
 * `PendingApproval` bukan sekadar `Open` yang ditandai: uang laci sudah
 * dihitung dan angkanya sudah tersimpan, tetapi shift belum boleh dipakai
 * berjualan lagi dan belum boleh ditutup lagi. Karena itu "masih berjalan"
 * (`Open`) dan "belum selesai" (`Open` atau `PendingApproval`) adalah dua
 * pertanyaan berbeda dan punya scope masing-masing di `Shift`.
 */
enum ShiftStatus: string
{
    case Open = 'OPEN';

    /** Kasir sudah menghitung laci, Owner belum memutuskan selisihnya. */
    case PendingApproval = 'PENDING_APPROVAL';

    case Closed = 'CLOSED';

    /**
     * Shift ini boleh dipakai menjual?
     *
     * Hanya `Open`. Shift yang menunggu keputusan sudah tidak menerima
     * penjualan baru: angka lacinya sedang diperiksa, dan penjualan yang masuk
     * setelah hitungan akan membuat angka itu salah.
     */
    public function canSell(): bool
    {
        return $this === self::Open;
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Berjalan',
            self::PendingApproval => 'Menunggu Persetujuan',
            self::Closed => 'Ditutup',
        };
    }

    /**
     * Warna badge untuk keadaan ini.
     *
     * `PendingApproval` memakai `warning`, bukan `info`: ada uang yang sudah
     * dihitung tetapi belum disahkan, dan itu memang menunggu seseorang.
     */
    public function badgeType(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::PendingApproval => 'warning',
            self::Closed => 'neutral',
        };
    }
}
