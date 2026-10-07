<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Menonaktifkan sebuah akun harus berlaku seketika, bukan berlaku pada login
 * berikutnya saja.
 *
 * Kolom `is_active` bisa ditulis sejak awal dan tombolnya ada di Pengaturan, tapi
 * tidak ada satu pun tempat yang membacanya: `LoginRequest` hanya memeriksa kata
 * sandi, dan tidak ada pemeriksaan pada request yang sedang berjalan. Owner pun
 * bisa menonaktifkan orang yang sedang bekerja, dan orang itu tetap bekerja
 * sampai sesinya berakhir dengan sendirinya.
 *
 * Dua sisi di bawah menguji dua hal yang berbeda. Menolak login belum cukup untuk
 * sisi kedua, karena sesi yang sudah terbuka tidak melewati login.
 */
class InactiveAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('budi|127.0.0.1');
    }

    private function inactiveUser(): User
    {
        return User::factory()->inactive()->create([
            'username' => 'budi',
            'password' => bcrypt('rahasia-kuat'),
        ]);
    }

    #[Test]
    public function a_deactivated_account_cannot_log_in_even_with_the_right_password(): void
    {
        $this->inactiveUser();

        $this->post('/login', [
            'username' => 'budi',
            'password' => 'rahasia-kuat',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    #[Test]
    public function the_refusal_names_the_reason_instead_of_a_broken_password(): void
    {
        // Memberitahu kata sandi salah padahal kata sandinya benar hanya
        // mengarahkan orang mereset sesuatu yang tidak perlu direset.
        $this->inactiveUser();

        $this->post('/login', [
            'username' => 'budi',
            'password' => 'rahasia-kuat',
        ])->assertSessionHasErrorsIn('default', 'username');

        $this->assertStringContainsString(
            'dinonaktifkan',
            session('errors')->first('username'),
        );
    }

    #[Test]
    public function a_wrong_password_on_a_deactivated_account_says_nothing_about_the_account(): void
    {
        // Keadaan akun baru dilaporkan setelah kata sandinya terbukti benar.
        // Kalau tidak, form login berubah menjadi daftar username yang
        // dinonaktifkan.
        $this->inactiveUser();

        $this->post('/login', [
            'username' => 'budi',
            'password' => 'salah',
        ])->assertSessionHasErrors('username');

        $this->assertStringNotContainsString(
            'dinonaktifkan',
            session('errors')->first('username'),
        );
    }

    #[Test]
    public function a_refused_login_leaves_no_session_behind(): void
    {
        // `Auth::attempt()` sudah membuka sesi pada saat akun diperiksa, jadi
        // penolakan harus menutupnya kembali.
        $this->inactiveUser();

        $this->post('/login', [
            'username' => 'budi',
            'password' => 'rahasia-kuat',
        ]);

        $this->assertGuest();
    }

    #[Test]
    public function an_open_session_ends_on_the_next_request(): void
    {
        $user = User::factory()->staff()->create();

        // Masuk seperti biasa, jadi sesinya sah dan akunnya masih bisa dipakai.
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        // Owner menonaktifkan akun itu selagi sesinya masih terbuka.
        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function the_refused_session_id_does_not_survive(): void
    {
        // `logout()` sendiri meninggalkan id lama di perangkat. Id itu harus
        // berpindah, kalau tidak request berikutnya yang datang oleh browser
        // kembali ke id yang sama.
        $user = User::factory()->staff()->create();
        $before = session()->getId();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertNotSame($before, session()->getId());
    }

    #[Test]
    public function a_json_client_is_told_it_is_not_signed_in(): void
    {
        // 302 menuju halaman login HTML tidak bisa dibaca oleh klien JSON, dan
        // 422 pada field tertentu akan berarti "input-mu salah" -- yang di sini
        // tidak terjadi.
        $user = User::factory()->staff()->inactive()->create();

        $this->actingAs($user)
            ->postJson('/pin/verify', ['pin' => '123456'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Akun ini dinonaktifkan. Minta Owner mengaktifkannya kembali di Pengaturan · Pengguna.');

        $this->assertGuest();
    }

    #[Test]
    public function an_active_account_is_untouched(): void
    {
        // Yang diperiksa adalah `is_active`, bukan sekadar ada atau tidaknya
        // middleware yang kini berjalan di setiap request.
        $user = User::factory()->staff()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $this->assertAuthenticatedAs($user);
    }
}
