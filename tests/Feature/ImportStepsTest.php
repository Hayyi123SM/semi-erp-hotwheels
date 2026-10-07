<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ImportSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penanda posisi dan pemilih baris header.
 *
 * Keduanya adalah hal yang paling mudah dibuat membingungkan tanpa terlihat
 * rusak: stepper dengan satu tahap yang salah hanya akan membingungkan orang
 * yang salah membaca dokumen, dan selector header yang diam saja hanya akan
 * membuang satu langkah. Jadi yang diperiksa di sini adalah isi
 * HTML-nya, bukan hanya `assertOk`.
 */
class ImportStepsTest extends TestCase
{
    use RefreshDatabase;

    private function uploadRak(User $owner, string $body): string
    {
        $upload = $this->actingAs($owner)
            ->post('/master/import/rak/upload', [
                'import_file' => UploadedFile::fake()->createWithContent('rak.csv', $body),
            ])
            ->assertRedirect();

        return parse_url($upload->headers->get('Location'), PHP_URL_PATH);
    }

    /**
     * @return array<int, array{0: string}>
     */
    private function stepLabels(string $html): array
    {
        preg_match_all('/Tahap alur.*?<\/nav>/s', $html, $nav);

        preg_match_all('/>\s*(Unggah Berkas|Petakan Kolom|Validasi|Selesai)\s*</', $nav[0][0] ?? '', $found);

        return $found[1];
    }

    /**
     * @return array<int, array{0: string, 1: int}>
     */
    public static function importSteps(): array
    {
        return [
            'Unggah' => ['upload', 1],
            'Petakan' => ['mapping', 2],
            'Validasi' => ['validate', 3],
            'Selesai' => ['result', 4],
        ];
    }

    #[Test]
    #[DataProvider('importSteps')]
    public function every_step_of_the_import_flow_is_marked(string $step, int $position): void
    {
        $this->assertSame($position, ImportSteps::number($step));
    }

    #[Test]
    public function the_four_steps_are_always_the_same_four(): void
    {
        $labels = array_column(ImportSteps::for(), 'label');

        // Kalau daftar ini berubah, stepper di empat halaman ikut berubah
        // bersamanya -- itu sebabnya dia didefinisikan sekali.
        $this->assertSame(['Unggah Berkas', 'Petakan Kolom', 'Validasi', 'Selesai'], $labels);
    }

    #[Test]
    public function only_reachable_steps_get_a_link(): void
    {
        $steps = ImportSteps::for('/master/import/rak/abc', '/master/lokasi-rak');

        $this->assertSame('/master/lokasi-rak', $steps[0]['href']);
        $this->assertSame('/master/import/rak/abc', $steps[1]['href']);

        // Tanpa token, memuat ulang validasi berarti menjalankan validasi
        // ulang tanpa peta kolom yang disetujui.
        $this->assertNull($steps[2]['href']);
        $this->assertNull($steps[3]['href']);
    }

    #[Test]
    public function the_result_page_links_nowhere_back_into_the_finished_import(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner, "code,zone,type,capacity,is_active\nr-01,A,DISPLAY,120,YA\n");

        $this->actingAs($owner)->post($url.'/preview', [
            'header_row' => '0',
            'map' => ['code' => 'A', 'zone' => 'B', 'type' => 'C', 'capacity' => 'D', 'is_active' => 'E'],
        ])->assertOk();

        $result = $this->actingAs($owner)->post($url.'/commit')->assertOk();

        // Sesi sudah dibuang, jadi setiap tautan di stepper harus leading ke
        // daftar modul -- bukan ke pemetaan yang pasti 404.
        $steps = ImportSteps::for(null, '/master/lokasi-rak');

        foreach ($steps as $index => $step) {
            if ($step['href'] === null) {
                continue;
            }

            // `url()` karena yang ditulis di HTML adalah URL penuh, sementara
            // `ImportSteps` menyimpan path-nya.
            $this->assertStringContainsString('href="'.url($step['href']).'"', $result->getContent());
            $this->actingAs($owner)->get($step['href'])->assertOk();
        }

        $this->assertSame(['Unggah Berkas', 'Petakan Kolom', 'Validasi', 'Selesai'], $this->stepLabels($result->getContent()));
    }

    #[Test]
    public function the_mapping_page_marks_the_second_step_and_links_back_to_the_list(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner, "code,zone,type,capacity,is_active\nr-01,A,DISPLAY,120,YA\n");

        $response = $this->actingAs($owner)->get($url)->assertOk();
        $html = $response->getContent();

        $this->assertSame(['Unggah Berkas', 'Petakan Kolom', 'Validasi', 'Selesai'], $this->stepLabels($html));
        $this->assertStringContainsString('aria-current="step"', $html);
        $this->assertStringContainsString('/master/lokasi-rak', $html);
    }

    #[Test]
    public function the_upload_form_shows_the_first_step_before_any_token_exists(): void
    {
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)->get('/master/lokasi-rak/create')->assertOk();
        $html = $response->getContent();

        $this->assertSame(['Unggah Berkas', 'Petakan Kolom', 'Validasi', 'Selesai'], $this->stepLabels($html));

        // Hanya tahap 1 yang boleh punya tautan: tiga tahap berikutnya butuh
        // token, dan tanpa token tautannya hanya tidak berbentuk soal.
        $nav = '';
        preg_match('/Tahap alur.*?<\/nav>/s', $html, $nav);
        $this->assertStringNotContainsString('/master/import/rak/', $nav[0] ?? '');
    }

    #[Test]
    public function completed_steps_are_marked_with_a_check_instead_of_a_number(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner, "code,zone,type,capacity,is_active\nr-01,A,DISPLAY,120,YA\n");

        $this->actingAs($owner)->post($url.'/preview', [
            'header_row' => '0',
            'map' => ['code' => 'A', 'zone' => 'B', 'type' => 'C', 'capacity' => 'D', 'is_active' => 'E'],
        ])->assertOk();

        $html = $this->actingAs($owner)->post($url.'/commit')->assertOk()->getContent();

        preg_match('/Tahap alur.*?<\/nav>/s', $html, $nav);
        $nav = $nav[0] ?? '';

        // Tiga langkah sudah lewat: masing-masing centang, bukan angka. Kalau
        // hanya angka, yang tampil indistinguishable dari langkah yang belum
        // dicapai.
        $this->assertSame(3, substr_count($nav, 'M5 13l4 4L19 7'));

        // Hanya satu `aria-current`: yang sedang berjalan. Kalau dua, pembaca
        // layar mengumumkan dua tahap sekaligus.
        $this->assertSame(1, substr_count($nav, 'aria-current="step"'));
    }

    #[Test]
    public function the_header_selector_works_without_javascript(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner, "code,zone,type,capacity,is_active\nr-01,A,DISPLAY,120,YA\n");

        $html = $this->actingAs($owner)->get($url)->assertOk()->getContent();

        // Sebelumnya, pemilihan baris header bergantung pada `@change`, jadi tanpa
        // JavaScript dropdown-nya diam saja dan tidak ada cara mengganti baris.
        $this->assertStringContainsString('name="header"', $html);
        $this->assertStringContainsString('Terapkan Baris Header', $html);
        $this->assertStringNotContainsString('window.location.href', $html);
    }

    #[Test]
    public function choosing_a_different_header_row_reloads_with_that_row(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner, "sampul\n\ncode,zone,type,capacity,is_active\nr-01,A,DISPLAY,120,YA\n");

        $default = $this->actingAs($owner)->get($url)->assertOk();
        $this->assertStringContainsString('>Baris 3<', $default->getContent());

        $moved = $this->actingAs($owner)->get($url.'?header=0')->assertOk()->getContent();

        // Baris 1 sekarang yang terpilih, bukan lagi baris 3.
        $this->assertMatchesRegularExpression('/<option value="0"\s+selected/', $moved);
    }

    #[Test]
    public function a_header_row_far_down_the_file_is_still_selectable(): void
    {
        $owner = User::factory()->owner()->create();

        // 120 baris Sebelum header. Selector lama hanya menawarkan 50 baris
        // pertama, jadi baris ini tidak akan pernah muncul di dropdown.
        $body = str_repeat("catatan,,-,-\n", 120)."code,zone,type,capacity,is_active\nr-01,A,DISPLAY,120,YA\n";
        $url = $this->uploadRak($owner, $body);

        $this->actingAs($owner)->get($url)->assertOk()->assertSee('Baris 121', false);
    }

    #[Test]
    public function the_header_selector_is_not_nested_inside_the_mapping_form(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner, "code,zone,type,capacity,is_active\nr-01,A,DISPLAY,120,YA\n");

        $html = $this->actingAs($owner)->get($url)->assertOk()->getContent();

        // Dua form yang saling membungkus menghasilkan HTML yang diparse secara
        // berbeda oleh peramban, dan form-nya biasanya hilang.
        $this->assertSame(
            substr_count($html, '<form'),
            substr_count($html, '</form>'),
            'jumlah <form> dan </form> harus sama'
        );
    }

    #[Test]
    public function the_picked_header_row_reaches_validation(): void
    {
        $owner = User::factory()->owner()->create();
        $body = "sampul\n\ncode,zone,type,capacity,is_active\nr-01,A,DISPLAY,120,YA\n";
        $url = $this->uploadRak($owner, $body);

        $this->actingAs($owner)->get($url.'?header=2')->assertOk();

        $preview = $this->actingAs($owner)->post($url.'/preview', [
            'header_row' => '2',
            'map' => ['code' => 'A', 'zone' => 'B', 'type' => 'C', 'capacity' => 'D', 'is_active' => 'E'],
        ])->assertOk();

        $preview->assertSee('Siap Disimpan');
        $this->assertDatabaseCount('racks', 0);
    }
}
