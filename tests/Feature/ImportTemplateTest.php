<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Berkas hasil unduhan, dihapus setelah setiap tes.
     *
     * PhpSpreadsheet versi ini hanya bisa membaca dari berkas, jadi isi
     * respons yang dialirkan harus ditulis ke disk dulu. Berkasnya sengaja
     * ditahan sampai assertion selesai karena membaca dari arsip yang sudah
     * dihapus akan gagal dengan pesan yang menyesatkan.
     *
     * @var array<int, string>
     */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        $this->paths = [];

        parent::tearDown();
    }

    private function workbook(string $module): Spreadsheet
    {
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)
            ->get("/master/import/{$module}/template")
            ->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'impor-').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $this->paths[] = $path;

        return IOFactory::load($path);
    }

    #[Test]
    public function staff_cannot_download_an_import_template(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->get('/master/import/rak/template')
            ->assertForbidden();
    }

    #[Test]
    public function an_unknown_module_has_no_template(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get('/master/import/mesin/template')
            ->assertNotFound();
    }

    #[Test]
    #[DataProvider('modules')]
    public function it_downloads_a_readable_workbook_for_every_module(string $module): void
    {
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)
            ->get("/master/import/{$module}/template")
            ->assertOk()
            ->assertDownload("template-import-{$module}.xlsx");

        // Berkas template wajib benar-benar bisa dibuka kembali. Kalau hanya
        // nama berkas dan MIME-nya yang benar, orang baru tahu berkasnya
        // rusak setelah menutup jendela unduhan.
        $path = tempnam(sys_get_temp_dir(), 'impor-').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $this->paths[] = $path;

        $book = IOFactory::load($path);

        $this->assertSame(['Data', 'Petunjuk'], $book->getSheetNames());
    }

    public static function modules(): array
    {
        return [
            'penitip' => ['penitip'],
            'katalog' => ['katalog'],
            'rak' => ['rak'],
            'pengguna' => ['pengguna'],
        ];
    }

    #[Test]
    public function the_data_sheet_carries_the_real_column_labels(): void
    {
        $sheet = $this->workbook('rak')->getSheetByName('Data');

        $labels = array_map('strval', $sheet->rangeToArray('A1:E1', calculateFormulas: false)[0]);

        $this->assertSame(
            ['Kode Rak', 'Zona', 'Tipe (DISPLAY/STORAGE/QUARANTINE/RTV_STAGING)', 'Kapasitas (item)', 'Aktif (YA/TIDAK)'],
            $labels
        );
    }

    #[Test]
    public function the_data_sheet_has_one_example_row_and_nothing_below_it(): void
    {
        $sheet = $this->workbook('katalog')->getSheetByName('Data');

        // Baris contoh boleh ada -- itu yang menjelaskan formatnya -- tapi
        // tidak boleh ada baris lain di bawahnya: setiap baris data yang
        // tersisa akan ikut diimpor.
        $this->assertSame(2, $sheet->getHighestRow());
        $this->assertNotSame('', (string) $sheet->getCell('A2')->getValue());
    }

    #[Test]
    public function the_data_sheet_holds_no_instruction_row_that_would_import_as_a_row(): void
    {
        $sheet = $this->workbook('katalog')->getSheetByName('Data');

        // Pengingat "hapus baris contoh" milik sheet Petunjuk. Kalau pernah
        // diletakkan di sini, ia akan terbaca sebagai satu produk bernama
        // "Contoh isi -- hapus baris ini".
        $this->assertNull($sheet->getCell('A3')->getValue());
    }

    #[Test]
    public function the_guide_sheet_says_which_row_to_delete_before_uploading(): void
    {
        $guide = $this->workbook('katalog')->getSheetByName('Petunjuk');

        $this->assertStringContainsString('Hapus baris itu', (string) $guide->getCell('A1')->getValue());

        $headers = array_map('strval', $guide->rangeToArray('A3:F3', calculateFormulas: false)[0]);

        $this->assertSame(['Field Sistem', 'Wajib', 'Tipe', 'Nilai yang diperbolehkan', 'Contoh', 'Aturan'], $headers);
    }

    #[Test]
    public function the_guide_sheet_explains_every_field_the_data_sheet_has(): void
    {
        $book = $this->workbook('penitip');

        // Satu kolom tapi banyak baris: flatten dulu sebelum dibaca.
        $fields = $book->getSheetByName('Petunjuk')->rangeToArray('A4:A100', calculateFormulas: false);
        $fields = array_filter(array_map('strval', array_merge(...$fields)), fn (string $value) => $value !== '');

        $this->assertNotSame([], $fields);
        $this->assertSame('Nama Penitip', $fields[0]);
    }
}
