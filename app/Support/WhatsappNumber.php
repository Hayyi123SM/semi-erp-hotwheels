<?php

namespace App\Support;

/**
 * Satu nomor WhatsApp, dalam dua bentuk yang tidak boleh tertukar.
 *
 * Bentuk tersimpan dan bentuk yang dibaca orang memang berbeda, dan mencampur
 * keduanya adalah sumber bug yang sunyi. Tautan `wa.me` hanya menerima
 * `6281234567890`: tanpa `+`, tanpa spasi, tanpa awalan yang terlewat. Orang
 * justru menulis `+62 812-3456-7890`, lengkap dengan spasi dan tanda hubung,
 * karena itulah bentuk yang biasa dicetak dan diucapkan. Kolom `wa_number`
 * menerima keduanya sebagai string berbeda, jadi `0812-3456-7890` dan
 * `+6281234567890` lolos sebagai dua penitip berbeda meski itu satu nomor, dan
 * `wa.me` yang dibangun dari nilai mentah itu membuka chat yang salah.
 *
 * Karena itu setiap nomor dinormalkan tepat sekali, di mutator model, bukan di
 * request. Menormalkan di request hanya menutup satu pintu: impor dan tinker
 * tetap bisa menulis bentuk mentah, dan data yang salah masuk lebih sulit
 * dilacak daripada data yang tidak pernah bisa salah masuk.
 *
 * Bentuk yang disimpan selalu `62` diikuti digit. Nomor yang ditulis `0` di
 * depan ditafsirkan Indonesia, karena `0` adalah awalan nomor lokal sedangkan
 * awalan negara tidak pernah `0` -- jadi di sini tidak ada tebakan. Nomor tanpa
 * `0` dan tanpa `62` sengaja dibiarkan apa adanya supaya `isValid()` menolaknya:
 * menebak kode negara berarti risiko mengirim nota penitip ke orang yang tidak
 * pernah menautkan nomor itu.
 */
final class WhatsappNumber
{
    /**
     * Kode negara tempat seluruh aplikasi ini bekerja.
     */
    private const string COUNTRY_CODE = '62';

    /**
     * Digit paling sedikit yang masih mungkin sebuah nomor.
     *
     * Batas ini soal bentuk, bukan soal kebetulan: di bawah delapan digit, sisa
     * karakter bukan nomor telepon melainkan sisa kebetulan. `+62 (abc)` hanya
     * menyisakan `62` -- dan `62` adalah kode negara, bukan nomor. Tanpa batas
     * ini mutator akan menyimpannya, lalu database unique akan membandingkan
     * dua huruf karung yang sama-sama tidak berguna sebagai nomor.
     *
     * Nomor yang benar-benar panjang atau salah format tetaphandled oleh
     * `isValid()`, yang memeriksa panjang 8 sampai 13 digit setelah `62`.
     */
    private const int MIN_DIGITS = 8;

    /**
     * Nomor dalam bentuk yang disimpan dan yang dibaca `wa.me`: digit saja,
     * diawali kode negara.
     *
     * Mengembalikan `null` untuk input kosong supaya kolom `wa_number` yang
     * nullable tetap bisa dibiarkan kosong, alih-alih berubah menjadi `''` yang
     * lolos aturan `nullable` tetapi tetap menabrak unique index.
     */
    public static function normalize(?string $value): ?string
    {
        $digits = self::digits($value);

        if ($digits === null) {
            return null;
        }

        // `0` di depan adalah awalan nomor lokal, bukan bagian dari nomor.
        // `62` di depan sudah bentuk akhir, jadi dilewati apa adanya.
        if (str_starts_with($digits, '0')) {
            return self::COUNTRY_CODE.substr($digits, 1);
        }

        return $digits;
    }

    /**
     * Digit saja, atau `null` kalau yang tersisa terlalu pendek untuk disebut
     * nomor.
     */
    private static function digits(?string $value): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $value) ?? '';

        return strlen($digits) < self::MIN_DIGITS ? null : $digits;
    }

    /**
     * Nomor dalam bentuk yang dibaca manusia, untuk kolom tabel dan isian form.
     *
     * Sengaja tidak lewat `normalize()`, supaya angka yang memang terpotong
     * tetap tampil apa adanya. Menyembunyikan angka yang pendek lebih
     * buruk daripada menampilkannya apa adanya: yang pendek tidak akan
     * mendapati tautan WhatsApp, dan membungkusnya di `null` hanya membuat
     * orang mengira datanya hilang.
     *
     * Bentuk ini sudah dipakai form sejak awal, jadi memanggilnya untuk
     * tampilan adalah mengembalikan nilai ke kontrak yang tertulis di halaman
     * itu sendiri -- bukan mengubah tampilan.
     */
    public static function display(?string $value): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $value) ?? '';

        if ($digits === '') {
            return null;
        }

        $local = str_starts_with($digits, '0')
            ? substr($digits, 1)
            : (str_starts_with($digits, self::COUNTRY_CODE)
                ? substr($digits, strlen(self::COUNTRY_CODE))
                : $digits);

        if (strlen($local) < 9) {
            // Tidak cukup panjang untuk dikelompokkan dengan jujur. Memaksakan
            // pola tetap saja menipu mata, jadi angka mentahnya dibiarkan.
            return $digits;
        }

        return '+'.self::COUNTRY_CODE.' '.substr($local, 0, 3).'-'.substr($local, 3, 4).'-'.substr($local, 7);
    }

    /**
     * Apakah nomor ini bisa menjadi tujuan `wa.me`.
     *
     * Dipakai aturan validasi, bukan di dalam `normalize()`, supaya angka yang
     * tidak bisa dikembalikan ditolak dengan pesan yang menyebut format yang
     * benar -- bukan hilang begitu saja saat disimpan.
     */
    public static function isValid(?string $value): bool
    {
        $normalized = self::normalize($value);

        if ($normalized === null || ! str_starts_with($normalized, self::COUNTRY_CODE)) {
            return false;
        }

        // E.164 mengizinkan 8 sampai 15 digit. Batas atasnya bukan selera:
        // `wa.me` menolak nomor yang lebih panjang, jadi menyimpannya lebih dulu
        // hanya menunda penolakan sampai pesan dikirim.
        return preg_match('/^62[0-9]{8,13}$/', $normalized) === 1;
    }

    /**
     * Potongan digit untuk mencari nomor, atau `null` bila yang diketik bukan
     * nomor.
     *
     * Tanpa ini, mencari nomor menjadi rusak begitu penormalisan masuk. Tersimpan
     * selalu `6281234567890`, sementara orang mengetik `08123` -- awalan lokal
     * yang paling sering dipakai, dan juga potongan dari nomor yang sedang dicari.
     * `LIKE '%08123%'` tidak akan menemukan `6281234567890` sama sekali, jadi
     * pencarian nomor yang tadinya bekerja diam-diam ikut mati.
     *
     * Yang dikembalikan adalah bagian nomor TANPA awalan: `62` maupun `0` sama
     *-sama dibuang, karena keduanya awalan dari angka yang sama. Jadi `08123`,
     * `62812`, dan `812` semuanya berakhir pada `812`, dan ketiganya menemukan
     * `6281234567890`.
     *
     * Mengembalikan `null` untuk pencarian yang bukan nomor -- nama, kode, kata
     * kunci -- supaya pencarian biasa tidak ikut berubah-ubah.
     */
    public static function searchFragment(string $search): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $search) ?? '';

        if (strlen($digits) < 3) {
            return null;
        }

        if (str_starts_with($digits, self::COUNTRY_CODE)) {
            $digits = substr($digits, strlen(self::COUNTRY_CODE));
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return $digits === '' ? null : $digits;
    }

    /**
     * Tautan `wa.me` dengan pesan yang sudah terisi.
     *
     * Satu-satunya tempat di aplikasi yang tahu bentuk tautan ini. Panjang URL
     * dibatasi karena sebagian peramban mobile memotong tautan yang terlalu
     * panjang, dan pesan yang terpotong separuh lebih buruk daripada pesan
     * pendek tapi utuh.
     */
    public static function chatLink(?string $number, string $message): ?string
    {
        $normalized = self::normalize($number);

        if ($normalized === null || ! self::isValid($normalized)) {
            return null;
        }

        return 'https://wa.me/'.$normalized.'?text='.rawurlencode(self::truncateForLink($message));
    }

    /**
     * Potong pesan secukupnya agar tautannya masih bisa dibuka.
     *
     * Dipotong di batas baris, bukan di tengah kata, karena isi nota yang
     * terpotong di tengah kalimat membuat Staff salah mengira angka yang hilang
     * memang tidak ada.
     *
     * Batasnya dihitung dalam byte, bukan karakter, dan itu bukan kelalaian:
     * yang dibatasi adalah panjang URL, dan URL menghitung byte. Isi multibyte
     * karena itu terpotong lebih awal dalam jumlah karakter, dan itu pilihan
     * yang konservatif.
     */
    private static function truncateForLink(string $message): string
    {
        $limit = 1800;

        if (strlen($message) <= $limit) {
            return $message;
        }

        $cut = mb_strrpos(mb_substr($message, 0, $limit), "\n");

        return rtrim($cut === false ? mb_substr($message, 0, $limit) : mb_substr($message, 0, $cut)).'…';
    }
}
