<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\Inventory\SkuService;
use App\Support\DeviceId;
use App\Support\Sql\UniqueViolation;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Alokasi nomor struk penjualan.
 *
 * Format: `HW-YYYYMMDD-SEQ`, contoh `HW-20261006-0001`. Bagian hari direset
 * tiap hari; nomor di dalam satu hari monoton naik dan tidak pernah dipakai
 * ulang.
 *
 * Bentuk ini menyimpang dari `POS-{DEVICE}-{YYYYMMDD}-{SEQ}` pada spesifikasi.
 * Perangkatnya sendiri sekarang memang tercatat -- `sales.device_id` diisi
 * otomatis oleh {@see DeviceId} -- tetapi sengaja tidak ikut ke dalam nomor
 * struk. Nomor harus terbaca keras dan tidak pernah berubah, sementara
 * identitas perangkat adalah metadata yang masih boleh diperbaiki belakangan;
 * menaruhnya di nomor berarti kesalahan identitas perangkat ikut menodai nomor
 * yang sudah terbit selamanya.
 *
 * PERTAHANKAN SOAL RACE CONDITION
 * ------------------------------
 * Sama dengan {@see SkuService}: pembagian nomor adalah
 * operasi read-modify-write, jadi pertahanannya berlapis.
 *
 *  1. lockForUpdate() di dalam transaksi, supaya pembaca tidak melihat nilai
 *     basi selagi baris sedang ditulis.
 *  2. Baris per hari dibuat lebih dulu dengan insertOrIgnore, sehingga balapan
 *     pembuatan baris baru ditelan sebagai kasus biasa.
 *  3. Pelanggaran UNIQUE yang muncul dari balapan diulang; yang masih terjadi
 *     setelah batas percobaan dilempar apa adanya, supaya kegagalan tidak
 *     berubah menjadi nomor struk yang keliru.
 *  4. `sales.receipt_no` UNIQUE adalah jaring pengaman terakhir: nomor yang lolos
 *     semua guard di atas tetap ditolak keras saat dicatat, tidak pernah
 *     menimpa struk yang sudah terbit.
 *
 * CATATAN PENTING: lockForUpdate() tidak berfungsi pada SQLite, yang justru
 * dipakai test suite. Di SQLite penguncian tidak berarti apa-apa, jadi
 * pembuktian penguncian nyata harus dijalankan di MySQL.
 */
final class ReceiptSequencer
{
    /**
     * Prefiks struk, tanpa tanda hubung.
     */
    private const string PREFIX = 'HW';

    /**
     * Digit minimal nomor urut; melebar otomatis di atas 9999.
     */
    private const int MIN_SEQUENCE_DIGITS = 4;

    /**
     * Jumlah percobaan sebelum menyerah pada pelanggaran UNIQUE.
     */
    private const int MAX_ATTEMPTS = 5;

    /**
     * Nomor struk berikutnya untuk hari berjalan, mis. `HW-20261006-0001`.
     *
     * Hari dihitung sekali di sini dan dibawa sampai ke akhir, bukan dihitung
     * ulang saat struk dirakit: dua pembacaan `now()` yang berbeda bisa
     * berada di sisi tengah malam yang berlawanan, dan struk akan tercatat
     * bertanggal hari yang urutannya tidak pernah dialokasikan.
     *
     * @throws QueryException bila nomor tidak dapat dialokasikan tanpa bentrok
     */
    public function next(?DateTimeInterface $at = null): string
    {
        $day = $this->day($at);

        return self::PREFIX.'-'.$day.'-'.$this->pad($this->nextSequenceOn($day));
    }

    /**
     * Naikkan dan kembalikan nomor urut untuk hari itu.
     *
     * @throws QueryException
     */
    public function nextSequence(?DateTimeInterface $at = null): int
    {
        return $this->nextSequenceOn($this->day($at));
    }

    /**
     * Rakit nomor struk dari satu nomor urut pada hari tertentu.
     */
    public function format(int $sequence, ?DateTimeInterface $at = null): string
    {
        return self::PREFIX.'-'.$this->day($at).'-'.$this->pad($sequence);
    }

    /**
     * @throws QueryException
     */
    private function nextSequenceOn(string $day): int
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->bump($day);
            } catch (QueryException $e) {
                if ($attempt >= self::MAX_ATTEMPTS || ! UniqueViolation::is($e)) {
                    throw $e;
                }

                // Transaksi lain memakai hari yang sama lebih dulu. Ulangi
                // supaya percobaan berikutnya membaca baris yang sudah ada.
            }
        }
    }

    /**
     * Read-modify-write yang berjalan di dalam satu transaksi terkunci.
     *
     * @throws QueryException
     */
    private function bump(string $day): int
    {
        return DB::transaction(function () use ($day): int {
            // Baris dibuat lebih dulu supaya lockForUpdate() selalu menemukan
            // baris yang ada. insertOrIgnore menelan pelanggaran UNIQUE, jadi
            // balapan saat baris hari ini baru dibuat tidak perlu kasus terpisah.
            DB::table('receipt_sequences')->insertOrIgnore([
                'day' => $day,
                'last_seq' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('receipt_sequences')
                ->where('day', $day)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                // insertOrIgnore bisa melewatkan baris yang tidak lolos
                // validasi. Jangan sampai last_seq terbaca null lalu jadi nol,
                // karena nol berarti struk berikutnya mengulang 0001.
                throw new QueryException(
                    null,
                    sprintf('Baris urut struk untuk hari %s tidak ditemukan.', $day),
                );
            }

            $next = ((int) $row->last_seq) + 1;

            DB::table('receipt_sequences')
                ->where('day', $day)
                ->update([
                    'last_seq' => $next,
                    'updated_at' => now(),
                ]);

            return $next;
        }, self::MAX_ATTEMPTS);
    }

    /**
     * Kunci hari, dalam format `YYYYMMDD`.
     */
    private function day(?DateTimeInterface $at): string
    {
        return ($at === null ? Carbon::now() : Carbon::instance($at))->format('Ymd');
    }

    /**
     * Zero-pad nomor urut ke minimal 4 digit, melebar otomatis di atas 9999.
     */
    private function pad(int $sequence): string
    {
        return str_pad((string) $sequence, self::MIN_SEQUENCE_DIGITS, '0', STR_PAD_LEFT);
    }
}
