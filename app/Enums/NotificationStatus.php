<?php

namespace App\Enums;

/**
 * Status satu pesan notifikasi.
 *
 * Mesin's sama dengan yang di SRS 6.5 (`sent -> delivered -> read / failed`),
 * tapi PENDING ditambahkan di depan supaya ada tempat mencatat "sudah
 * disiapkan, belum diserahkan". Tanpa itu, pesan yang menunggu giliran tidak
 * bisa dibedakan dari pesan yang gagal, dan keduanya tampil sebagai "belum
 * terkirim" di dokumen.
 *
 * DELIVERED dan READ tidak akan pernah terlihat selama transport masih
 * `wa.me`: tautan itu tidak memberi umpan balik apa pun, dan status sebenarnya
 * hanya diketahui dari sisi penitip. Dua status ini sengaja tetap ada supaya
 * driver Cloud API bisa ditambahkan tanpa mengubah status mana pun yang sudah
 * tampil di dokumen -- lihat `App\Services\Notification\Transport\WaMeTransport`.
 */
enum NotificationStatus: string
{
    case Pending = 'PENDING';
    case Sent = 'SENT';
    case Delivered = 'DELIVERED';
    case Read = 'READ';
    case Failed = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Siap Dikirim',
            self::Sent => 'Diserahkan ke Staff',
            self::Delivered => 'Sudah Diterima',
            self::Read => 'Sudah Dibaca',
            self::Failed => 'Gagal Dikirim',
        };
    }

    public function type(): string
    {
        return match ($this) {
            self::Read, self::Delivered => 'success',
            self::Failed => 'error',
            self::Sent => 'warning',
            self::Pending => 'info',
        };
    }

    /**
     * Status yang masih boleh dicoba lagi.
     *
     * SENT dan DELIVERED tidak termasuk: pesan sudah keluar, mengulangnya
     * berarti penitip menerima nota yang sama dua kali. READ pasti tidak.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }

    public function isFailed(): bool
    {
        return $this === self::Failed;
    }

    /**
     * Status yang tidak akan berubah lagi tanpa campur tangan baru.
     */
    public function isSettled(): bool
    {
        return ! $this->isOpen();
    }

    /**
     * Transisi yang diizinkan.
     *
     * Mengembalikan daftar tujuan, bukan satu boolean, supaya pemanggil yang
     * salah menuliskan transisi melihat sendiri daftar yang benar.
     *
     * FAILED menerima semua status tujuan karena kegagalan bisa terjadi kapan
     * saja -- termasuk setelah terkirim, waktu Cloud API membalas dengan
     * nomor yang salah atau template ditolak.
     *
     * READ tidak punya jalan keluar, dan itu disengaja. Ia berarti penitip
     * sudah membuka pesannya, jadi pesan itu berhasil. Menandainya kembali ke
     * PENDING agar bisa dikirim ulang bukan memperbaiki apa pun: ia
     * menyiapkan notifikasi yang sama untuk dikirim sekali lagi ke orang yang
     * sudah membacanya. Yang boleh diulang hanya yang belum sampai.
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Sent, self::Failed],
            self::Sent => [self::Delivered, self::Read, self::Failed],
            self::Delivered => [self::Read, self::Failed],
            self::Read => [],
            self::Failed => [self::Pending, self::Sent, self::Failed],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
