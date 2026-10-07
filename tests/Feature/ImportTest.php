<?php

namespace Tests\Feature;

use App\Models\Consignor;
use App\Models\Product;
use App\Models\Rack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function staff_is_blocked_from_all_import_flows(): void
    {
        $staff = User::factory()->staff()->create();
        $file = UploadedFile::fake()->createWithContent('rak.csv', "code\nA-1");

        $this->actingAs($staff)
            ->post('/master/import/rak/upload', ['import_file' => $file])
            ->assertForbidden();
    }

    #[Test]
    public function upload_rejects_invalid_extension(): void
    {
        $owner = User::factory()->owner()->create();
        $file = UploadedFile::fake()->createWithContent('catatan.txt', 'teks biasa');

        $this->actingAs($owner)
            ->from('/master/lokasi-rak/create')
            ->post('/master/import/rak/upload', ['import_file' => $file])
            ->assertSessionHasErrors('import_file');
    }

    #[Test]
    public function unknown_import_module_returns_404(): void
    {
        $owner = User::factory()->owner()->create();
        $file = UploadedFile::fake()->createWithContent('whatever.csv', "a\n1");

        $this->actingAs($owner)
            ->post('/master/import/mesin/upload', ['import_file' => $file])
            ->assertNotFound();
    }

    #[Test]
    public function orphan_import_token_returns_404(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get('/master/import/rak/nonexistent-token')
            ->assertNotFound();

        $this->actingAs($owner)
            ->post('/master/import/rak/nonexistent-token/preview', [
                'header_row' => '0',
                'map' => ['code' => 'A'],
            ])
            ->assertNotFound();

        $this->actingAs($owner)
            ->post('/master/import/rak/nonexistent-token/commit')
            ->assertNotFound();
    }

    #[Test]
    public function owner_can_upload_and_process_rak_csv(): void
    {
        $owner = User::factory()->owner()->create();
        $file = UploadedFile::fake()->createWithContent(
            'rak.csv',
            "code,zone,type,capacity,is_active\nr-01,A,DISPLAY,120,YA\n",
        );

        $upload = $this->actingAs($owner)
            ->post('/master/import/rak/upload', ['import_file' => $file])
            ->assertRedirect();

        $this->actingAs($owner)->get($this->urlFromResponse($upload))->assertOk();

        $this->previewThenCommit($owner, $this->urlFromResponse($upload), [
            'header_row' => '0',
            'map' => [
                'code' => 'A',
                'zone' => 'B',
                'type' => 'C',
                'capacity' => 'D',
                'is_active' => 'E',
            ],
        ])->assertOk()->assertSee('Berhasil');

        $rack = Rack::where('code', 'R-01')->firstOrFail();
        $this->assertSame('A', $rack->zone);
        $this->assertSame('DISPLAY', $rack->type->value);
        $this->assertSame(120, $rack->capacity);
        $this->assertTrue($rack->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'IMPORT']);
    }

    #[Test]
    public function import_reports_rows_that_fail_validation(): void
    {
        $owner = User::factory()->owner()->create();
        $file = UploadedFile::fake()->createWithContent(
            'katalog.csv',
            "nama,harga\nToyota AE86,75000\nHonda Civic,\n",
        );

        $upload = $this->actingAs($owner)
            ->post('/master/import/katalog/upload', ['import_file' => $file])
            ->assertRedirect();

        $this->previewThenCommit($owner, $this->urlFromResponse($upload), [
            'header_row' => '0',
            'map' => [
                'name:p1' => 'A',
                'default_list_price' => 'B',
            ],
        ])
            ->assertOk()
            ->assertSee('Gagal');

        $this->assertDatabaseHas('products', ['name' => 'Toyota AE86']);
        $this->assertDatabaseMissing('products', ['name' => 'Honda Civic']);
    }

    #[Test]
    public function owner_can_import_penitip_with_scheme_and_bank_data(): void
    {
        $owner = User::factory()->owner()->create();
        $file = UploadedFile::fake()->createWithContent(
            'penitip.csv',
            "nama,wa,scheme_rate,bank,min_payout\nSiti Nurbaya,0899112233,25,BNI,100000\n",
        );

        $upload = $this->actingAs($owner)
            ->post('/master/import/penitip/upload', ['import_file' => $file])
            ->assertRedirect();

        $this->previewThenCommit($owner, $this->urlFromResponse($upload), [
            'header_row' => '0',
            'map' => [
                'name' => 'A',
                'wa_number' => 'B',
                'scheme_rate' => 'C',
                'bank_name' => 'D',
                'min_payout' => 'E',
            ],
            'defaults' => ['scheme_type' => 'PERCENTAGE'],
        ])->assertOk();

        $consignor = Consignor::where('name', 'Siti Nurbaya')->firstOrFail();
        $this->assertSame('CN01', $consignor->consignor_code);
        $this->assertSame(25.0, (float) $consignor->scheme_rate);
        $this->assertSame('BNI', $consignor->bank_name);
        $this->assertSame(100000, $consignor->min_payout);
    }

    /**
     * A spreadsheet is the one place a grouped figure genuinely arrives, and the
     * one place the form mask cannot help.
     *
     * A cell a person typed with thousands separators comes back as a string --
     * `1.750.000` is not a number any parser will accept, so it is left alone --
     * and read as a decimal that is stored as one and a half. Nothing afterwards
     * can tell: the value in the database is a valid number, of the wrong size,
     * by a factor of a million.
     *
     * This is the same reduction the forms do, on the same `Numbers` helper, and
     * it is pinned here because `typedValue()` is the only place the import path
     * reads a figure at all.
     */
    #[Test]
    public function a_grouped_price_in_a_spreadsheet_is_read_as_a_whole_amount(): void
    {
        $owner = User::factory()->owner()->create();
        $file = UploadedFile::fake()->createWithContent(
            'katalog.csv',
            "nama,harga\nHot Wheels Redline,1.750.000\n",
        );

        $upload = $this->actingAs($owner)
            ->post('/master/import/katalog/upload', ['import_file' => $file])
            ->assertRedirect();

        $this->previewThenCommit($owner, $this->urlFromResponse($upload), [
            'header_row' => '0',
            'map' => ['name:p1' => 'A', 'default_list_price' => 'B'],
        ])->assertOk();

        $this->assertSame(1750000, (int) Product::where('name', 'Hot Wheels Redline')->firstOrFail()->default_list_price);
    }

    /**
     * The same figure with its currency attached, which is what a column
     * formatted as accounting in Excel hands back.
     */
    #[Test]
    public function a_currency_prefixed_price_in_a_spreadsheet_is_read_as_a_whole_amount(): void
    {
        $owner = User::factory()->owner()->create();
        $file = UploadedFile::fake()->createWithContent(
            'katalog.csv',
            "nama,harga\nHot Wheels Blue Streak,Rp2.500.000\n",
        );

        $upload = $this->actingAs($owner)
            ->post('/master/import/katalog/upload', ['import_file' => $file])
            ->assertRedirect();

        $this->previewThenCommit($owner, $this->urlFromResponse($upload), [
            'header_row' => '0',
            'map' => ['name:p1' => 'A', 'default_list_price' => 'B'],
        ])->assertOk();

        $this->assertSame(2500000, (int) Product::where('name', 'Hot Wheels Blue Streak')->firstOrFail()->default_list_price);
    }

    /**
     * A share with a decimal in it, which has to survive as a share.
     *
     * Pinned because the column is `decimal(5,2)` and the alternative is a
     * consignor on a whole-number share: `12.5` rounded to `13` is a quarter of
     * somebody's money, arrived at without an error anywhere.
     */
    #[Test]
    public function a_share_with_a_decimal_in_a_spreadsheet_keeps_its_decimal(): void
    {
        $owner = User::factory()->owner()->create();
        $file = UploadedFile::fake()->createWithContent(
            'penitip.csv',
            "nama,persen\nSiti Nurbaya,12.5\n",
        );

        $upload = $this->actingAs($owner)
            ->post('/master/import/penitip/upload', ['import_file' => $file])
            ->assertRedirect();

        $this->previewThenCommit($owner, $this->urlFromResponse($upload), [
            'header_row' => '0',
            'map' => ['name' => 'A', 'scheme_rate' => 'B'],
            'defaults' => ['scheme_type' => 'PERCENTAGE'],
        ])->assertOk();

        $this->assertSame(12.5, (float) Consignor::where('name', 'Siti Nurbaya')->firstOrFail()->scheme_rate);
    }

    #[Test]
    public function import_cancel_clears_session(): void
    {
        $owner = User::factory()->owner()->create();
        $file = UploadedFile::fake()->createWithContent('rak.csv', "code\nZ-9\n");

        $upload = $this->actingAs($owner)
            ->post('/master/import/rak/upload', ['import_file' => $file])
            ->assertRedirect();

        $url = $this->urlFromResponse($upload);

        $this->actingAs($owner)->delete($url)->assertRedirect();
        $this->actingAs($owner)->get($url)->assertNotFound();

        $this->assertDatabaseCount('racks', 0);
    }

    #[Test]
    public function import_module_mismatch_returns_404(): void
    {
        $owner = User::factory()->owner()->create();
        $file = UploadedFile::fake()->createWithContent('penitip.csv', "nama\nOrang\n");

        $upload = $this->actingAs($owner)
            ->post('/master/import/penitip/upload', ['import_file' => $file])
            ->assertRedirect();

        $url = $this->urlFromResponse($upload);
        $rakUrl = preg_replace('#/penitip/#', '/rak/', $url);

        $this->actingAs($owner)->get($rakUrl)->assertNotFound();
    }

    /**
     * Ambil URL redirect (route mapping) lalu transform jadi path lokal.
     */
    private function urlFromResponse(TestResponse $response): string
    {
        return parse_url($response->headers->get('Location'), PHP_URL_PATH);
    }

    /**
     * Jalankan dua langkah yang tidak bisa dilewati: validasi dulu, lalu simpan.
     *
     * Langkah kedua sengaja tidak mengirim mapping lagi -- ia memakai mapping
     * yang disimpan langkah validasi. Kalau helper ini mengirim mapping dua
     * kali, testnya tidak akan menguji bagian yang paling mudah rusak di alur
     * ini: bahwa yang tersimpan benar-benar yang sudah ditampilkan dan
     * disetujui orang.
     */
    private function previewThenCommit(User $owner, string $mappingUrl, array $mapping): TestResponse
    {
        $this->actingAs($owner)
            ->post($mappingUrl.'/preview', $mapping)
            ->assertOk()
            ->assertSee('Belum ada data yang disimpan');

        return $this->actingAs($owner)->post($mappingUrl.'/commit');
    }
}
