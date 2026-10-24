<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Spreadsheet\SpreadsheetService;
use App\Support\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Batas baris per unggahan dulu berlaku tanpa suara: berkas yang lebih panjang
 * masuk sebagian, sisanya hilang, dan tidak ada pesan apa pun.
 *
 * Yang diuji di sini adalah batasnya terlihat -- ditolak, lengkap dengan jumlah
 * barisnya, sebelum sesi impor dimulai -- dan berkas yang pas di batas tetap
 * diterima seperti biasa.
 */
class ImportRowLimitTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_file_past_the_row_limit_is_refused_with_the_row_count(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->from(route('master.lokasi-rak.create'))
            ->post('/master/import/rak/upload', [
                'import_file' => UploadedFile::fake()->createWithContent(
                    'rak.csv',
                    $this->csvBody(SpreadsheetService::MAX_ROWS),
                ),
            ])
            ->assertRedirect(route('master.lokasi-rak.create'))
            ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'error'
                && str_contains($toast['message'], 'melebihi batas')
                && str_contains($toast['message'], Format::number(SpreadsheetService::MAX_ROWS + 1)));

        // Tidak ada baris yang tersimpan, dan tidak ada sesi impor yang sempat
        // dimulai -- kalau ada, halaman pemetaan yang akan dituju.
        $this->assertDatabaseCount('racks', 0);
    }

    #[Test]
    public function a_file_that_fits_the_row_limit_exactly_is_accepted(): void
    {
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)
            ->post('/master/import/rak/upload', [
                'import_file' => UploadedFile::fake()->createWithContent(
                    'rak.csv',
                    $this->csvBody(SpreadsheetService::MAX_ROWS - 1),
                ),
            ])
            ->assertRedirect()
            ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'info'
                && ! str_contains($toast['message'], 'melebihi batas'));

        // Sesi imbornya hidup: langkah pemetaan bisa dibuka seperti biasa.
        $this->actingAs($owner)
            ->get($response->headers->get('Location'))
            ->assertOk();
    }

    /**
     * Berkas CSV dengan satu baris judul ditambah sejumlah baris data.
     *
     * @param  int  $dataRows  Jumlah baris data, belum termasuk judul.
     */
    private function csvBody(int $dataRows): string
    {
        return implode("\n", array_merge(
            ['code,zone,type,capacity,is_active'],
            array_map(
                static fn (int $index): string => sprintf('r-%04d,A,DISPLAY,10,YA', $index),
                range(1, $dataRows),
            ),
        ))."\n";
    }
}
