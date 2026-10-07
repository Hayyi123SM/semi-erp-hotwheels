<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bentuk markup halaman kasir.
 *
 * Layout POS adalah keputusan yang tidak bisa diuji dari test DOM: yang benar
 * di 1280px bisa berantakan di 768px tanpa satu pun kegagalan di kode. Test di
 * bawah menjaga keputusan yang menghasilkan layout itu -- di mana grid pecah,
 * dan apa yang boleh muncul di panel pembayaran -- supaya tidak berubah diam-diam
 * di perubahan berikutnya.
 *
 * Yang diuji di sini adalah batasannya, bukan tampilannya: lebar kolom dan tinggi
 * font memang tidak bisa dijaga dari sini. Layout最终 tetap perlu dilihat di
 * 768px, 820px, dan 1280px.
 */
class KasirLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const BLADE = 'resources/views/pages/pos/kasir.blade.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * Isi blade tanpa komentar Blade-nya.
     *
     * Komentar di file ini menjelaskan attribute yang sengaja dihapus, jadi ia
     * menyebut attribute itu secara harfiah. Assertion di bawah harus menilai
     * markup yang benar-benar dirender, bukan kebetulan kata yang sama muncul
     * di penjelasan.
     */
    private function bladeMarkup(): string
    {
        return preg_replace('/\{\{--.*?--\}\}/s', '', $this->bladeSource());
    }

    private function bladeSource(): string
    {
        return file_get_contents(base_path(self::BLADE));
    }

    public function test_grid_peaks_at_lg_not_md(): void
    {
        $blade = $this->bladeMarkup();

        // Di 768px sidebar jadi rail icon 4.5rem, jadi panel pembayaran hanya
        // mendapat 198px -- dan total 36px bold butuh ~230px. Memecah di `md`
        // membuat total dan tombol quick cash meluber tepat di ukuran tablet yang
        // paling sering dipakai kasir.
        $this->assertStringContainsString('lg:grid-cols-[minmax(0,62fr)_minmax(0,38fr)]', $blade);
        $this->assertStringNotContainsString('md:grid-cols-', $blade);
    }

    public function test_cart_column_scrolls_only_when_it_has_a_fixed_height(): void
    {
        $blade = $this->bladeMarkup();

        // `overflow-y-auto` tanpa `min-h-0` di dalam grid tidak pernah menggulir:
        // grid item punya tinggi otomatis, jadi tidak ada yang meluap. `min-h-0`
        // yang mengizinkannya menyusut ke tinggi baris grid.
        $this->assertStringContainsString('lg:min-h-0 lg:overflow-y-auto', $blade);
    }

    public function test_riwayat_transaction_is_not_duplicated_next_to_the_pay_button(): void
    {
        $blade = $this->bladeMarkup();

        // Sidebar sudah punya entri ini di `layouts/sidebar.blade.php`. Di halaman
        // kasir hanya jadi permukaan kedua yang bisa tidak sengaja terkena, tepat
        // di atas tombol bayar.
        $this->assertStringNotContainsString('route(\'pos.riwayat\')', $blade);

        // Label tombol tidak boleh menjanjikan cetak: alur cetak struk POS belum
        // ada di aplikasi ini, jadi tombol yang menjanjikannya membuat kasir
        // menekannya berulang menunggu printer yang tidak akan merespons.
        $this->assertStringNotContainsString('Cetak Struk', $blade);
        $this->assertStringContainsString("paying ? 'Menyimpan...' : 'Bayar'", $blade);
    }

    public function test_dead_show_numpad_scope_is_gone(): void
    {
        // `showNumpad` tidak dibaca di mana pun pada halaman, jadi `x-data` itu
        // hanya membagi rantai resolusi scope tanpa memberi apa pun. Yang dicari
        // adalah atributnya, bukan kata tersebut: blade masih menyebut
        // `showNumpad` di komentar yang menjelaskan kenapa ia dihapus.
        $this->assertStringNotContainsString('x-data="{ showNumpad', $this->bladeMarkup());
    }

    public function test_quick_cash_gives_every_button_a_full_row_of_width_on_tablet(): void
    {
        $blade = $this->bladeMarkup();

        // Di 198px, tiga tombol quick cash mendapat 60px masing-masing untuk label
        // `Rp150.000` yang butuh ~85px. Dua kolom di bawah `lg` memberinya ruang
        // yang tidak perlu dikecilkan dengan memotong teksnya.
        $this->assertStringContainsString('grid grid-cols-2 gap-2 lg:grid-cols-3', $blade);
        $this->assertStringContainsString('col-span-2 h-14', $blade);
    }

    public function test_pay_button_sits_where_it_can_be_seen_not_where_it_can_be_found(): void
    {
        $blade = $this->bladeMarkup();

        // `mt-auto` hanya di atas `lg`: di bawah itu panelnya adalah baris dengan
        // tinggi sendiri, jadi tombol yang menempel ke dasar justru membuat kasir
        // mencari tombol bayar.
        $this->assertStringContainsString('lg:mt-auto lg:pt-6', $blade);
    }
}
