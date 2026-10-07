<?php

namespace App\Enums;

use App\Support\Format;

enum OpnameStatus: string
{
    case Draft = 'DRAFT';
    case Counting = 'COUNTING';
    case PendingApproval = 'PENDING_APPROVAL';
    case Approved = 'APPROVED';
    case Cancelled = 'CANCELLED';

    /**
     * Apakah sesi ini masih menggantung: menghitung atau menunggu Owner.
     *
     * Dipakai dua kali dengan arti yang sama -- menolak sesi kedua dimulai
     * selama yang pertama belum selesai, dan menolak penghitungan yang datang
     * setelah sesi diajukan -- supaya aturan "satu sesi terbuka" tidak
     * terpecah menjadi dua daftar status yang bisa berbeda.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Counting, self::PendingApproval], true);
    }

    /**
     * Nilai terbuka dalam bentuk kolom, untuk dipakai di `whereIn()`.
     *
     * Dipisah dari `isOpen()` karena keduanya dipakai di dua tempat berbeda:
     * `isOpen()` untuk baris yang sedang dipegang, daftar ini untuk query yang
     * mencari sesi terbuka dari luar -- termasuk pengecekan bahwa suatu lot
     * tidak sedang terkait sesi opname (FR-IC-35). Bila daftarnya ditulis
     * ulang di sana, dua pengecekan itu bisa berbeda isi pada saat enum
     * bertambah case, dan selisihnya baru terlihat ketika satu sesi lolos
     * seharusnya ditahan.
     *
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Counting->value, self::PendingApproval->value];
    }

    public function label(): string
    {
        return Format::statusLabel($this->value);
    }
}
