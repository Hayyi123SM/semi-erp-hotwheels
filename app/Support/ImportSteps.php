<?php

namespace App\Support;

/**
 * Tahap-tahap alur impor Excel, dipakai oleh stepper.
 *
 * Satu daftar untuk keempat halaman. Kalau tiap halaman menulis daftarnya
 * sendiri, "Validasi" akan muncul dengan nama berbeda di dua tempat, dan
 * tidak akan ada yang mengetahuinya sampai ada orang yang membacanya
 * berdampingan.
 *
 * `href` hanya diisi untuk halaman yang sudah boleh dicapai dari halaman ini.
 * Halaman hasil-import punya sesi yang sudah dibuang, jadi tidak ada satu
 * pun tautan kembali ke sana -- dan langkah yang tidak bisa dicapai tetap
 * tampil sebagai teks, bukan tautan.
 */
final class ImportSteps
{
    /**
     * @return array<int, array{label: string, href: string|null}>
     */
    public static function for(?string $mappingUrl = null, ?string $moduleRoute = null): array
    {
        return [
            ['label' => 'Unggah Berkas', 'href' => $moduleRoute],
            ['label' => 'Petakan Kolom', 'href' => $mappingUrl],
            ['label' => 'Validasi', 'href' => null],
            ['label' => 'Selesai', 'href' => null],
        ];
    }

    /**
     * Nomor tahap untuk halaman tertentu.
     */
    public static function number(string $step): int
    {
        return match ($step) {
            'upload' => 1,
            'mapping' => 2,
            'validate' => 3,
            'result' => 4,
            default => 1,
        };
    }
}
