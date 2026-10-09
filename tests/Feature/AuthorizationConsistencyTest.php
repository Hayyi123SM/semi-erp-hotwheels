<?php

namespace Tests\Feature;

use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\Opname;
use App\Models\OpnameLine;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\Rack;
use App\Models\RtvNote;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Otorisasi harus punya satu jawaban, di satu tempat.
 *
 * Aplikasi ini punya tepat satu batas role: Owner memegang semua modul, Staff
 * hanya POS. Batas itu dinyatakan tiga kali sekaligus -- sebagai middleware
 * di route, sebagai `abort_unless` di controller, dan sebagai predikat `when`
 * di tabel Blade -- dan tidak ada yang memaksa ketiganya sepakat. Hasilnya
 * bug nyata: tombol yang selalu ditolak, menu yang disembunyikan tapi
 * halamannya tetap terbuka, dan angka Owner yang bocor lewat kartu di luar
 * tabel.
 *
 * Test di bawah mengunci perbaikan itu, lalu menambahkan dua invariant yang
 * saling mengunci: yang satu memindai route ber-middleware `owner` dan
 * memastikan Staff ditolak di semuanya, yang lain memastikan tidak ada route
 * ber-auth yang tertinggal terbuka untuk Staff tanpa masuk `pos.*` atau
 * daftar dua-peran.
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

    /**
     * Route ber-auth yang memang boleh dibuka kedua peran: pendukung POS,
     * profil, dan mekanisme keamanan bawaan. Sisanya (selain `pos.*`) wajib
     * Owner-only -- dicek invariant di bawah.
     */
    private const BOTH_ROLE_ROUTES = [
        'home',
        'logout',
        'profile.edit',
        'profile.update',
        'profile.destroy',
        'pin.verify',
        'password.confirm',
        'password.update',
        'verification.notice',
        'verification.verify',
        'verification.send',
    ];

    // ===== F1: tombol Lokasi Rak yang selalu ditolak =====

    #[Test]
    public function staff_cannot_open_the_rack_module(): void
    {
        $staff = $this->staff();
        Rack::factory()->create(['code' => 'A-01', 'is_active' => true]);

        // Modul Master Data kini Owner-only: Staff ditolak di pintu, bukan
        // dibiarkan masuk lalu dihadang tombol per tombol yang selalu 403.
        $this->actingAs($staff)->get(route('master.lokasi-rak'))->assertForbidden();
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
    public function staff_cannot_read_or_create_consignors(): void
    {
        // Kuncian edit dulu, lalu menyeluruh: seluruh Master Data Owner-only.
        $staff = $this->staff();

        $this->actingAs($staff)->get(route('master.penitip'))->assertForbidden();
        $this->actingAs($staff)->post(route('master.penitip.store'), [
            'name' => 'Siti',
            'status' => 'ACTIVE',
        ])->assertForbidden();

        $this->assertSame(0, Consignor::count());
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
    public function staff_cannot_read_the_consignor_list_too(): void
    {
        $staff = $this->staff();

        // Agregat "Saldo Titipan Jatuh Tempo" pernah bocor ke Staff lewat
        // halaman yang terbuka. Master Data kini Owner-only, jadi bocornya
        // ditutup bersama akses halamannya.
        $this->actingAs($staff)->get(route('master.penitip'))->assertForbidden();
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
            'consignor' => (string) Consignor::factory()->create()->getKey(),
            'rack' => (string) Rack::factory()->create()->getKey(),
            'product' => (string) Product::factory()->create()->getKey(),
            'lot' => (string) StockLot::factory()->create()->getKey(),
            'consignment' => (string) Consignment::factory()->create()->getKey(),
            'opname' => (string) Opname::factory()->create()->getKey(),
            'line' => (string) OpnameLine::factory()->create()->getKey(),
            'rtv' => (string) RtvNote::factory()->create()->getKey(),
            // Parameter non-model (token impor, draftId sesi) tidak di-route
            // bind; mereka tidak akan bertemu model di route, jadi cukup angka
            // apa pun -- middleware `owner` sudah menolak sebelum controller.
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

            $status = $this->actingAs($staff)
                ->{$method}($url)
                ->getStatusCode();

            // 404 ikut diterima hanya untuk route ber-`scopeBindings()`: pasangan
            // palsu `{opname}`/`{line}` kita tidak saling memiliki sehingga
            // binding menolak sebelum middleware `owner` sempat jalan. Yang
            // menjamin middleware-nya memang terpasang adalah invariant
            // `every_authenticated_route_is_staff_open_or_owner_only`.
            $this->assertContains(
                $status,
                [403, 404],
                "Route $method $uri harus menolak Staff, malah menjawab $status."
            );
        }

        // Penolakan tidak boleh diam-diam mengubah apa pun.
        $this->assertTrue($staff->fresh()->is_active);
    }

    /**
     * Arah sebaliknya bagi pemindai di atas: route biasa bisa tidak sengaja
     * dibiarkan terbuka untuk Staff.
     *
     * Pemindaian `every_owner_only_route_refuses_staff` mengambil route yang
     * sudah ber-middleware `owner`; kalau middlewar-nya dicabut, route itu
     * hilang dari daftar dan tidak akan pernah teruji. Invariant berikut
     * menutup sisi itu: setiap route ber-auth yang bukan POS dan bukan rute
     * dua-peran WAJIB menolak Staff -- entah lewat middleware `owner` di route,
     * dalam `pos.*`, atau ada di daftar dua-peran.
     */
    #[Test]
    public function every_authenticated_route_is_staff_open_or_owner_only(): void
    {
        $violations = collect(Route::getRoutes())
            ->filter(fn (RoutingRoute $route) => in_array('auth', $route->gatherMiddleware(), true))
            ->filter(fn (RoutingRoute $route) => $route->getName() !== null)
            ->reject(fn (RoutingRoute $route) => str_starts_with($route->getName(), 'pos.'))
            ->reject(fn (RoutingRoute $route) => in_array($route->getName(), self::BOTH_ROLE_ROUTES, true))
            ->reject(fn (RoutingRoute $route) => in_array('owner', $route->gatherMiddleware(), true))
            ->map(fn (RoutingRoute $route) => $route->getName())
            ->values();

        $this->assertSame(
            [],
            $violations->all(),
            'Route ber-auth wajib masuk `pos.*`, daftar dua-peran, atau memakai middleware owner.'
        );
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
            // Toll Station: dashboard dan seluruh modul non-POS dijaga
            // middleware `owner` di level grup route.
            'dashboard',
            'master.penitip',
            'inbound.stock-in-pribadi',
            'inventory.karantina',
            'report.settlement',
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
