<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LabelPaperMode;
use App\Models\Setting;
use App\Models\User;
use App\Services\Label\LabelPrinterSettings;
use App\Services\Label\LabelTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Endpoint hitung grid untuk angka yang sedang diketik di form Perangkat.
 *
 * Endpoint ini yang membuat ringkasan grid di form bisa bergerak mengikuti angka
 * Owner tanpa menyalin rumus `SheetGridCalculator` ke JavaScript. Yang dijaga di
 * sini bukan hanya happy path: Owner justru butuh penjelasan saat angkanya
 * DITOLAK, jadi angka yang belum lengkap dan angka yang tidak masuk akal harus
 * dibalas dengan bentuk yang bisa ditampilkan, bukan dengan 422.
 *
 * Bentuk JSON-nya juga ikut dijaga. Field ini dibaca Alpine lewat `x-text` dan
 * `x-show`, jadi nama key-nya adalah bagian dari kontrak, bukan detail internal.
 */
class LabelSheetPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->owner()->create());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'default_template' => LabelTemplate::QrOnly->value,
            'sheet_media_width_mm' => '100',
            'sheet_media_height_mm' => '150',
            'sheet_has_gap' => '1',
            'sheet_gap_mm' => '2',
            'max_print_width_mm' => '108',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function preview(array $overrides = []): array
    {
        return $this->postJson(route('setting.perangkat.label.preview'), $this->form($overrides))
            ->assertOk()
            ->json();
    }

    #[Test]
    public function it_returns_the_grid_for_the_numbers_being_typed(): void
    {
        $json = $this->preview();

        $this->assertTrue($json['readable']);
        $this->assertTrue($json['fits']);
        $this->assertSame([], $json['errors']);
        $this->assertSame(
            '100 x 150 mm · 6 kolom x 8 baris = 48 label per halaman',
            $json['summary'],
        );
        $this->assertSame(48, $json['labelsPerSheet']);
        $this->assertSame('100mm 150mm', $json['pageSize']);
        $this->assertSame('16 mm', $json['trailingSlack']);
    }

    /**
     * Angka yang belum lengkap harus dijawab, bukan ditolak.
     *
     * Owner sedang mengetik, jadi "lebar kertas masih kosong" adalah keadaan
     * normal -- bukan kesalahan yang perlu dibalas dengan 422. Kalau endpoint ini
     * menolak, ringkasan grid akan berkedip hilang setiap kali Owner menghapus
     * kolom untuk mengetik ulang, dan pesannya sendiri tidak akan pernah tampil.
     */
    #[Test]
    public function an_empty_paper_size_is_answered_as_not_readable_yet(): void
    {
        $json = $this->preview(['sheet_media_width_mm' => '', 'sheet_media_height_mm' => '']);

        $this->assertFalse($json['readable']);
        $this->assertFalse($json['fits']);
        $this->assertSame(['sheet_media_width_mm', 'sheet_media_height_mm'], $json['missing']);
    }

    /**
     * Celah yang dikosongkan tidak boleh dihitung sebagai kolom kosong saat
     * centangnya mati.
     *
     * Kolomnya dinonaktifkan browser, jadi tidak pernah dikirim. Kalau endpoint
     * ini tetap menunggunya, Owner yang sedang menyetel label tanpa celah akan
     * melihat ringkasan yang tidak pernah selesai dihitung.
     */
    #[Test]
    public function a_gap_that_is_switched_off_does_not_block_the_preview(): void
    {
        $json = $this->preview(['sheet_has_gap' => '0', 'sheet_gap_mm' => '']);

        $this->assertTrue($json['readable']);
        $this->assertSame(
            '100 x 150 mm · 6 kolom x 10 baris = 60 label per halaman',
            $json['summary'],
        );
    }

    /**
     * Celah yang dikosongkan SAAT centangnya menyala tetap harus menggantung.
     *
     * Kebalikan dari kasus sebelumnya: centang menyala berarti Owner sedang
     * memakai celah, jadi angka yang belum ada membuat hitungannya belum bisa
     * dipercaya.
     */
    #[Test]
    public function an_empty_gap_still_blocks_the_preview_while_the_box_is_checked(): void
    {
        $json = $this->preview(['sheet_gap_mm' => '']);

        $this->assertFalse($json['readable']);
        $this->assertSame(['sheet_gap_mm'], $json['missing']);
    }

    /**
     * Angka yang bukan angka harus diperlakukan sebagai belum diisi.
     *
     * Kolomnya `type="number"`, jadi browser tidak akan mengirim huruf -- tapi
     * request bisa datang dari integrasi lain atau form yang sudah terbuka
     * sebentar. Teks seperti "abc" tidak boleh dibaca sebagai `0`, karena Owner
     * akan melihat "kertas 0 x 0 mm" dan mengira rumusnya yang salah.
     */
    #[Test]
    public function text_is_treated_as_missing_rather_than_as_zero(): void
    {
        $json = $this->preview(['sheet_media_width_mm' => 'abc']);

        $this->assertFalse($json['readable']);
        $this->assertSame(['sheet_media_width_mm'], $json['missing']);
    }

    /**
     * Kertas yang melebihi area cetak harus ditolak di kolom kertasnya, dengan
     * kalimat yang sama seperti yang muncul setelah Owner menekan Simpan.
     *
     * Dua jalur ini -- preview dan simpan -- harus menunjuk kolom yang sama. Kalau
     * preview menunjukkan alasannya di kolom lain, Owner akan memperbaiki kolom
     * yang salah dan tetap ditolak.
     */
    #[Test]
    public function a_paper_wider_than_the_print_area_is_reported_on_the_width_field(): void
    {
        $json = $this->preview(['sheet_media_width_mm' => '120']);

        $this->assertTrue($json['readable']);
        $this->assertFalse($json['fits']);
        $this->assertArrayHasKey('sheet_media_width_mm', $json['errors']);
        $this->assertStringContainsString(
            'lebih lebar dari area cetak printer 108 mm',
            $json['errors']['sheet_media_width_mm'][0],
        );
    }

    /**
     * Label yang lebih besar dari kertas ditunjuk ke kolom KERTAS, bukan kolom
     * label.
     *
     * Arahnya penting: Owner tidak mengetik ukuran label, dia memilih preset.
     * Satu-satunya yang bisa dia ubah dari sisi itu adalah kertasnya, jadi pesan
     * yang mengarah ke kolom label hanya akan membuat Owner mengulanginya
     * dengan preset yang sama.
     */
    #[Test]
    public function a_label_larger_than_the_paper_is_reported_on_the_paper(): void
    {
        $json = $this->preview([
            'default_template' => LabelTemplate::FourByThree->value,
            'sheet_media_width_mm' => '30',
            'sheet_media_height_mm' => '20',
        ]);

        $this->assertTrue($json['readable']);
        $this->assertFalse($json['fits']);
        $this->assertArrayHasKey('sheet_media_width_mm', $json['errors']);
        $this->assertArrayNotHasKey(
            'default_template',
            $json['errors'],
            'Pesan tidak boleh mengarah ke kolom yang tidak bisa diubah Owner.',
        );
    }

    /**
     * Ukuran label yang tidak dikenal tidak boleh membuat endpoint ini gagal.
     *
     * Form yang sudah terbuka saat template lama dihapus masih bisa mengirim
     * nilai yang tidak dikenal. Endpoint ini hanya untuk menampilkan ringkasan,
     * jadi jawaban yang benar adalah hitungkan memakai template yang aktif --
     * bukan 500.
     */
    #[Test]
    public function an_unknown_template_falls_back_to_the_active_one(): void
    {
        Setting::set(LabelPrinterSettings::DEFAULT_TEMPLATE_KEY, LabelTemplate::ThreeByTwo->value);

        $json = $this->preview(['default_template' => 'ukuran-hantu']);

        $this->assertTrue($json['readable']);
        $this->assertTrue($json['fits']);
    }

    /**
     * Batas cetak yang tidak dikirim tetap memakai yang tersimpan.
     *
     * Form punya kolomnya, tapi request lain belum tentu mengirimnya.
     * Hilangnya batas cetak hanya karena tidak dikirim akan membuat preview
     * menganggap kertas 150 mm muat pada printer yang sebenarnya hanya bisa 108 mm.
     */
    #[Test]
    public function a_missing_print_limit_keeps_the_stored_one(): void
    {
        Setting::set(LabelPrinterSettings::MAX_PRINT_WIDTH_MM_KEY, '216');

        $json = $this->preview(['max_print_width_mm' => '', 'sheet_media_width_mm' => '150']);

        $this->assertTrue($json['readable']);
        $this->assertTrue($json['fits'], '150 mm masih muat di printer 216 mm.');
    }

    /**
     * Endpoint ini hanya membaca, jadi Staff tidak boleh memakainya. Angka yang
     * dikembalikan menentukan isi cetakan, sama seperti penyimpanan.
     */
    #[Test]
    public function staff_cannot_reach_the_preview(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('setting.perangkat.label.preview'), $this->form())
            ->assertForbidden();
    }

    #[Test]
    public function the_preview_never_writes_a_setting(): void
    {
        $this->preview(['sheet_media_width_mm' => '150', 'sheet_media_height_mm' => '180']);

        $this->assertNull(Setting::get(LabelPrinterSettings::SHEET_MEDIA_WIDTH_MM_KEY));
        $this->assertNull(Setting::get(LabelPrinterSettings::SHEET_MEDIA_HEIGHT_MM_KEY));
        $this->assertSame(LabelPaperMode::Roll, app(LabelPrinterSettings::class)->paperMode());
    }
}
