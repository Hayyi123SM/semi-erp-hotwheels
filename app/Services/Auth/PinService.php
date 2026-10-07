<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Otorisasi singkat untuk aksi sensitif yang nama Owner di atasnya.
 *
 * Dua arah, keduanya melewati kode yang sama:
 *
 *  - `issue()` memeriksa PIN yang diketik lalu menukarnya menjadi token. Yang
 *    ikut di form sebagai `pin_token` adalah token itu, bukan PIN-nya, sehingga
 *    PIN tidak ikut melintasi setiap POST dan tidak bisa diputar ulang dari log
 *    akses atau dari halaman yang tersimpan di browser.
 *
 *  - `describeFailure()` memeriksa token yang sudah ikut di form. Token yang
 *    benar, yang kedaluwarsa, yang milik aksi lain, yang diminta kasir lain, dan
 *    yang milik Owner yang sudah dinonaktifkan semuanya keluar dari satu jalur,
 *    sehingga tidak ada bentuk yang lolos hanya karena satu kasus lupa diperiksa.
 *
 * Tokennya terenkripsi, bukan ditandatangani. Yang dibutuhkan di sini adalah
 * hanya yang bisa menerbitkan, dan isinya tidak bisa diubah. Enkripsi memberi
 * dua hal itu sekaligus tanpa harus menyimpan apa pun di sisi server.
 *
 * Token sengaja tidak dibuat sekali pakai, dan ini keputusan yang disengaja.
 * Token sudah terikat ke Owner-nya, ke kasir yang meminta, ke satu aksi, dan ke
 * lima menit -- sehingga token yang bocor tidak berguna untuk aksi lain, tidak
 * berguna untuk orang lain, dan tidak berguna besok. Selain itu, sekali pakai
 * akan menambah satu masalah nyata: form yang ditolak karena field lain
 * yang salah, lalu dikirim ulang, akan meminta PIN Owner untuk kedua kalinya
 * dalam hitungan detik. Aturan seperti itu akan dilewati, dan cara paling
 * cepat untuk melewatinya adalah menyimpan PIN di field yang tidak pernah
 * dikosongkan -- persis kebocoran yang token ini dirancang untuk cegah.
 */
final class PinService
{
    /**
     * Umur token.
     *
     * Cukup untuk menyelesaikan satu commit, terlalu pendek untuk dipakai lagi
     * keesokan hari. Memanjangnya hanya memperpanjang jendela di mana PIN yang
     * bocor masih berlaku.
     */
    public const int LIFETIME_MINUTES = 5;

    private const string REJECT_NO_OWNER_PIN = 'Belum ada Owner yang mengatur PIN. Minta Owner mengaturnya di Pengaturan · Pengguna.';

    private const string REJECT_WRONG_PIN = 'PIN Owner salah.';

    private const string REJECT_INACTIVE = 'Akun sedang tidak aktif. Minta Owner mengaktifkannya kembali.';

    /**
     * Tukar PIN Owner yang diketik menjadi token berumur pendek.
     *
     * @throws ValidationException dengan key `pin` bila PIN tidak cocok
     */
    public function issue(User $actor, string $pin, string $context): PinGrant
    {
        // Dicek di sini, bukan hanya di `describeFailure`. Akun yang sudah
        // dinonaktifkan masih punya sesi yang hidup, jadi tanpa baris ini
        // kasir yang sudah dinonaktifkan bisa saja menukar PIN milik Owner
        // menjadi token yang sah selama masa berlakunya.
        if (! $actor->is_active) {
            throw ValidationException::withMessages(['pin' => [self::REJECT_INACTIVE]]);
        }

        $owner = $this->matchOwner($pin);
        $expiresAt = now()->addMinutes(self::LIFETIME_MINUTES);

        return new PinGrant(
            $this->encrypt([
                'sub' => (int) $owner->getKey(),
                'by' => (int) $actor->getKey(),
                'ctx' => $context,
                'exp' => $expiresAt->getTimestamp(),
            ]),
            $expiresAt,
            $owner,
        );
    }

    /**
     * Apakah token pada form ini masih sah untuk aksi ini.
     */
    public function check(?User $actor, ?string $token, string $context): bool
    {
        return $this->describeFailure($actor, $token, $context) === null;
    }

    /**
     * Owner yang memberi otorisasi atas token ini, atau `null` kalau tokennya
     * tidak sah.
     *
     * `check()` sudah menjawab "boleh atau tidak", tapi tidak menjawab "oleh
     * siapa" -- dan FR-AUTH-01 minta setiap override menyimpan `approved_by`.
     * Jadi pemanggil yang perlu menulis nama penotorisasi tidak boleh
     * membongkar isi tokennya sendiri atau mengulang seluruh rangkaian
     * pemeriksaan di tempat lain yang bisa tiba-tiba berbeda dari
     * `describeFailure()`.
     *
     * Memakai `describeFailure()` sebagai satu-satunya pintu gerbang, bukan
     * `check()` plus pencarian kedua, karena dua jalur pemeriksaan yang
     * berbeda adalah cara paling biasa untuk token yang dianggap sah oleh
     * satu tempat dan ditolak tempat lain.
     */
    public function approverFor(?User $actor, ?string $token, string $context): ?User
    {
        if ($this->describeFailure($actor, $token, $context) !== null) {
            return null;
        }

        $payload = $this->decrypt((string) $token);

        if ($payload === null) {
            return null;
        }

        $owner = User::query()->find($payload['sub']);

        return $owner instanceof User ? $owner : null;
    }

    /**
     * Alasan token ditolak, atau `null` bila tokennya sah.
     *
     * Dikembalikan sebagai kalimat siap tampil, bukan enum, karena pemanggilnya
     * langsung menampilkannya ke kasir yang sedang berdiri di depan printer.
     * Satu kalimat untuk satu sebab lebih jujur daripada kode yang harus
     * diterjemahkan di tiga tempat berbeda.
     */
    public function describeFailure(?User $actor, ?string $token, string $context): ?string
    {
        if ($actor === null || ! $actor->is_active) {
            return self::REJECT_INACTIVE;
        }

        if ($token === null || $token === '') {
            return 'Verifikasi PIN Owner belum dilakukan untuk aksi ini.';
        }

        $payload = $this->decrypt($token);

        if ($payload === null) {
            return 'Token PIN Owner tidak terbaca. Minta PIN Owner lalu ulangi.';
        }

        if ($payload['exp'] <= now()->getTimestamp()) {
            return 'Token PIN Owner sudah kedaluwarsa. Minta PIN Owner lalu ulangi.';
        }

        // `hash_equals` bukan karena konteksnya rahasia, melainkan karena keduanya
        // bebas dan panjangnya bisa berbeda, sehingga `===` bisa selesai lebih
        // awal di tengah perbandingan.
        if (! hash_equals($payload['ctx'], $context)) {
            return 'Token PIN Owner ini berlaku untuk aksi lain. Minta PIN Owner lagi untuk aksi ini.';
        }

        // Ikat ke kasir yang meminta. Tanpa ini, satu token yang bocor dari satu
        // tab bisa dipakai untuk menutupi aksi kasir lain di tab berikutnya.
        if ($payload['by'] !== (int) $actor->getKey()) {
            return 'Token PIN Owner ini diminta oleh kasir lain. Minta PIN Owner sendiri untuk aksi ini.';
        }

        $owner = User::query()->find($payload['sub']);

        // Dijemput ulang, bukan dipercaya begitu saja: antara PIN diberikan dan
        // aksi dijalankan, akun Owner bisa dinonaktifkan atau diturunkan perannya.
        if (! $owner instanceof User || ! $owner->is_active || ! $owner->isOwner()) {
            return 'Akun Owner yang memberi otorisasi sudah tidak berlaku.';
        }

        return null;
    }

    /**
     * Owner aktif yang PIN-nya sudah diatur.
     *
     * Owner yang belum pernah diberi PIN dilewati, bukan dihitung sebagai
     * kandidat: `Hash::check` terhadap null akan benar bagi PIN kosong, dan itu
     * justru akan membuka aksi yang harus tertutup.
     *
     * @return Collection<int, User>
     */
    private function ownersWithPin(): Collection
    {
        return User::query()
            ->where('role', Role::Owner)
            ->where('is_active', true)
            ->whereNotNull('pin')
            ->get();
    }

    /**
     * @throws ValidationException
     */
    private function matchOwner(string $pin): User
    {
        $owners = $this->ownersWithPin();

        if ($owners->isEmpty()) {
            throw ValidationException::withMessages(['pin' => [self::REJECT_NO_OWNER_PIN]]);
        }

        $matched = $owners->first(fn (User $owner): bool => Hash::check($pin, $owner->pin));

        if ($matched === null) {
            throw ValidationException::withMessages(['pin' => [self::REJECT_WRONG_PIN]]);
        }

        return $matched;
    }

    /**
     * @param  array{sub: int, by: int, ctx: string, exp: int}  $payload
     */
    private function encrypt(array $payload): string
    {
        // `JSON_THROW_ON_ERROR` supaya payload yang tidak bisa diserialisasi
        // menggagalkan penerbitan token, bukan menghasilkan token kosong.
        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{sub: int, by: int, ctx: string, exp: int}|null
     */
    private function decrypt(string $token): ?array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            // Token bukan milik kita, isinya sudah tidak utuh, atau formatnya
            // rusak. Ketiganya ditolak dengan cara yang sama, dan pemanggil tidak
            // perlu tahu mana yang terjadi.
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        foreach (['sub', 'by', 'ctx', 'exp'] as $key) {
            if (! array_key_exists($key, $decoded)) {
                return null;
            }
        }

        return [
            'sub' => (int) $decoded['sub'],
            'by' => (int) $decoded['by'],
            'ctx' => (string) $decoded['ctx'],
            'exp' => (int) $decoded['exp'],
        ];
    }
}
