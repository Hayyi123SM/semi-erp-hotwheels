<?php

namespace App\Enums;

enum MovementType: string
{
    case InOwn = 'IN_OWN';
    case InConsign = 'IN_CONSIGN';
    case Sale = 'SALE';
    case SaleVoid = 'SALE_VOID';
    case ReturnCustomer = 'RETURN_CUSTOMER';
    case Rtv = 'RTV';
    case AdjPlus = 'ADJ_PLUS';
    case AdjMinus = 'ADJ_MINUS';
    case WriteOff = 'WRITE_OFF';
    case Transfer = 'TRANSFER';
    case QuarantineIn = 'QUARANTINE_IN';
    case QuarantineOut = 'QUARANTINE_OUT';

    /**
     * Kalimat yang dibaca orang di kartu stok.
     *
     * Nilainya disimpan dalam bentuk kode (`ADJ_MINUS`) karena itulah yang
     * dicari di laporan; labelnya ada di sini supaya tidak ada halaman yang
     * menulis ulang terjemahan yang sama dengan ejaan yang sedikit berbeda.
     */
    public function label(): string
    {
        return match ($this) {
            self::InOwn => 'Masuk (Pribadi)',
            self::InConsign => 'Masuk (Titipan)',
            self::Sale => 'Terjual',
            self::SaleVoid => 'Void Penjualan',
            self::ReturnCustomer => 'Retur Pelanggan',
            self::Rtv => 'Retur ke Penitip',
            self::AdjPlus => 'Penyesuaian +',
            self::AdjMinus => 'Penyesuaian −',
            self::WriteOff => 'Penghapusan',
            self::Transfer => 'Pindah Rak',
            self::QuarantineIn => 'Masuk Karantina',
            self::QuarantineOut => 'Keluar Karantina',
        };
    }
}
