<?php

namespace App\Enums;

use App\Models\SettlementPayment;

/**
 * Cara uang berpindah tangan.
 *
 * Enum ini dipakai dua konteks yang berbeda dan tidak boleh dicampur: pembayaran
 * penjualan di kasir, dan pembayaran hasil titipan ke penitip lewat
 * {@see SettlementPayment}. Keduanya tidak punya metode yang sama --
 * kasir tidak pernah mentransfer ke mana pun, dan penitip tidak pernah dibayar
 * QRIS. Karena itu {@see self::pos()} ada, dan setiap konteks yang punya daftar
 * sendiri harus memakainya, bukan `cases()`.
 */
enum PaymentMethod: string
{
    case Cash = 'TUNAI';
    case Qris = 'QRIS';
    case Edc = 'EDC';
    case Transfer = 'TRANSFER';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Qris => 'QRIS',
            self::Edc => 'EDC',
            self::Transfer => 'Transfer',
        };
    }

    /**
     * Metode yang bisa dipakai di kasir.
     *
     * `Transfer` sengaja tidak ada: uang dari penjualan tidak pernah pindah lewat
     * bank, dan transfer di sini hanya berlaku untuk bayar penitip. Rekap shift
     * yang mengiterasi `cases()` akan menampilkan baris "Transfer Rp 0" yang
     * mustahil terjadi di laci, dan baris yang selalu nol itu dibaca kasir sebagai
     * "ada transfer tapi belum tercatat" -- yang membuat kasir mencari penjelasan
     * di tempat yang salah saat menutup shift dengan uang kurang.
     *
     * @return array<int, self>
     */
    public static function pos(): array
    {
        return [
            self::Cash,
            self::Qris,
            self::Edc,
        ];
    }
}
