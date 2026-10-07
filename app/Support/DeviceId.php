<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Perangkat yang sedang memakai aplikasi.
 *
 * "Perangkat" di aplikasi ini bukan entitas yang punya tabel sendiri: tidak ada
 * pendaftaran perangkat, tidak ada nomor seri, dan tidak ada yang perlu diverifikasi
 * keanggotaannya. Yang ada hanyalah identitas yang ikut dibawa setiap request,
 * supaya transaksi yang dipindai di satu mesin tidak tercatat sebagai milik
 * mesin lain. Identitas ini menempel sebagai kolom teks nullable di `shifts`,
 * `sales`, `stock_movements`, dan `audit_logs`.
 *
 * Tiga sumber, dibaca berurutan -- yang di depan menang atas yang di belakang:
 *
 *  1. **Session.** Kasir yang sudah log in terikat ke perangkat itu sampai keluar
 *     dari akun, dan yang berpindah perangkat harus keluar lebih dulu supaya
 *     identitas berikutnya bisa berganti dengan jujur.
 *  2. **Header `X-Device-Id`.** Pengiriman eksplisit dari klien, dipakai bila
 *     ada yang ingin menentukan perangkatnya sendiri.
 *  3. **Cookie `device_id`.** Diambil otomatis, tanpa input siapa pun.
 *
 * Sumber ketiga lahir dari kenyataan bahwa dua sumber pertama tidak pernah ada
 * di lapangan: tidak ada yang mengisi session dan tidak ada yang mengirim header,
 * sehingga kolom `device_id` seluruhnya `null` -- halaman menampilkan "Tidak
 * terdeteksi", pencarian perangkat tidak menemukan apa pun, dan "satu shift
 * terbuka per perangkat" berubah menjadi `WHERE device_id IS NULL` yang
 * menyatukan seluruh tablet dalam toko menjadi satu perangkat tunggal.
 *
 * Bentuk identitas otomatis `{BROWSER}-{PLATFORM}-{TOKEN}` (misalnya
 * `CHR-MAC-3f9a2b71`), dan alasannya terbagi dua:
 *
 *  - **Label dari `User-Agent`** (`CHR`, `MAC`, ...) supaya petugas bisa membaca
 *    perangkat mana yang dimaksud tanpa membuka database. Versi sengaja tidak
 *    ikut: perbaruan browser tidak boleh mengganti identitas shift yang sedang
 *    berjalan.
 *  - **Token acak** supaya identitas benar-benar per perangkat. `User-Agent`
 *    sendiri tidak cukup -- dua tablet sejenis dengan browser yang sama
 *    menghasilkan `User-Agent` yang sama, dan identitas yang sama berarti kasir
 *    kedua di tablet kedua tidak akan pernah bisa membuka shift. Hostname juga
 *    tidak dipakai: yang tersedia bagi server adalah nama server sendiri, yang
 *    sama untuk setiap perangkat.
 *
 * Token diingat dalam cookie `HttpOnly` yang umurnya lima tahun, jadi identitas
 * ini stabil di seluruh session dan tidak berubah hanya karena kasir keluar lalu
 * masuk lagi. Cookie dibaca dengan pemeriksaan format lebih dulu: nama `device_id`
 * dipakai aplikasi lain di domain yang sama tidak akan ikut teradopsi.
 *
 * Pemanggilan berulang dalam satu request selalu mengembalikan nilai yang sama
 * -- hasilnya disimpan di `request()->attributes`, bukan dihitung ulang. Tanpa
 * itu, halaman yang membaca perangkat dua kali bisa menampilkan satu identitas
 * sementara audit log yang ditulis pada request yang sama memakai identitas lain.
 */
final class DeviceId
{
    public const SESSION_KEY = 'device_id';

    public const HEADER = 'X-Device-Id';

    /**
     * Cookie yang membawa identitas otomatis.
     *
     * Sengaja berbeda dari {@see SESSION_KEY} secara cuma-cuma: keduanya memang
     * berisi hal yang sama, tetapi session hidup sampai kasir keluar sementara
     * cookie ini harus bertahan jauh lebih lama.
     */
    public const COOKIE = 'device_id';

    /**
     * Batas panjang yang sama dengan kolom `device_id`.
     *
     * Header datang dari klien dan tidak dibersihkan. SQLite di suite pengujian
     * tidak menegakkan batas kolom, jadi header yang terlalu panjang akan lolos
     * semua test lalu ditolak MySQL di produksi.
     */
    public const MAX_LENGTH = 64;

    /**
     * Umur cookie identitas, dalam menit: lima tahun.
     */
    private const COOKIE_MINUTES = 5 * 365 * 24 * 60;

    /**
     * Token acak per perangkat, dalam byte. Delapan karakter heksadesimal sudah
     * jauh melebihi jumlah tablet yang wajar di satu toko.
     */
    private const TOKEN_BYTES = 4;

    /**
     * Satu-satunya bentuk yang diterima dari cookie.
     *
     * Cookie divalidasi, bukan dipercaya apa adanya: nama `device_id` bisa
     * saja sudah terisi aplikasi lain di domain yang sama, dan mengadopsinya
     * berarti satu identitas perangkat milik aplikasi lain menentukan shift
     * mana yang dianggap sudah terbuka.
     */
    private const REMEMBERED = '/^[A-Z0-9]{2,12}-[A-Z0-9]{2,12}-[0-9a-f]{8}$/';

    /**
     * Kunci penyimpanan sementara di request yang sedang berjalan.
     */
    private const ATTRIBUTE = 'device_id.current';

    /**
     * Identitas perangkat untuk request yang sedang berjalan, atau `null`.
     *
     * `null` berarti perangkat tidak bisa dikenali sama sekali: bukan perangkat
     * yang tidak sah, melainkan permintaan yang tidak membawa identitas apa pun
     * -- panggilan dari baris perintah, atau klien yang mengirim `User-Agent`
     * kosong. Mengisi kolomnya dengan nilai kosong akan membuat semua permintaan
     * itu diperlakukan sebagai satu perangkat yang sama, jadi `null` dibiarkan
     * sebagai `null`.
     */
    public static function current(): ?string
    {
        if (($deviceId = self::valid(session()->get(self::SESSION_KEY))) !== null) {
            return $deviceId;
        }

        if (($deviceId = self::valid(request()->header(self::HEADER))) !== null) {
            return $deviceId;
        }

        return self::automatic();
    }

    /**
     * Identitas yang diambil sendiri oleh server, sekali per request.
     */
    private static function automatic(): ?string
    {
        $request = request();

        if ($request->attributes->has(self::ATTRIBUTE)) {
            return $request->attributes->get(self::ATTRIBUTE);
        }

        $deviceId = self::remembered($request);

        if ($deviceId === null) {
            $deviceId = self::identify($request);

            if ($deviceId !== null) {
                self::remember($request, $deviceId);
            }
        }

        $request->attributes->set(self::ATTRIBUTE, $deviceId);

        return $deviceId;
    }

    /**
     * Identitas yang sudah pernah diberikan pada perangkat ini, atau `null`
     * bila cookie-nya belum ada atau bentuknya bukan milik kita.
     */
    private static function remembered(Request $request): ?string
    {
        $deviceId = self::valid($request->cookies->get(self::COOKIE));

        if ($deviceId === null || preg_match(self::REMEMBERED, $deviceId) !== 1) {
            return null;
        }

        return $deviceId;
    }

    /**
     * Identitas baru untuk perangkat yang belum pernah kita lihat, atau `null`
     * kalau `User-Agent`-nya tidak ada.
     */
    private static function identify(Request $request): ?string
    {
        $userAgent = $request->userAgent();

        if ($userAgent === null || trim($userAgent) === '') {
            return null;
        }

        $deviceId = sprintf(
            '%s-%s-%s',
            self::browser($userAgent),
            self::platform($userAgent),
            bin2hex(random_bytes(self::TOKEN_BYTES)),
        );

        return mb_strlen($deviceId) <= self::MAX_LENGTH ? $deviceId : null;
    }

    /**
     * Simpan identitas supaya request berikutnya membaca hal yang sama.
     *
     * Cookie `HttpOnly` karena tidak ada satu pun kode klien yang perlu
     * membacanya; `SameSite=Lax` karena identitas ini memang hanya boleh ikut
     * pada navigasi dari aplikasi sendiri.
     */
    private static function remember(Request $request, string $deviceId): void
    {
        Cookie::queue(cookie(
            self::COOKIE,
            $deviceId,
            self::COOKIE_MINUTES,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        ));
    }

    /**
     * Kelayakan satu nilai kandidat: ada isinya, dan muat di kolomnya.
     *
     * String kosong dan string berisi spasi diperlakukan sebagai tidak ada,
     * bukan sebagai nama perangkat. Kolomnya nullable, jadi string kosong akan
     * lolos sebagai nilai yang benar-benar tersimpan, dan setelah itu setiap
     * pemeriksaan "apakah perangkat ini punya shift terbuka" akan memperlakukan
     * semua perangkat kosong sebagai satu perangkat yang sama.
     */
    private static function valid(mixed $candidate): ?string
    {
        if (! is_string($candidate)) {
            return null;
        }

        $deviceId = trim($candidate);

        if ($deviceId === '' || mb_strlen($deviceId) > self::MAX_LENGTH) {
            return null;
        }

        return $deviceId;
    }

    /**
     * Pembuat peramban, singkat. Urutannya penting: Edge dan Chrome berdua
     * memuat potongan `Chrome/`, dan Safari memuat `Safari/` pada peramban yang
     * juga memuat `Chrome/`.
     */
    private static function browser(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'SamsungBrowser') => 'SBR',
            str_contains($userAgent, 'Edg/') => 'EDG',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'OPR',
            str_contains($userAgent, 'Firefox/') || str_contains($userAgent, 'FxiOS/') => 'FF',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'CHR',
            str_contains($userAgent, 'Safari/') => 'SAF',
            default => 'WEB',
        };
    }

    /**
     * Sistem operasi perangkat, singkat.
     */
    private static function platform(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'Android') => 'AND',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'IOS',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'MAC',
            str_contains($userAgent, 'Windows') => 'WIN',
            str_contains($userAgent, 'Linux') || str_contains($userAgent, 'X11') => 'LNX',
            default => 'OTH',
        };
    }
}
