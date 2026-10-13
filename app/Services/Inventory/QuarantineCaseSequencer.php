<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Support\Sql\UniqueViolation;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Alokasi nomor kasus karantina.
 *
 * Format: `QC-YYYYMMDD-SEQ`, contoh `QC-20261010-001`. Bagian hari direset
 * tiap hari; nomor di dalam satu hari monoton naik dan tidak pernah dipakai
 * ulang.
 *
 * PERTAHANKAN SOAL RACE CONDITION
 * ------------------------------
 * Sama dengan {@see ReceiptSequencer} dan {@see SkuService}: pembagian nomor
 * adalah operasi read-modify-write, jadi pertahanannya berlapis.
 *
 *  1. lockForUpdate() di dalam transaksi, supaya pembaca tidak melihat nilai
 *     basi selagi baris sedang ditulis.
 *  2. Baris per hari dibuat lebih dulu dengan insertOrIgnore, sehingga balapan
 *     pembuatan baris baru ditelan sebagai kasus biasa.
 *  3. Pelanggaran UNIQUE yang muncul dari balapan diulang; yang masih terjadi
 *     setelah batas percobaan dilempar apa adanya.
 *  4. `quarantine_cases.case_no` UNIQUE adalah jaring pengaman terakhir: nomor
 *     yang lolos semua guard di atas tetap ditolak keras saat kasus dicatat.
 *
 * CATATAN PENTING: lockForUpdate() tidak berfungsi pada SQLite, yang dipakai
 * test suite. Di SQLite penguncian tidak berarti apa-apa, jadi pembuktian
 * penguncian nyata harus dijalankan di MySQL.
 */
final class QuarantineCaseSequencer
{
    /**
     * Prefiks nomor kasus, tanpa tanda hubung.
     */
    private const string PREFIX = 'QC';

    /**
     * Digit minimal nomor urut; melebar otomatis di atas 999.
     */
    private const int MIN_SEQUENCE_DIGITS = 3;

    /**
     * Jumlah percobaan sebelum menyerah pada pelanggaran UNIQUE.
     */
    private const int MAX_ATTEMPTS = 5;

    /**
     * Nomor kasus berikutnya untuk hari berjalan, mis. `QC-20261010-001`.
     *
     * Hari dihitung sekali di sini dan dibawa sampai ke akhir, bukan dihitung
     * ulang saat nomor dirakit: dua pembacaan `now()` yang berbeda bisa berada
     * di sisi tengah malam yang berlawanan.
     *
     * @throws QueryException bila nomor tidak dapat dialokasikan tanpa bentrok
     */
    public function next(?DateTimeInterface $at = null): string
    {
        $day = $this->day($at);

        return self::PREFIX.'-'.$day.'-'.$this->pad($this->nextSequenceOn($day));
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
            DB::table('quarantine_sequences')->insertOrIgnore([
                'day' => $day,
                'last_seq' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('quarantine_sequences')
                ->where('day', $day)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new QueryException(
                    null,
                    sprintf('Baris urut kasus karantina untuk hari %s tidak ditemukan.', $day),
                );
            }

            $next = ((int) $row->last_seq) + 1;

            DB::table('quarantine_sequences')
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
     * Zero-pad nomor urut ke minimal 3 digit, melebar otomatis di atas 999.
     */
    private function pad(int $sequence): string
    {
        return str_pad((string) $sequence, self::MIN_SEQUENCE_DIGITS, '0', STR_PAD_LEFT);
    }
}
