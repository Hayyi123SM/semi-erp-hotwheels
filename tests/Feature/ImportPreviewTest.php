<?php

namespace Tests\Feature;

use App\Models\Rack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Alur impor punya tiga langkah dan dua di antaranya bisa dilewati salah.
 *
 * Yang diuji di sini bukan "impor jalan" -- itu sudah tercakup di ImportTest --
 * tapi apa yang terjadi di antara langkah-langkah itu: apakah langkah
 * validasi benar-benar tidak menulis apa pun, dan apakah langkah simpan
 * memakai peta kolom yang sama persis dengan yang sudah dilihat orang.
 */
class ImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function uploadRak(User $owner, string $body = "code,zone,type,capacity,is_active\nr-01,A,DISPLAY,120,YA\n"): string
    {
        $upload = $this->actingAs($owner)
            ->post('/master/import/rak/upload', [
                'import_file' => UploadedFile::fake()->createWithContent('rak.csv', $body),
            ])
            ->assertRedirect();

        return parse_url($upload->headers->get('Location'), PHP_URL_PATH);
    }

    private function mapping(): array
    {
        return [
            'header_row' => '0',
            'map' => ['code' => 'A', 'zone' => 'B', 'type' => 'C', 'capacity' => 'D', 'is_active' => 'E'],
        ];
    }

    #[Test]
    public function validating_writes_nothing_to_the_database(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner);

        $this->actingAs($owner)
            ->post($url.'/preview', $this->mapping())
            ->assertOk()
            ->assertSee('Belum ada data yang disimpan')
            ->assertSee('Siap Disimpan');

        $this->assertDatabaseCount('racks', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'IMPORT']);
    }

    #[Test]
    public function committing_saves_the_rows_that_validating_approved(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner);

        $this->actingAs($owner)->post($url.'/preview', $this->mapping())->assertOk();
        $this->actingAs($owner)->post($url.'/commit')->assertOk()->assertSee('Berhasil');

        $this->assertDatabaseCount('racks', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'IMPORT']);
    }

    #[Test]
    public function committing_without_validating_first_is_refused(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner);

        // Commit tidak menerima peta kolom dari formulir, jadi tanpa
        // langkah validasi tidak ada yang bisa disalin dari layar. Menerimanya
        // di sini berarti ada jalan menyimpan data tanpa pernah ditunjukkan
        // ke orang lebih dulu.
        $this->actingAs($owner)
            ->post($url.'/commit')
            ->assertStatus(409);

        $this->assertDatabaseCount('racks', 0);
    }

    #[Test]
    public function committing_uses_the_mapping_from_the_last_validation_not_the_old_one(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak(
            $owner,
            "code,zone,type,capacity,is_active\nr-01,A,DISPLAY,120,YA\nr-02,B,STORAGE,80,TIDAK\n"
        );

        // Validasi pertama salah memetakan zona ke kolom kapasitas.
        $this->actingAs($owner)->post($url.'/preview', [
            'header_row' => '0',
            'map' => ['code' => 'A', 'zone' => 'D', 'type' => 'C', 'capacity' => 'B', 'is_active' => 'E'],
        ])->assertOk();

        // Validasi kedua memperbaikinya.
        $this->actingAs($owner)->post($url.'/preview', $this->mapping())->assertOk();

        $this->actingAs($owner)->post($url.'/commit')->assertOk();

        $this->assertDatabaseHas('racks', ['code' => 'R-01', 'zone' => 'A']);
        $this->assertDatabaseHas('racks', ['code' => 'R-02', 'zone' => 'B']);
    }

    #[Test]
    public function a_usable_row_is_saved_even_when_its_neighbour_is_rejected(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak(
            $owner,
            "code,zone,type,capacity,is_active\nr-01,A,DISPLAY,120,YA\n,DISPLAY,10,YA\n"
        );

        $preview = $this->actingAs($owner)->post($url.'/preview', $this->mapping())->assertOk();
        $preview->assertSee('1 baris tidak lolos');
        $this->assertDatabaseCount('racks', 0);

        $this->actingAs($owner)->post($url.'/commit')->assertOk()->assertSee('Berhasil');

        // Baris kedua tidak punya kode, jadi hanya baris pertama yang masuk.
        $this->assertDatabaseCount('racks', 1);
        $this->assertDatabaseHas('racks', ['code' => 'R-01']);
    }

    #[Test]
    public function going_back_to_the_mapping_page_withdraws_the_approval(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner);

        $this->actingAs($owner)->post($url.'/preview', $this->mapping())->assertOk();

        // Membuka pemetaan berarti mulai mengedit. Layar validasi yang
        // disetujui orang sudah tidak lagi menggambarkan apa yang ada di layar.
        $this->actingAs($owner)->get($url)->assertOk();

        $this->actingAs($owner)->post($url.'/commit')->assertStatus(409);

        $this->assertDatabaseCount('racks', 0);
    }

    #[Test]
    public function remapping_the_columns_withdraws_the_approval_even_without_touching_the_header_row(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner);

        $this->actingAs($owner)->post($url.'/preview', $this->mapping())->assertOk();

        // Baris header sama persis seperti yang disetujui. Yang berubah adalah
        // pemetaan kolom, jadi persetujuan lama tetap harus dibuang.
        $this->actingAs($owner)->get($url.'?header=0')->assertOk();

        $this->actingAs($owner)->post($url.'/commit')->assertStatus(409);

        $this->assertDatabaseCount('racks', 0);
    }

    #[Test]
    public function the_chosen_header_row_survives_going_back_without_the_query_string(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner, "sampul\n\ncode,zone,type,capacity,is_active\nr-01,A,DISPLAY,120,YA\n");

        $this->actingAs($owner)->get($url.'?header=2')->assertOk();

        // Stepper di halaman Validasi menunjuk ke URL tanpa query. Tanpa
        // penyimpanan, baris header yang sudah dipilih akan hilang begitu
        // orang mundur.
        $html = $this->actingAs($owner)->get($url)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="2"\s+selected/', $html);
    }

    #[Test]
    public function a_file_where_everything_fails_cannot_be_committed(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner, "code,zone,type,capacity,is_active\n,DISPLAY,10,YA\n");

        $preview = $this->actingAs($owner)->post($url.'/preview', $this->mapping())->assertOk();

        // Tidak ada tombol simpan sama sekali: menyetujui nol baris tidak
        // punya arti, dan orang hanya akan mengira impor berhasil.
        $preview->assertSee('Tidak Ada yang Bisa Diimpor');
        $preview->assertDontSee('Setujui');

        $this->assertDatabaseCount('racks', 0);
    }

    #[Test]
    public function committing_a_file_where_everything_fails_is_refused_by_the_server_too(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner, "code,zone,type,capacity,is_active\n,DISPLAY,10,YA\n");

        $this->actingAs($owner)->post($url.'/preview', $this->mapping())->assertOk();

        // Tombolnya memang disembunyikan, tapi menyembunyikan tombol bukan
        // jaminan: permintaannya masih bisa dibuat langsung. Tanpa penolakan di
        // sini, impor yang menyimpan nol baris akan melapor "Berhasil 0" --
        // persis hasil yang paling menyesatkan.
        $this->actingAs($owner)->post($url.'/commit')->assertStatus(422);

        $this->assertDatabaseCount('racks', 0);
    }

    #[Test]
    public function refusing_an_empty_import_keeps_the_session_so_the_mapping_can_be_fixed(): void
    {
        // Kode ada di kolom terakhir, jadi pemetaan default -- yang mengira
        // kode itu kolom pertama -- tidak menghasilkan apa pun yang bisa
        // disimpan.
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner, "zone,type,capacity,is_active,code\n,DISPLAY,10,YA,r-01\n");

        $this->actingAs($owner)->post($url.'/preview', $this->mapping())->assertOk();
        $this->actingAs($owner)->post($url.'/commit')->assertStatus(422);

        // Sesi harus masih hidup supaya orang bisa memperbaiki pemetaan kolom
        // dan menjalankan validasi lagi, bukan mengunggah ulang berkasnya.
        $this->actingAs($owner)->get($url)->assertOk();

        $this->actingAs($owner)
            ->post($url.'/preview', [
                'header_row' => '0',
                'map' => ['zone' => 'A', 'type' => 'B', 'capacity' => 'C', 'is_active' => 'D', 'code' => 'E'],
                'defaults' => ['is_active' => 'YA'],
            ])
            ->assertOk()
            ->assertSee('Siap Disimpan');

        $this->actingAs($owner)->post($url.'/commit')->assertOk()->assertSee('Berhasil');
        $this->assertDatabaseCount('racks', 1);
    }

    #[Test]
    public function the_session_is_spent_after_committing_so_it_cannot_be_run_twice(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner);

        $this->actingAs($owner)->post($url.'/preview', $this->mapping())->assertOk();
        $this->actingAs($owner)->post($url.'/commit')->assertOk();

        $this->assertDatabaseCount('racks', 1);

        $this->actingAs($owner)->post($url.'/commit')->assertNotFound();
        $this->assertDatabaseCount('racks', 1);
    }

    #[Test]
    public function cancelling_after_validating_saves_nothing(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner);

        $this->actingAs($owner)->post($url.'/preview', $this->mapping())->assertOk();
        $this->actingAs($owner)->delete($url)->assertRedirect();

        $this->assertDatabaseCount('racks', 0);
        $this->actingAs($owner)->get($url)->assertNotFound();
    }

    #[Test]
    public function staff_cannot_validate_or_commit(): void
    {
        $staff = User::factory()->staff()->create();
        $url = $this->uploadRak(User::factory()->owner()->create());

        $this->actingAs($staff)->post($url.'/preview', $this->mapping())->assertForbidden();
        $this->actingAs($staff)->post($url.'/commit')->assertForbidden();
    }

    #[Test]
    public function jumping_straight_to_commit_explains_which_step_was_skipped(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner);

        // 409, bukan 404 dan bukan 500: token-nya ada dan modulnya cocok,
        // hanya langkah sebelumnya yang belum dijalankan.
        $response = $this->actingAs($owner)->post($url.'/commit')->assertStatus(409);

        $this->assertStringContainsString('validasi', strtolower((string) $response->getContent()));
    }

    #[Test]
    public function the_number_shown_before_approving_is_the_number_that_gets_saved(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak(
            $owner,
            "code,zone,type,capacity,is_active\nr-01,A,DISPLAY,120,YA\nr-02,B,STORAGE,80,TIDAK\nr-03,A,QUARANTINE,5,YA\n"
        );

        $preview = $this->actingAs($owner)->post($url.'/preview', $this->mapping())->assertOk();
        $preview->assertSee('Setuju');

        $this->actingAs($owner)->post($url.'/commit')->assertOk();

        $this->assertSame(3, Rack::count());
    }

    #[Test]
    public function a_second_import_starts_with_an_empty_session(): void
    {
        $owner = User::factory()->owner()->create();

        $first = $this->uploadRak($owner);
        $this->actingAs($owner)->post($first.'/preview', $this->mapping())->assertOk();
        $this->actingAs($owner)->post($first.'/commit')->assertOk();

        $second = $this->uploadRak($owner, "code,zone,type,capacity,is_active\nr-99,C,STORAGE,7,TIDAK\n");
        $this->assertNotSame($first, $second);

        $this->actingAs($owner)->post($second.'/commit')->assertStatus(409);
        $this->assertSame(1, Rack::count());
    }

    #[Test]
    public function the_failed_row_numbers_point_at_the_original_file(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak(
            $owner,
            "code,zone,type,capacity,is_active\nr-01,A,DISPLAY,120,YA\n,DISPLAY,10,YA\nr-03,A,STORAGE,5,YA\n"
        );

        // Baris tanpa kode ada di baris 3 berkas: satu baris judul, lalu
        // r-01 di baris 2, lalu yang kosong di baris 3.
        $this->actingAs($owner)->post($url.'/preview', $this->mapping())->assertOk()->assertSee('#3');

        $this->actingAs($owner)->post($url.'/commit')->assertOk();

        $this->assertDatabaseCount('racks', 2);
    }

    /**
     * Dua halaman baru ini tidak bisa masuk daftar route di `PagesRenderTest`
     * karena keduanya butuh token impor di sesi -- jadi penjaga comment Blade
     * yang bocor harus ada di sini, atau tidak ada sama sekali.
     *
     * Comment yang tidak tertutup lolos ke HTML sebagai teks biasa, dan
     * `assertOk` tetap hijau karena responsnya 200. Yang bocor cuma kelihatan
     * di layar.
     */
    #[Test]
    public function no_import_step_leaks_unstripped_blade_comments(): void
    {
        $owner = User::factory()->owner()->create();
        $url = $this->uploadRak($owner, "code,zone,type,capacity,is_active\nr-01,A,DISPLAY,120,YA\n,DISPLAY,10,YA\n");

        $steps = [
            $this->actingAs($owner)->get($url)->assertOk(),
            $this->actingAs($owner)->post($url.'/preview', $this->mapping())->assertOk(),
            $this->actingAs($owner)->post($url.'/commit')->assertOk(),
        ];

        foreach ($steps as $index => $response) {
            $response->assertDontSee('{{--', false);
            $response->assertDontSee('--}}', false);
        }
    }
}
