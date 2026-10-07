<?php

namespace App\Enums;

use App\Support\Format;

/**
 * Status satu dokumen Retur Penitip (FR-IC-30..33).
 *
 * Urutannya bukan sekadar label: `Draft` adalah dokumen yang barisnya sudah
 * ditulis tapi barangnya masih di rak biasa, `Verifying` barangnya sudah di rak
 * staging dan sedang dipindai satu per satu, dan `Executed` stoknya sudah
 * berkurang. `Approved` ada di enum karena dokumen yang disetujui namun belum
 * dieksekusi adalah keadaan yang bisa saja dipisah nanti; pada versi ini
 * persetujuan dan eksekusi berlangsung dalam satu transaksi, jadi status itu
 * tidak pernah tersimpan di baris mana pun.
 */
enum RtvStatus: string
{
    case Draft = 'DRAFT';
    case Verifying = 'VERIFYING';
    case Approved = 'APPROVED';
    case Executed = 'EXECUTED';
    case Cancelled = 'CANCELLED';

    /**
     * Apakah dokumen ini masih menggantung dan karena itu menutup sesi lain.
     *
     * `Approved` ikut dihitung terbuka: keadaan itu belum menyelesaikan apa
     * pun -- stoknya belum berkurang -- sehingga dokumen yang berada di situ
     * masih harus menghalangi RTV kedua untuk penitip yang sama.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Verifying, self::Approved], true);
    }

    /**
     * Nilai terbuka dalam bentuk kolom, untuk dipakai di `whereIn()`.
     *
     * Dipisah dari `isOpen()` supaya daftarnya hanya ada satu: query yang
     * menyalin daftar status dari sini dua baris di bawah bisa berbeda isi
     * ketika enum bertambah case baru, dan perbedaannya tidak terlihat di
     * layar mana pun sampai satu sesi lolos pengecekan yang seharusnya menahannya.
     *
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Draft->value, self::Verifying->value, self::Approved->value];
    }

    public function label(): string
    {
        return Format::statusLabel($this->value);
    }
}
