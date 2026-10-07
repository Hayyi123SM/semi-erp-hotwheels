<?php

namespace Tests\Feature;

use App\Models\Consignor;
use App\Models\ProductSeries;
use App\Models\Rack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Otorisasi harus punya satu jawaban, di satu tempat.
 *
 * Aplikasi ini punya tepat satu batas role: ada pekerjaan yang Owner lakukan
 * dan Staff tidak.SUDAH dulu batas itu dinyatakan tiga kali sekaligus --
 * sebagai middleware di route, sebagai `abort_unless` di controller, dan
 * sebagai predikat `when` di tabel Blade -- dan tidak ada yang memaksa ketiganya
 * sepakat. Hasilnya tiga bug nyata: tombol yang selalu ditolak, menu yang
 * disembunyikan tapi halamannya tetap terbuka, dan angka Owner yang bocor lewat
 * kartu di luar tabel.
 *
 * Test di bawah mengunci keempatnya, lalu menambahkan satu invariant yang
 * memindai route ber-middleware `owner` dan memastikan tidak ada yang bisa
 * dijangkau Staff. Invariant itulah yang dipasang supaya temuan berikutnya
 * tidak harus ditemukan manual.
 */
class AuthorizationConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    private function staff(): User
    {
        return User::factory()->staff()->create();
    }

    // ===== F1: tombol Lokasi Rak yang selalu ditolak =====

    #[Test]
    public function staff_sees_no_action_button_on_a_rack(): void
    {
        $staff = $this->staff();
        Rack::factory()->create(['code' => 'A-01', 'is_active' => true]);

        // Tombol "Aktif" pernah tampil untuk Staff lalu selalu 403, karena
        // predikatnya `! $isOwner || $rack->is_active` yang selalu benar untuk
        // role kedua. Sekarang Staff tidak boleh melihat satu pun tombol aksi.
        $html = $this->actingAs($staff)->get(route('master.lokasi-rak'))->getContent();

        $this->assertStringNotContainsString('Nonaktifkan', $html);
        $this->assertStringNotContainsString('Aktifkan', $html);
        $this->assertStringNotContainsString('Hapus', $html);
    }

    #[Test]
    public function owner_still_sees_the_matching_toggle_for_each_rack_state(): void
    {
        $owner = $this->owner();
        Rack::factory()->create(['code' => 'A-01', 'is_active' => true]);
        Rack::factory()->create(['code' => 'A-02', 'is_active' => false]);

        // Memakai string yang bisa muncul di CSS atau ikon akan salah positives,
        // jadi ini memeriksa label yang benar-benar dirender untuk tiap state.
        $html = $this->actingAs($owner)->get(route('master.lokasi-rak'))->getContent();

        $this->assertStringContainsString('Nonaktifkan', $html, 'Rak aktif harus punya aksi Nonaktifkan.');
        $this->assertStringContainsString('Aktifkan', $html, 'Rak nonaktif harus punya aksi Aktifkan.');
    }

    // ===== F7: Edit Penitip tersembunyi tapi server mengizinkan =====

    #[Test]
    public function staff_cannot_open_or_edit_a_consignor(): void
    {
        $staff = $this->staff();
        $consignor = Consignor::factory()->create();

        // Tombol Edit disembunyikan dari Staff sejak dulu, tapi `edit()` dan
        // `update()` tidak punya `abort_unless`, jadi Staff bisa mengetik URL
        // dan mengubah data penitip. UI yang hide bukan access control.
        $this->actingAs($staff)
            ->get(route('master.penitip.edit', $consignor))
            ->assertForbidden();

        $this->actingAs($staff)
            ->put(route('master.penitip.update', $consignor), [
                'name' => 'Nama Baru',
                'scheme_type' => 'PERCENTAGE',
                'scheme_rate' => '50',
                'status' => 'ACTIVE',
            ])
            ->assertForbidden();

        $this->assertNotSame('Nama Baru', $consignor->fresh()->name);
    }

    #[Test]
    public function staff_may_still_create_and_read_consignors(): void
    {
        // Mengencangkan edit tidak boleh menutup jalur yang memang terbuka.
        $staff = $this->staff();

        $this->actingAs($staff)->get(route('master.penitip'))->assertOk();
        $this->actingAs($staff)->post(route('master.penitip.store'), [
            'name' => 'Siti',
            'status' => 'ACTIVE',
        ])->assertRedirect(route('master.penitip'));

        $this->assertSame(1, Consignor::count());
    }

    // ===== F4: menu Pengguna disembunyikan tapi halamannya terbuka =====

    #[Test]
    public function staff_cannot_read_the_user_list_or_open_its_forms(): void
    {
        $staff = $this->staff();
        $target = $this->owner();

        // Menu disembunyikan dari sidebar, tapi `index()` tidak punya gate dan
        // route-nya tidak ber-middleware, jadi Staff bisa membaca nama,
        // username, email, dan role setiap orang hanya dengan mengetik URL.
        $this->actingAs($staff)->get(route('setting.pengguna'))->assertForbidden();
        $this->actingAs($staff)->get(route('setting.pengguna.create'))->assertForbidden();
        $this->actingAs($staff)->get(route('setting.pengguna.edit', $target))->assertForbidden();
    }

    // ===== F12: kartu saldo Owner bocor ke Staff =====

    #[Test]
    public function staff_does_not_see_the_due_balance_stat_card(): void
    {
        $staff = $this->staff();

        // Kolom "Saldo Jatuh Tempo" di tabel sudah disembunyikan dari Staff,
        // tapi agregatnya bocor lewat kartu di atas tabel. Mengetik angka yang
        // sama di tempat berbeda bukan menyembunyikan, hanya memindahkan.
        $html = $this->actingAs($staff)->get(route('master.penitip'))->getContent();

        $this->assertStringNotContainsString('Saldo Titipan Jatuh Tempo', $html);
    }

    #[Test]
    public function owner_still_sees_the_due_balance_stat_card(): void
    {
        $owner = $this->owner();

        $html = $this->actingAs($owner)->get(route('master.penitip'))->getContent();

        $this->assertStringContainsString('Saldo Titipan Jatuh Tempo', $html);
    }

    // ===== Invariant: setiap route `owner` menutup pintu untuk Staff =====

    /**
     * Route yang diberi middleware `owner`, dibaca langsung dari tabel route.
     */
    private function ownerRoutes(): array
    {
        return collect(Route::getRoutes())
            ->filter(fn (RoutingRoute $route) => in_array('owner', $route->gatherMiddleware(), true))
            ->map(fn (RoutingRoute $route) => [
                $route->methods()[0],
                $route->uri(),
                $route->parameterNames(),
            ])
            ->sortBy(fn (array $row) => $row[1])
            ->values()
            ->all();
    }

    private function parameterFor(string $name): string
    {
        return match ($name) {
            'user' => (string) $this->owner()->getKey(),
            'series' => (string) ProductSeries::factory()->create()->getKey(),
            default => 'x',
        };
    }

    #[Test]
    public function every_owner_only_route_refuses_staff(): void
    {
        $routes = $this->ownerRoutes();

        // Kalau pemindai ini tidak menemukan apa pun, perulangan di bawah
        // tidak menguji apa pun dan test ini tetap hijau. Itu bentuk kelulusan
        // yang paling berbahaya: hijau karena kosong.
        $this->assertNotEmpty($routes, 'Tidak ada route owner untuk dipindai.');

        $staff = $this->staff();

        foreach ($routes as [$method, $uri, $parameters]) {
            $url = $uri;
            foreach ($parameters as $parameter) {
                $url = str_replace('{'.$parameter.'}', $this->parameterFor($parameter), $url);
            }

            $this->actingAs($staff)
                ->{$method}($url)
                ->assertForbidden("Route $method $uri harus menolak Staff.");
        }

        // Penolakan tidak boleh diam-diam mengubah apa pun.
        $this->assertTrue($staff->fresh()->is_active);
    }

    /**
     * Arah sebaliknya: middleware `owner` bisa hilang tanpa apa-apa.
     *
     * Pemindaian di atas hanya melihat route yang masih memakai `owner`.
     * Kalau middleware dicabut dari salah satunya, route itu hilang dari daftar
     * dan pemindaian tidak akan pernah melewatinya -- persis cara ketiga
     * temuan ini bisa kembali tanpa terdeteksi.
     */
    #[Test]
    public function the_routes_fixed_by_this_audit_are_still_owner_only(): void
    {
        $protected = [
            // F4: yang dulu hanya disembunyikan dari sidebar.
            'setting.pengguna',
            'setting.pengguna.create',
            'setting.pengguna.store',
            'setting.pengguna.edit',
            'setting.pengguna.update',
            'setting.pengguna.toggle',
            // Ukuran label menentukan isi label yang keluar dari printer,
            // jadi halaman simpannya ikut daftar route yang wajib owner.
            'setting.perangkat.label.update',
        ];

        $routeNames = collect(Route::getRoutes())
            ->filter(fn (RoutingRoute $route) => in_array('owner', $route->gatherMiddleware(), true))
            ->map(fn (RoutingRoute $route) => $route->getName())
            ->filter()
            ->values()
            ->all();

        foreach ($protected as $name) {
            $this->assertContains(
                $name,
                $routeNames,
                "Route $name wajib ber-middleware owner; kalau tidak, Staff bisa membukanya lagi."
            );
        }
    }
}
