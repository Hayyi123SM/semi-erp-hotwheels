<?php

namespace App\Support;

use BackedEnum;
use Carbon\Carbon;

class Format
{
    public const EMPTY = '—';

    private const STATUS_LABELS = [
        'AVAILABLE' => 'Tersedia',
        'SOLD_OUT' => 'Habis',
        'RETURNED' => 'Dikembalikan',
        'WRITTEN_OFF' => 'Dihapus',
        'VOID' => 'Dibatalkan',
        'ACTIVE' => 'Aktif',
        'SUSPENDED' => 'Ditangguhkan',
        'ARCHIVED' => 'Arsip',
        'DRAFT' => 'Draft',
        'PENDING' => 'Menunggu',
        'APPROVED' => 'Disetujui',
        'PARTIALLY_PAID' => 'Sebagian Dibayar',
        'PAID' => 'Lunas',
        'SENT' => 'Terkirim',
        'DELIVERED' => 'Tersampaikan',
        'READ' => 'Dibaca',
        'QUEUED' => 'Antre',
        'CONFIRMED' => 'Terkonfirmasi',
        'FAILED' => 'Gagal',
        'NEEDS_REVIEW' => 'Perlu Review',
        'INACTIVE' => 'Non-aktif',
        'OPEN' => 'Terbuka',
        'CLOSED' => 'Ditutup',
        'COMPLETED' => 'Selesai',
        // Siklus opname (FR-IC-20..23). Kata "Hitung" dipakai bukan "Dihitung",
        // supaya kalimatnya tetap pendek di badge dan tetap berwaktu saat
        // dipakai sebagai label status sesi yang sedang berjalan.
        'COUNTING' => 'Menghitung',
        'PENDING_APPROVAL' => 'Menunggu Persetujuan',
        'CANCELLED' => 'Dibatalkan',
        'COUNTED' => 'Menunggu Review',
        'OK' => 'Sesuai',
        'REJECTED' => 'Ditolak',
        // Retur ke penitip (FR-IC-30..33). "Memverifikasi" berbunyi berwaktu
        // dan tidak menyiratkan angkanya sudah final; "Dieksekusi" dipilih
        // bukan "Selesai", karena dokumen yang dibatalkan juga bisa dibilang
        // selesai tanpa satu unit pun bergerak.
        'VERIFYING' => 'Memverifikasi',
        'EXECUTED' => 'Dieksekusi',
    ];

    private const STATUS_TYPES = [
        'AVAILABLE' => 'success',
        'ACTIVE' => 'success',
        'APPROVED' => 'success',
        'PAID' => 'success',
        'CLOSED' => 'success',
        'COMPLETED' => 'success',
        'CONFIRMED' => 'success',
        'DELIVERED' => 'success',
        'SOLD_OUT' => 'warning',
        'NEEDS_REVIEW' => 'warning',
        'PENDING' => 'warning',
        'SUSPENDED' => 'warning',
        'QUEUED' => 'warning',
        'SENT' => 'warning',
        'PARTIALLY_PAID' => 'warning',
        'OPEN' => 'warning',
        'VOID' => 'error',
        'WRITTEN_OFF' => 'error',
        'RETURNED' => 'error',
        'ARCHIVED' => 'error',
        'FAILED' => 'error',
        'INACTIVE' => 'error',
        'CANCELLED' => 'error',
        'REJECTED' => 'error',
        'COUNTING' => 'info',
        'COUNTED' => 'warning',
        'PENDING_APPROVAL' => 'warning',
        'OK' => 'success',
        // Retur ke penitip: verifikasi yang sedang berjalan bukan peringatan
        // dan bukan kegagalan, sementara eksekusi yang sudah berjalan sesuai
        // rencana memang layak ditandai sukses.
        'VERIFYING' => 'info',
        'EXECUTED' => 'success',
    ];

    public static function rupiah(int|float|string|null $amount): string
    {
        return 'Rp'.number_format((float) ($amount ?? 0), 0, ',', '.');
    }

    public static function number(int|float|string|null $value, int $decimals = 0): string
    {
        if ($value === null || $value === '') {
            return self::EMPTY;
        }

        return number_format((float) $value, $decimals, ',', '.');
    }

    /**
     * A percentage, the way a field that takes one has to be written.
     *
     * `decimal(5,2)` sends `20.00` out of the database, and a field with the
     * `rate` mask on it reads digits and a comma: given `20.00` it would find
     * no comma, take the dot for thousands, and show `2000` -- a hundred times
     * the rate, in a field whose whole job is to be right about a share of
     * somebody's money.
     *
     * So the decimal point arrives as the comma the reader will see, the
     * thousands separator is left out because a percentage is not grouped, and
     * the zeros a two-place column pads with are trimmed off: `12,50` is what
     * the reader typed, `12,5` is what they meant.
     */
    public static function rate(int|float|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // A percentage is never grouped, so a comma in one is only ever a decimal
        // point. PHP does not agree: `(float) '12,50'` stops at the comma and
        // hands back 12, which is half a share written as a whole one.
        $value = str_replace(',', '.', (string) $value);

        $formatted = number_format((float) $value, 2, ',', '');

        if (! str_contains($formatted, ',')) {
            return $formatted;
        }

        [$whole, $fraction] = explode(',', $formatted, 2);
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $whole : $whole.','.$fraction;
    }

    public static function date(Carbon|string|null $value): string
    {
        return self::carbon($value)?->format('d M Y') ?? self::EMPTY;
    }

    public static function datetime(Carbon|string|null $value): string
    {
        $date = self::carbon($value);

        return $date ? $date->format('d M Y H:i').' WIB' : self::EMPTY;
    }

    public static function enum(BackedEnum|string|null $value): string
    {
        $raw = $value instanceof BackedEnum ? $value->value : $value;

        if ($raw === null || $raw === '') {
            return self::EMPTY;
        }

        $words = preg_split('/[\s_\-]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(
            fn (string $word) => mb_convert_case($word, MB_CASE_TITLE, 'UTF-8'),
            $words,
        ));
    }

    public static function text(?string $value, string $empty = self::EMPTY): string
    {
        return $value === null || trim($value) === '' ? $empty : $value;
    }

    public static function statusLabel(BackedEnum|string|null $value): string
    {
        $raw = $value instanceof BackedEnum ? $value->value : $value;

        if ($raw === null || $raw === '') {
            return self::EMPTY;
        }

        return self::STATUS_LABELS[$raw] ?? self::enum($raw);
    }

    public static function statusType(BackedEnum|string|null $value): string
    {
        $raw = $value instanceof BackedEnum ? $value->value : $value;

        return self::STATUS_TYPES[$raw] ?? 'info';
    }

    public static function ownershipType(BackedEnum|string|null $value): string
    {
        $raw = $value instanceof BackedEnum ? $value->value : $value;

        return match (strtoupper((string) $raw)) {
            'CONSIGN', 'TITIP' => 'TITIP',
            'QUARANTINE' => 'KARANTINA',
            default => 'PRIBADI',
        };
    }

    private static function carbon(Carbon|string|null $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse($value);
    }
}
