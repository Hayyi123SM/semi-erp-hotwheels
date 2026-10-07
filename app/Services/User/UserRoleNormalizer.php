<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Menyamakan `users.role` ke ejaan yang bisa dibaca `App\Enums\Role`, untuk data
 * yang sudah ada.
 *
 * Kolomnya default-nya `staff` huruf kecil, sementara enum-nya `STAFF` dan
 * `OWNER`. Nilai yang tidak bisa di-cast itu menjawab salah setiap kali ditanya:
 * `isOwner()` dibandingkan dengan `Role::Owner` lalu menjawab tidak, dan setiap
 * keputusan akses yang bergantung pada role mewarisi jawaban yang salah itu.
 * Baris Owner yang tidak terbaca Owner adalah kegagalan yang paling mahal di
 * daftar ini, karena ia tidak kelihatan sebagai galat sama sekali.
 *
 * Logikanya hidup di sini, bukan di dalam migration, karena migration tidak bisa
 * diuji: `RefreshDatabase` sudah menjalankannya sebelum baris uji ada, jadi
 * `artisan migrate` di dalam test tidak melakukan apa-apa. Kelas ini bisa
 * dipanggil langsung, jadi salah baca role lama bisa dibuktikan dengan test dan
 * bukan hanya diasumsikan benar.
 */
class UserRoleNormalizer
{
    /**
     * Ejaan baku setiap nilai, dibaca dari enum bila bisa.
     *
     * @return array<string, string> huruf kecil => huruf baku
     */
    private function canonical(): array
    {
        $map = [];

        foreach (Role::cases() as $case) {
            $map[strtolower($case->value)] = $case->value;
        }

        return $map;
    }

    public function run(): int
    {
        $canonical = $this->canonical();

        $rows = DB::table('users')
            ->select(['id', 'role'])
            // `whereIn` dengan `DB::raw` memetakan kedua bagian dengan benar,
            // tidak seperti `whereInRaw` yang memperlakukan nilai sebagai SQL
            // mentah.
            ->whereIn(DB::raw('lower(role)'), array_keys($canonical))
            ->get();

        $rewritten = 0;

        foreach ($rows as $row) {
            $target = $canonical[strtolower((string) $row->role)];

            // Perbandingan di sini yang membedakan huruf besar dari huruf kecil.
            // `lower(role) = 'staff'` juga cocok dengan `STAFF`, jadi menyandarkan
            // keputusannya pada SQL akan menulis ulang baris yang sudah benar dan
            // menghitungnya sebagai perbaikan -- log lalu berbohong, dan migration
            // yang dijalankan dua kali terlihat seperti ada kerja lebihan. Tabel
            // user memang kecil, jadi satu per satu murah dan angkanya jujur.
            if ($row->role === $target) {
                continue;
            }

            DB::table('users')->where('id', $row->id)->update(['role' => $target]);
            $rewritten++;
        }

        if ($rewritten > 0) {
            // `Migration` tidak punya facility output, jadi hasilnya ditulis ke
            // log. Nilai role yang berubah menyentuh keputusan akses, jadi harus
            // ada jejaknya.
            Log::info('Normalisasi role users selesai.', ['baris_ditulis' => $rewritten]);
        }

        return $rewritten;
    }
}
