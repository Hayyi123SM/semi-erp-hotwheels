<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PagesRenderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('appRoutes')]
    public function page_renders_with_layout(string $route): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())
            ->get($route)
            ->assertOk();

        $response->assertSee('<!DOCTYPE html>', false);
        $this->assertAssetsAreReferenced($response);
    }

    /**
     * Halaman harus benar-benar memuat aset, tapi dari mana bergantung pada
     * ada atau tidaknya `public/hot`.
     *
     * Berkas `hot` itu milik `npm run dev`, dan bentuknya disengaja: kalau
     * ada, `@vite` menunjuk ke dev server; kalau tidak, ke `/build/assets/`.
     * Menebak `/build/assets/` membuat seluruh suite gagal di mesin yang
     * sedang menjalankan dev server -- 23 halaman sekaligus, tanpa satu pun
     * yang benar-benar rusak.
     */
    private function assertAssetsAreReferenced(TestResponse $response): void
    {
        $hot = trim((string) @file_get_contents(public_path('hot')));

        if ($hot !== '') {
            $response->assertSee($hot, false);

            return;
        }

        $response->assertSee('/build/assets/', false);
    }

    /**
     * Blade comment yang tidak tertutup (`{{-- ... -->`) lolos ke HTML sebagai
     * teks, dan `assertOk` tetap hijau karena responsnya 200 -- yang bocor cuma
     * kelihatan di layar. Memakai penanda yang sama persis dengan yang dipakai
     * Blade membuat kelas kesalahan ini Mustahil lolos tanpa disadari.
     */
    #[Test]
    #[DataProvider('appRoutes')]
    public function no_page_leaks_unstripped_blade_comments(string $route): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())
            ->get($route)
            ->assertOk();

        $response->assertDontSee('{{--', false);
        $response->assertDontSee('--}}', false);
    }

    #[Test]
    #[DataProvider('appRoutes')]
    public function page_renders_for_owner(string $route): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())
            ->get($route)
            ->assertOk();

        $response->assertSee('<!DOCTYPE html>', false);
    }

    /**
     * The toast has to be dismissible by hand as well as on a timer.
     *
     * The timer is the reason a message usually goes away on its own, and it is
     * the reason a failure was hard to miss: six seconds is not long enough to
     * read a sentence, go and do something about it, and come back. A reader who
     * needs longer than that needs a button, and the button is the only part of
     * this that has to be *in the markup* -- `notify.dismiss()` existing in the
     * module proves nothing about whether anything on the page calls it.
     */
    #[Test]
    public function the_toast_carries_a_control_that_dismisses_it(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/dashboard');

        $response->assertSee('$store.toast.dismiss(t.id)', false);
        $response->assertSee('Tutup notifikasi', false);
    }

    /**
     * And hovering one holds it still, so a pointer resting on a message does
     * not take it away mid-sentence.
     */
    #[Test]
    public function the_toast_pauses_its_clock_while_it_is_hovered(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/dashboard');

        $response->assertSee('@mouseenter="$store.toast.pause(t.id)"', false);
        $response->assertSee('@mouseleave="$store.toast.resume(t.id)"', false);
    }

    public static function appRoutes(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'master.penitip' => ['/master/penitip'],
            'master.katalog-produk' => ['/master/katalog-produk'],
            'master.lokasi-rak' => ['/master/lokasi-rak'],
            'inbound.stock-in-pribadi' => ['/inbound/stock-in-pribadi'],
            'inbound.consignment-in' => ['/inbound/consignment-in'],
            'inbound.consignment-in.riwayat' => ['/inbound/consignment-in/riwayat'],
            'inbound.cetak-label' => ['/inbound/cetak-label'],
            'inventory.live-stock' => ['/inventory/live-stock'],
            'inventory.karantina' => ['/inventory/karantina'],
            'inventory.stok-opname' => ['/inventory/stok-opname'],
            'inventory.retur-rtv' => ['/inventory/retur-rtv'],
            'pos.kasir' => ['/pos/kasir'],
            'pos.riwayat' => ['/pos/riwayat-transaksi'],
            'pos.shift-kasir' => ['/pos/shift-kasir'],
            'report.settlement' => ['/reports/consignor-settlement'],
            'report.margin' => ['/reports/profit-margin'],
            'report.laporan' => ['/reports/laporan-penjualan-stok'],
            'report.audit-log' => ['/reports/audit-log'],
            'setting.pengguna' => ['/settings/pengguna-role'],
            'setting.perangkat' => ['/settings/perangkat'],
            'setting.wa-template' => ['/settings/wa-template'],
            'setting.parameter' => ['/settings/parameter'],
        ];
    }
}
