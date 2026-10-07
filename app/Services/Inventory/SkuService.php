<?php

namespace App\Services\Inventory;

use App\Support\Sql\UniqueViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Alokasi nomor urut SKU.
 *
 * Format SKU internal (SRS Lampiran A): {owner_code}-{category_code}-{urut},
 * contoh CN01-HW-001. Regex resmi: ^(OW00|CN\d{2,3})-[A-Z]{2,3}-\d{3,6}$
 *
 * Nomor urut bersifat monoton dan tidak pernah dipakai ulang, bahkan ketika
 * SKU di-void (SRS: "nomor tidak dipakai ulang"). Karena itu implementasinya
 * hanya pernah menaikkan last_seq, tidak pernah menurunkannya.
 *
 * PERTAHANKAN SOAL RACE CONDITION
 * ------------------------------
 * Pembagian nomor ini adalah operasi read-modify-write, sehingga rawan balapan:
 * dua kasir yang commit bersamaan bisa membaca last_seq yang sama lalu
 * menghasilkan SKU kembar. Pertahanannya berlapis:
 *
 *  1. lockForUpdate() di dalam transaksi, sehingga pembaca tidak bisa membaca
 *     nilai basi selagi baris sedang ditulis.
 *  2. Baris per pemilik+kategori dibuat lebih dulu dengan insertOrIgnore. Bila
 *     dua transaksi benar-benar berebut membuat baris yang sama, satu di antara
 *     keduanya gagal karena pelanggaran UNIQUE lalu di-retry.
 *  3. Pelanggaran UNIQUE yang masih terjadi setelah retry maksimum dilempar,
 *     bukan ditelan diam-diam, supaya kegagalan tidak berubah menjadi nomor
 *     yang keliru.
 *  4. stock_lots.sku mempunyai constraint UNIQUE sebagai jaring pengaman
 *     terakhir. Bila alokasi nomor lolos semua guard di atas, insert lot tetap
 *     gagal keras dan tidak pernah menimpa SKU yang sudah ada.
 *
 * CATATAN PENTING: lockForUpdate() tidak berfungsi pada SQLite, yang justru
 * dipakai test suite. Di SQLite, penguncian tidak berarti apa-apa, sehingga
 * pembuktian penguncian nyata harus dijalankan di MySQL atau PostgreSQL.
 */
class SkuService
{
    /**
     * Jumlah percobaan sebelum menyerah pada pelanggaran UNIQUE.
     */
    private const int MAX_ATTEMPTS = 5;

    /**
     * Kode pemilik untuk stok milik toko sendiri (Stock In Pribadi).
     */
    public const string OWN_CODE = 'OW00';

    /**
     * Kategori bawaan bila seri produk belum punya kode.
     */
    public const string DEFAULT_CATEGORY = 'HW';

    /**
     * Digit minimal pada nomor urut; melebar otomatis menjadi 4 digit ke atas.
     */
    private const int MIN_SEQUENCE_DIGITS = 3;

    /**
     * Regex resmi dari SRS untuk memvalidasi bentuk SKU.
     */
    private const string SKU_PATTERN = '/^(OW00|CN\d{2,3})-[A-Z]{2,3}-\d{3,6}$/';

    /**
     * Alokasikan satu nomor urut lalu rakit SKU lengkapnya.
     *
     * @throws QueryException bila nomor tidak dapat dialokasikan tanpa bentrok
     */
    public function reserve(string $ownerCode, string $categoryCode = self::DEFAULT_CATEGORY): string
    {
        return $this->format(
            $ownerCode,
            $categoryCode,
            $this->nextSequence($ownerCode, $categoryCode),
        );
    }

    /**
     * Naikkan dan kembalikan nomor urut berikutnya.
     *
     * Dipisahkan dari reserve() agar bisa dipakai saat menambah unit ke lot yang
     * sudah ada tanpa mengubah bentuk SKU-nya.
     *
     * @throws QueryException
     */
    public function nextSequence(string $ownerCode, string $categoryCode = self::DEFAULT_CATEGORY): int
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->bump($ownerCode, $categoryCode);
            } catch (QueryException $e) {
                if ($attempt >= self::MAX_ATTEMPTS || ! UniqueViolation::is($e)) {
                    throw $e;
                }

                // Transaksi lain sempat membuat baris ini lebih dulu. Ulangi
                // supaya percobaan berikutnya membaca dan menaikkan baris yang
                // sudah tersedia.
            }
        }
    }

    /**
     * Rakit SKU dari komponen-komponennya.
     */
    public function format(string $ownerCode, string $categoryCode, int $sequence): string
    {
        return $this->segment($ownerCode, 4).'-'.$this->segment($categoryCode, 3).'-'.$this->pad($sequence);
    }

    /**
     * Periksa SKU terhadap regex resmi pada SRS.
     */
    public static function isValidSku(string $sku): bool
    {
        return preg_match(self::SKU_PATTERN, $sku) === 1;
    }

    /**
     * Read-modify-write yang berjalan di dalam satu transaksi terkunci.
     *
     * @throws QueryException
     */
    private function bump(string $ownerCode, string $categoryCode): int
    {
        return DB::transaction(function () use ($ownerCode, $categoryCode): int {
            // Baris dibuat lebih dulu supaya lockForUpdate() selalu menemukan baris
            // yang ada. insertOrIgnore menelan pelanggaran UNIQUE, sehingga balapan
            // saat baris baru dibuat tidak perlu ditangani sebagai kasus terpisah.
            DB::table('sku_sequences')->insertOrIgnore([
                'owner_code' => $ownerCode,
                'category_code' => $categoryCode,
                'last_seq' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('sku_sequences')
                ->where('owner_code', $ownerCode)
                ->where('category_code', $categoryCode)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                // insertOrIgnore diam-diam melewati baris yang tidak lolos
                // validasi. Jangan sampai last_seq terbaca null lalu jadi nol.
                throw new QueryException(
                    null,
                    sprintf('Baris urut SKU untuk %s/%s tidak ditemukan.', $ownerCode, $categoryCode),
                );
            }

            $next = ((int) $row->last_seq) + 1;

            DB::table('sku_sequences')
                ->where('owner_code', $ownerCode)
                ->where('category_code', $categoryCode)
                ->update([
                    'last_seq' => $next,
                    'updated_at' => now(),
                ]);

            return $next;
        }, self::MAX_ATTEMPTS);
    }

    /**
     * Zero-pad nomor urut ke minimal 3 digit, melebar otomatis di atas 999.
     */
    private function pad(int $sequence): string
    {
        return str_pad((string) $sequence, self::MIN_SEQUENCE_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Huruf kapital dan panjang dibatasi sesuai regex SRS.
     */
    private function segment(string $value, int $maxLength): string
    {
        return strtoupper(substr($value, 0, $maxLength));
    }
}
