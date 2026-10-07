<?php

namespace App\Services\Notification\Transport;

/**
 * Hasil satu percobaan kirim.
 *
 * Tiga keadaan, bukan dua, dan pemisahan `deferred` dari `refused` itu yang
 * menentukan apakah notifikasi dicoba lagi. Kalau keduanya disamakan, retry
 * otomatis akan mengirim ulang nota yang sudah diterima penitip; kalau keduanya
 * dianggap permanen, satu kegagalan sesaat membuat notifikasi mati selamanya
 * tanpa ada yang mencoba lagi.
 *
 * - `sent`: pesan sudah keluar, jangan pernah diulang.
 * - `deferred`: percobaan berikutnya akan menjemput lagi (jaringan, throttle).
 * - `refused`: mencoba lagi tidak akan berhasil (nomor tidak ada, opt-in belum).
 */
final readonly class TransportResult
{
    private function __construct(
        public bool $sent,
        public ?string $failure = null,
        public bool $retryable = true,
    ) {}

    /**
     * Pesan sudah diserahkan ke tujuan.
     */
    public static function sent(): self
    {
        return new self(true);
    }

    /**
     * Percobaan gagal, dan layak dicoba lagi nanti.
     *
     * `@param string $failure` ditulis dalam bentuk yang bisa ditampilkan ke
     * Staff, karena inilah yang akan dibaca di daftar notifikasi.
     */
    public static function deferred(string $failure): self
    {
        return new self(false, $failure, true);
    }

    /**
     * Gagal dan tidak akan berhasil kalau dicoba lagi.
     */
    public static function refused(string $failure): self
    {
        return new self(false, $failure, false);
    }

    public function failed(): bool
    {
        return ! $this->sent;
    }
}
