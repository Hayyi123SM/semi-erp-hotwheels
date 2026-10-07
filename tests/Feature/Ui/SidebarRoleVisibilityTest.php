<?php

namespace Tests\Feature\Ui;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Menu yang ditampilkan tidak boleh lebih banyak dari menu yang boleh dibuka.
 *
 * Sidebar menampilkan 22 entri untuk siapa pun yang masuk, termasuk `Pengguna &
 * Role` yang hanya boleh dibuka Owner. Batas itu baru ditemukan setelah diklik
 * dan dijawab 403, jadi halaman yang tidak bisa dipakai tetap diiklankan
 * seolah-olah bisa.
 *
 * Yang sengaja tidak disembunyikan adalah `Stock In Pribadi`. Halamannya memang
 * terbuka untuk Staff dan menjelaskan bagian mana yang hanya untuk Owner, jadi
 * menyembunyikannya hanya memindahkan pertanyaan ke tempat yang tidak
 * menjawabnya.
 */
class SidebarRoleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Label menu yang benar-benar dirender, dalam urutan dokumen.
     *
     * Dibaca lewat DOM, bukan pola markup, supaya atribut Alpine yang memuat tanda
     * kurung sudut tidak bisa mengecoh test ini, dan supaya test ini bisa
     * sekaligus menyatakan "tepat entri ini, tidak ada yang lain".
     */
    private function menuLabels(User $user): array
    {
        $html = $this->actingAs($user)->get(route('dashboard'))->getContent();

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<!DOCTYPE html><html><body>'.$html.'</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $labels = [];

        foreach ($xpath->query("//nav[@aria-label='Navigasi utama']//span[contains(@class, 'sidebar-label')]") as $span) {
            $label = trim($span->textContent);

            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    #[Test]
    public function staff_does_not_see_the_user_management_menu(): void
    {
        $labels = $this->menuLabels(User::factory()->staff()->create());

        $this->assertNotContains('Pengguna & Role', $labels);
    }

    #[Test]
    public function owner_still_sees_the_user_management_menu(): void
    {
        // Disembunyikan dari semua orang, halaman Owner hanya bisa dijangkau
        // lewat URL yang tidak diiklankan. Itu bukan perbaikan.
        $labels = $this->menuLabels(User::factory()->owner()->create());

        $this->assertContains('Pengguna & Role', $labels);
    }

    #[Test]
    public function hiding_the_owner_menu_takes_exactly_one_entry(): void
    {
        // Yang dijaga hanya satu entri, jadi entri lain harus tetap sama
        // persis. Kalau filter ini terlalu rakus, di sinilah kelihatan, bukan
        // dari halaman yang tiba-tiba kehilangan sebagian besar menunya.
        $owner = $this->menuLabels(User::factory()->owner()->create());
        $staff = $this->menuLabels(User::factory()->staff()->create());

        $this->assertSame(
            array_values(array_diff($owner, ['Pengguna & Role'])),
            $staff,
        );
    }

    #[Test]
    public function staff_still_sees_the_page_that_explains_the_owner_only_part(): void
    {
        $labels = $this->menuLabels(User::factory()->staff()->create());

        $this->assertContains('Stock In Pribadi', $labels);
    }
}
