<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\User\UserRoleNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Role hasil pendaftaran tidak boleh bergantung pada default kolom.
 *
 * Kolom `users.role` default-nya `staff` huruf kecil, sementara enum
 * `App\Enums\Role` memakai `STAFF` dan `OWNER`. Nilai yang tidak bisa di-cast
 * itu menjawab salah setiap kali ditanya: `isOwner()` dibandingkan dengan
 * `Role::Owner` dan menjawab tidak, dan setiap keputusan akses yang bergantung
 * pada role mewarisi jawaban yang salah itu.
 *
 * Dua lapis diperbaiki. Form sekarang menulis role-nya sendiri, karena apa pun
 * yang happen default kolomnya, pendaftaran tidak boleh menerima role dari
 * pemohon. Migrationnya memperbaiki default dan baris yang sudah tertulis.
 */
class RegistrationRoleTest extends TestCase
{
    use RefreshDatabase;

    private function register(array $overrides = []): TestResponse
    {
        return $this->post('/register', array_merge([
            'name' => 'Budi',
            'username' => 'budi',
            'email' => 'budi@example.test',
            'password' => 'rahasia-kuat-123',
            'password_confirmation' => 'rahasia-kuat-123',
        ], $overrides));
    }

    #[Test]
    public function a_registered_user_becomes_staff_in_the_spelling_the_enum_reads(): void
    {
        $this->register()->assertRedirect(route('dashboard', absolute: false));

        $user = User::sole();

        $this->assertSame(Role::Staff, $user->role);
        $this->assertSame('STAFF', $user->getRawOriginal('role'));
    }

    #[Test]
    public function a_registered_user_is_active(): void
    {
        // Kalau tidak ditulis eksplisit, aturan kolom yang berlaku yang menentukan,
        // dan aturan itu bisa berubah tanpa ada yang melihat efeknya di sini.
        $this->register();

        $this->assertTrue(User::sole()->is_active);
    }

    #[Test]
    public function the_form_cannot_ask_for_a_role(): void
    {
        // Form pendaftaran tidak punya kolom role sama sekali, jadi role yang
        // dikirim pemohon harus diabaikan, bukan dipercaya.
        $this->register(['role' => 'OWNER']);

        $this->assertSame(Role::Staff, User::sole()->role);
    }

    #[Test]
    public function the_column_default_is_something_the_enum_can_read(): void
    {
        // Insert tanpa role sama sekali harus tetap menghasilkan nilai yang bisa
        // di-cast. Inilah jalur yang dipakai seed, import, dan tinker.
        $id = DB::table('users')->insertGetId([
            'name' => 'Tanpa Role',
            'username' => 'tanpa-role',
            'password' => bcrypt('rahasia-kuat-123'),
        ]);

        $this->assertSame('STAFF', DB::table('users')->where('id', $id)->value('role'));
        $this->assertSame(Role::Staff, User::findOrFail($id)->role);
    }

    /**
     * Tuliskan role mentah ke database, melewati cast.
     *
     * Meniru baris yang sudah ada sebelum migrasi ini, yang ditulis lewat
     * default kolom yang salah ejaan.
     */
    private function legacyUser(string $username, string $rawRole): void
    {
        DB::table('users')->insert([
            'name' => 'Legacy '.$username,
            'username' => $username,
            'password' => bcrypt('rahasia-kuat-123'),
            'role' => $rawRole,
        ]);
    }

    #[Test]
    public function the_migration_recases_rows_written_in_the_old_spelling(): void
    {
        $this->legacyUser('legacy-staff', 'staff');
        $this->legacyUser('legacy-owner', 'owner');

        // Perbandingan dibuat case-insensitive, jadi ejaan campur pun ikut
        // diperbaiki, bukan hanya dua string yang disebut di migration.
        $this->legacyUser('legacy-mixed', 'Staff');
        $this->legacyUser('legacy-mixed-owner', 'oWnEr');

        $this->assertSame(4, app(UserRoleNormalizer::class)->run());

        $this->assertSame(
            ['OWNER' => 2, 'STAFF' => 2],
            DB::table('users')->selectRaw('role, count(*) as c')->groupBy('role')->pluck('c', 'role')->all(),
        );

        $this->assertSame(2, User::where('role', Role::Owner)->count());
    }

    #[Test]
    public function a_recased_owner_can_do_owner_work_afterwards(): void
    {
        // Inilah yang gagal sebelum migrasi: baris owner huruf kecil tidak pernah
        // terbaca `isOwner()` sebagai benar, jadi akses Owner hilang tanpa galat.
        $this->legacyUser('legacy-owner', 'owner');

        app(UserRoleNormalizer::class)->run();

        $user = User::where('username', 'legacy-owner')->sole();

        $this->assertTrue($user->isOwner());
        $this->actingAs($user)->get(route('setting.pengguna'))->assertOk();
    }

    #[Test]
    public function recasing_twice_changes_nothing(): void
    {
        // Migration yang dijalankan ulang tidak boleh damaging baris yang sudah
        // benar, jadi hasilnya harus nol dan tidak ada yang berubah.
        $this->legacyUser('legacy-staff', 'staff');
        app(UserRoleNormalizer::class)->run();

        $this->assertSame(0, app(UserRoleNormalizer::class)->run());
        $this->assertSame('STAFF', User::where('username', 'legacy-staff')->sole()->getRawOriginal('role'));
    }
}
