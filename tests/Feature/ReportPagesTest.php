<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dua halaman laporan: judul yang terbaca manusia, dan filter rentang tanggal.
 *
 * Judul dikirim sebagai prop komponen Blade, lalu dirender `{{ $title }}` yang
 * meng-escape sekali lagi. Menulis `&amp;` di blade sumber menghasilkan `&amp;amp;`
 * di layar -- karakter aksen yang tidak pernah dimaksudkan penulisnya, dan sulit
 * terlihat karena halaman tetap 200. Test di sini menahannya di bentuk escape
 * tunggal supaya tidak diam-diam kembali.
 *
 * Filter rentang diuji pada sisi markup: `from`/`to` harus tetap jadi nama input
 * yang tersubmit, dan nilainya harus ter-echo saat halaman dibuka dengan query
 * itu. Logikanya sendiri (perhitungan preset) diuji di `date-range.test.js`.
 */
class ReportPagesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function margin_heading_is_escaped_exactly_once(): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())
            ->get(route('report.margin'))
            ->assertOk();

        $response->assertSee('Analisis Margin &amp; Fee', false);
        $response->assertDontSee('&amp;amp;', false);
    }

    #[Test]
    public function laporan_heading_is_escaped_exactly_once(): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())
            ->get(route('report.laporan'))
            ->assertOk();

        $response->assertSee('Laporan Penjualan &amp; Stok', false);
        $response->assertDontSee('&amp;amp;', false);
    }

    #[Test]
    public function the_range_filter_labels_which_bound_is_which(): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())
            ->get(route('report.margin'))
            ->assertOk();

        $response->assertSee('Dari');
        $response->assertSee('Sampai');
        $response->assertSee('name="from"', false);
        $response->assertSee('name="to"', false);
    }

    #[Test]
    public function the_selected_range_is_echoed_back_with_a_reset(): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())
            ->get(route('report.laporan', ['from' => '2026-01-01', 'to' => '2026-01-31']))
            ->assertOk();

        $response->assertSee('value="2026-01-01"', false);
        $response->assertSee('value="2026-01-31"', false);
        $response->assertSee('Reset');
    }

    /**
     * Preset dan pengikat Alpine harus benar-benar mendarat di markup: kalau
     * Blade salah meng-escape-nya, dropdown-nya tampil tapi `x-on:change`-nya
     * mati dan tidak ada yang tahu sampai ada yang mencoba memilihnya.
     */
    #[Test]
    public function the_range_filter_offers_quick_presets_bound_to_alpine(): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())
            ->get(route('report.margin'))
            ->assertOk();

        $response->assertSee('Cepat pilih');
        $response->assertSee('x-data="dateRange(', false);
        $response->assertSee('applyPreset($event.target.value)', false);
        $response->assertSee('Hari ini');
        $response->assertSee('7 hari terakhir');
        $response->assertSee('30 hari terakhir');
        $response->assertSee('Bulan ini');
    }

    /**
     * Rentang tanggal milik halaman, bukan tabel; form pencarian tabel harus
     * ikut membawanya, atau pencarian diam-diam kembali ke 30 hari terakhir.
     */
    #[Test]
    public function the_laporan_table_form_forwards_the_page_range(): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())
            ->get(route('report.laporan', ['from' => '2026-01-01', 'to' => '2026-01-31']))
            ->assertOk();

        $response->assertSee('type="hidden" name="from"', false);
        $response->assertSee('type="hidden" name="to"', false);
    }

    /**
     * Diminta tanpa query, laporan tetap menyaring 30 hari terakhir; filter di
     * halaman harus menampilkan rentang yang sama, bukan dua input kosong.
     */
    #[Test]
    public function the_range_filter_shows_the_effective_default_window(): void
    {
        $response = $this->actingAs(User::factory()->owner()->create())
            ->get(route('report.laporan'))
            ->assertOk();

        $response->assertSee('value="'.now()->toDateString().'"', false);
        $response->assertSee('value="'.now()->subDays(29)->toDateString().'"', false);
    }

    /**
     * Dimensi `hari` mengelompokkan lewat `date(sales.sold_at)`. Kalau ekspresi
     * itu diserahkan ke `groupBy()` sebagai string, ia di-backtick menjadi nama
     * kolom literal dan query-nya meledak -- jadi setiap dimensi dirender di
     * sini, bukan hanya dimensi bawaan.
     */
    #[Test]
    public function every_margin_dimension_renders_without_a_grouping_error(): void
    {
        $owner = User::factory()->owner()->create();

        foreach (['seri', 'penitip', 'sku', 'hari'] as $dimension) {
            $this->actingAs($owner)
                ->get(route('report.margin', ['d' => $dimension]))
                ->assertOk();
        }
    }
}
