<?php

namespace App\Enums;

enum QuarantineStatus: string
{
    case Open = 'OPEN';
    case WaitingEvidence = 'WAITING_EVIDENCE';
    case Escalated = 'ESCALATED';
    case Closed = 'CLOSED';

    /**
     * Apakah kasus ini masih menggantung.
     *
     * Kasus yang belum ditutup berarti unit fisiknya sedang diperiksa: barang
     * dengan kasus terbuka tidak boleh dijual, diopname ulang, ataupun
     * dikembalikan ke penitip, karena angka stoknya memang sedang dipersoalkan.
     */
    public function isOpen(): bool
    {
        return $this !== self::Closed;
    }

    /**
     * Nilai terbuka dalam bentuk kolom, untuk dipakai di `whereIn()`.
     *
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Open->value, self::WaitingEvidence->value, self::Escalated->value];
    }
}
