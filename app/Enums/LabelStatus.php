<?php

namespace App\Enums;

enum LabelStatus: string
{
    case Queued = 'QUEUED';
    case Sent = 'SENT';
    case Confirmed = 'CONFIRMED';
    case Failed = 'FAILED';

    /**
     * Status label memakai kalimat yang menjelaskan posisi labelnya, bukan
     * "Terkirim" generik milik Format::statusLabel(). Di layar antrean label,
     * "Terkirim" akan dibaca sebagai "barang sudah dikirim", padahal yang
     * ditunggu operator adalah "sudah keluar dari printer".
     */
    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Menunggu Dicetak',
            self::Sent => 'Menunggu Konfirmasi',
            self::Confirmed => 'Sudah Dikonfirmasi',
            self::Failed => 'Gagal Dicetak',
        };
    }

    public function type(): string
    {
        return match ($this) {
            self::Confirmed => 'success',
            self::Failed => 'error',
            self::Sent => 'warning',
            self::Queued => 'info',
        };
    }

    /**
     * Status yang masih menunggu tindakan operator.
     */
    public function isOpen(): bool
    {
        return $this !== self::Confirmed;
    }

    /**
     * Status yang tidak boleh diubah lagi karena labelnya sudah keluar dan
     * dikonfirmasi. Re-print harus membuat job baru, bukan membuka kembali
     * job lama: kalau job lama dihidupkan lagi, `labels_printed` bisa terhitung
     * dua kali untuk label yang sama.
     */
    public function isSettled(): bool
    {
        return $this === self::Confirmed;
    }
}
