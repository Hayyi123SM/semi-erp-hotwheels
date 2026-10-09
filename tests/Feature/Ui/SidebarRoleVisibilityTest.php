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
 * Satu-satunya batas peran di aplikasi ini: Staff hanya bekerja di POS. Sidebar
 * Staff hanya memuat grup POS / Kasir, dan section non-POS dibuang seluruhnya --
 * bukan sekadar diklik lalu 403. Owner melihat semua modul.
 */
class SidebarRoleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Label menu yang benar-benar dirender, dalam urutan dokumen.
     *
     * Dibaca lewat DOM, bukan pola markup, supaya atribut Alpine yang memuat tanda
     * kurung sudut tidak bisa mengecoh test ini. Halaman yang dipakai mengikuti
     * peran biarpun filtrinya memakai `auth()->user()`, bukan yang dirender.
     */
    private function menuLabels(User $user): array
    {
        $html = $this->actingAs($user)->get(route($user->homeRoute()))->getContent();

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
    public function staff_only_sees_the_pos_menu(): void
    {
        $labels = $this->menuLabels(User::factory()->staff()->create());

        $this->assertSame(['Kasir', 'Riwayat Transaksi', 'Shift Kasir'], $labels);
    }

    #[Test]
    public function staff_does_not_see_any_non_pos_menu(): void
    {
        $labels = $this->menuLabels(User::factory()->staff()->create());

        foreach ([
            'Dashboard',
            'Data Penitip',
            'Katalog Produk',
            'Lokasi Rak',
            'Stock In Pribadi',
            'Consignment In',
            'Cetak / Re-print Label',
            'Live Stock',
            'Karantina',
            'Stok Opname',
            'Retur Penitip (RTV)',
            'Consignor Settlement',
            'Profit Margin vs Fee',
            'Laporan Penjualan & Stok',
            'Audit Log',
            'Pengguna & Role',
            'Perangkat',
            'Template WhatsApp',
            'Parameter Sistem',
        ] as $label) {
            $this->assertNotContains($label, $labels, "Menu '$label' adalah non-POS dan wajib disembunyikan dari Staff.");
        }
    }

    #[Test]
    public function owner_still_sees_the_user_management_menu(): void
    {
        $labels = $this->menuLabels(User::factory()->owner()->create());

        $this->assertContains('Pengguna & Role', $labels);
    }

    #[Test]
    public function owner_still_sees_all_sections(): void
    {
        $labels = $this->menuLabels(User::factory()->owner()->create());

        foreach ([
            'Dashboard',
            'Data Penitip',
            'Live Stock',
            'Kasir',
            'Laporan Penjualan & Stok',
            'Pengguna & Role',
        ] as $label) {
            $this->assertContains($label, $labels);
        }
    }
}
