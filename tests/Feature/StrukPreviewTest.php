<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pratinjau struk di kartu "Printer Struk (POS-01)" dan gaya struk di halaman app.
 *
 * Dua hal yang dijaga di sini:
 *
 * 1. `receipt.css` harus benar-benar sampai ke halaman yang bisa menampilkan
 *    struk. Pratinjau struk dirender dari partial yang kelasnya hanya dimiliki
 *    stylesheet itu; tanpa dia, struk di dialog kasir tampil sebagai teks yang
 *    mengalir tanpa format -- persis bug yang pernah dilaporkan.
 * 2. Kartu Printer Struk menampilkan ketiga ukuran kertas dari data contoh,
 *    dan panel pratinjau itu terlihat oleh Staff yang tidak bisa mengubah
 *    setelannya: "kertas apa yang sedang berlaku" adalah pertanyaan yang harus
 *    bisa dijawab tanpa hak ubah.
 */
class StrukPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE_DOC_NO = 'HW-20261008-0042';

    #[Test]
    public function the_receipt_styles_reach_every_page_that_can_show_a_receipt(): void
    {
        $pages = [
            'kasir' => fn () => $this->actingAs(User::factory()->create())->get('/pos/kasir'),
            'perangkat' => fn () => $this->actingAs(User::factory()->owner()->create())->get(route('setting.perangkat')),
        ];

        foreach ($pages as $name => $load) {
            $stylesheets = $this->stylesheetHrefs($load()->assertOk()->content());

            $this->assertNotEmpty(
                array_filter($stylesheets, static fn (string $href): bool => str_contains($href, 'receipt')),
                "Halaman {$name} tidak memuat receipt.css -- pratinjau struk di sana akan tampil tanpa gaya.",
            );
        }
    }

    #[Test]
    public function the_perangkat_page_renders_a_preview_for_every_paper_size(): void
    {
        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('setting.perangkat'))
            ->assertOk()
            ->content();

        // Satu sheet per ukuran kertas, semua dari partial yang sama dengan
        // dialog kasir dan halaman cetak: satu sumber isi, bukan salinan.
        $this->assertSame(2, substr_count($html, 'receipt-sheet--thermal'));
        $this->assertSame(1, substr_count($html, 'receipt-sheet--a4'));
        $this->assertStringContainsString(self::SAMPLE_DOC_NO, $html);

        // Ketiga sheet diganti lewat state `paper` yang sama dengan select form,
        // jadi mengubah kertas langsung menukar pratinjau tanpa menyimpan.
        foreach (['58mm', '80mm', 'a4'] as $paper) {
            $this->assertStringContainsString(
                "(paper || '80mm') === '{$paper}'",
                $html,
                "Pratinjau {$paper} tidak punya pemicu x-show.",
            );
        }

        // Pratinjau tidak boleh menampilkan angka apa pun yang tidak ada pada
        // nota contoh: diskon, pembayaran, dan kembalian adalah tiga blok yang
        // paling sering hilang saat seseorang mengganti isi nota contoh.
        $this->assertStringContainsString('Diskon', $html);
        $this->assertStringContainsString('Kembalian', $html);
    }

    #[Test]
    public function the_preview_is_switchable_before_anything_is_saved(): void
    {
        // Belum pernah menyimpan kertas: select memang menampilkan "Belum
        // disimpan", tapi pratinjau harus tetap menunjukkan 80 mm sebagai
        // bawaan -- area kosong membuat kartu terlihat rusak.
        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('setting.perangkat'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString("paper || '80mm'", $html);
    }

    #[Test]
    public function staff_sees_the_preview_but_not_the_form(): void
    {
        $html = $this->actingAs(User::factory()->staff()->create())
            ->get(route('setting.perangkat'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('Pratinjau struk', $html);
        $this->assertStringNotContainsString('Simpan Pengaturan Struk', $html);
        $this->assertStringNotContainsString('Uji Cetak Thermal', $html);
    }

    /**
     * Semua `href` stylesheet di head -- mode dev maupun bundle terbangun.
     *
     * Menguji lewat HTML dirender, bukan lewat isi layout: yang putus justru
     * rantai `@vite` → manifest → tag link di halaman tertentu, dan itu baru
     * terlihat saat halamannya benar-benar diminta.
     *
     * @return list<string>
     */
    private function stylesheetHrefs(string $html): array
    {
        preg_match_all('/<link[^>]+rel="stylesheet"[^>]*>/m', $html, $matches);

        $hrefs = [];

        foreach ($matches[0] as $tag) {
            if (preg_match('/href="([^"]+)"/', $tag, $href) === 1) {
                $hrefs[] = $href[1];
            }
        }

        return $hrefs;
    }
}
