<?php

namespace Tests\Feature;

use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Auto-create Penitip saat impor Consignment In.
 *
 * Kolom "Kode Penitip" pada berkas berisi nama penitip yang dicocokkan ke
 * katalog; nama yang belum pernah ada akan menggelar barisnya gagal. Checkbox
 * "Buat Penitip baru otomatis" membuat nama itu menjadi penitip sungguhan,
 * dengan kode berurut dari sistem dan skema bawaan yang wajib dilengkapi
 * belakangan. Hanya Owner yang boleh memutuskan ini.
 */
class ConsignmentImportConsignorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Nama penitip yang belum terdaftar dibuat saat validasi, sehingga
     * barisnya lolos pada pratinjau -- bukan tampak gagal padahal datanya
     * benar. Kode penitipnya berurut dari sistem, dan catatan penandanya
     * terpasang supaya Owner tahu skemanya belum diisi.
     */
    #[Test]
    public function owner_can_auto_create_a_consignor_while_importing_consignment_in(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        Product::factory()->create(['series_id' => $series->id, 'name' => 'Skyline R34', 'casting_code' => 'BAS12345']);

        $url = $this->upload($owner, "kode penitip,nama produk,harga jual,qty,tgl masuk\nBudi Santoso,Skyline R34,500000,2,2026-10-01\n");

        $this->preview($owner, $url, [
            'header_row' => '0',
            'map' => [
                'consignor_id' => 'A',
                'product_id' => 'B',
                'list_price' => 'C',
                'qty' => 'D',
                'consignment_date' => 'E',
            ],
            'options' => ['auto_create_consignor' => 'YA'],
        ])->assertOk()->assertDontSee('tidak ditemukan');

        $consignor = Consignor::sole();

        $this->assertSame('Budi Santoso', $consignor->name);
        $this->assertMatchesRegularExpression('/^CN\d+$/', $consignor->consignor_code);
        $this->assertStringContainsString('Dibuat otomatis', (string) $consignor->notes);

        $this->commit($owner, $url);

        $this->assertSame($consignor->id, Consignment::sole()->consignor_id);
    }

    /**
     * Satu nama yang sama di beberapa baris hanya menghasilkan satu penitip.
     * Tanpa ini satu orang mendatangkan barangnya berkali-kali dan berubah
     * menjadi beberapa penitip dengan saldo terpisah.
     */
    #[Test]
    public function the_same_new_name_on_two_rows_creates_only_one_consignor(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        Product::factory()->create(['series_id' => $series->id, 'name' => 'Skyline R34', 'casting_code' => 'BAS12345']);
        Product::factory()->create(['series_id' => $series->id, 'name' => 'AE86 Trueno', 'casting_code' => 'BAS99999']);

        $url = $this->upload($owner, implode("\n", [
            'kode penitip,nama produk,kode casting,qty,tgl masuk',
            'Budi Santoso,Skyline R34,BAS12345,1,2026-10-01',
            'Budi Santoso,AE86 Trueno,BAS99999,1,2026-10-01',
        ]));

        $this->preview($owner, $url, [
            'header_row' => '0',
            'map' => [
                'consignor_id' => 'A',
                'product_id' => 'B',
                'casting_code' => 'C',
                'qty' => 'D',
                'consignment_date' => 'E',
            ],
            'options' => ['auto_create_consignor' => 'YA'],
        ])->assertOk();

        $this->assertSame(1, Consignor::count());
    }

    /**
     * Tanpa centang, nama penitip yang tidak dikenal tetap menggagalkan
     * barisnya. Pembuatan otomatis harus tetap keputusan sadar, bukan bawaan.
     */
    #[Test]
    public function an_unknown_consignor_is_rejected_when_auto_create_is_not_checked(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        Product::factory()->create(['series_id' => $series->id, 'name' => 'Skyline R34', 'casting_code' => 'BAS12345']);

        $url = $this->upload($owner, "kode penitip,nama produk,qty\nBudi Santoso,Skyline R34,1\n");

        $this->preview($owner, $url, [
            'header_row' => '0',
            'map' => [
                'consignor_id' => 'A',
                'product_id' => 'B',
                'qty' => 'C',
            ],
        ])->assertOk()->assertSee('tidak ditemukan');

        $this->assertSame(0, Consignor::count());
    }

    /**
     * Staff tidak boleh membuat penitip lewat impor. Seluruh alur impor
     * Inbound memang tertutup untuk Staff, jadi yang dipin di sini adalah
     * jawaban route-nya: 403, bukan pembuatan diam-diam.
     */
    #[Test]
    public function a_staff_cannot_auto_create_a_consignor(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->post('/master/import/consignment_in/upload', [
                'import_file' => UploadedFile::fake()->createWithContent(
                    'consignment.csv',
                    "kode penitip,nama produk,qty\nBudi,Skyline R34,1\n",
                ),
            ])
            ->assertForbidden();

        $this->assertSame(0, Consignor::count());
    }

    /**
     * Nilai yang berbentuk kode penitip (CNxx) adalah rujukan, bukan nama.
     * Menolak membuatnya menjaga salah ketik tetap terbaca sebagai kesalahan,
     * bukan diam-diam menjadi penitip bernama "CN99".
     */
    #[Test]
    public function a_value_that_looks_like_a_missing_code_is_never_created(): void
    {
        $owner = User::factory()->owner()->create();
        Consignor::factory()->create(['consignor_code' => 'CN01']);
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        Product::factory()->create(['series_id' => $series->id, 'name' => 'Skyline R34', 'casting_code' => 'BAS12345']);

        $url = $this->upload($owner, "kode penitip,nama produk,qty\nCN99,Skyline R34,1\n");

        $this->preview($owner, $url, [
            'header_row' => '0',
            'map' => [
                'consignor_id' => 'A',
                'product_id' => 'B',
                'qty' => 'C',
            ],
            'options' => ['auto_create_consignor' => 'YA'],
        ])->assertOk()->assertSee('tidak ditemukan');

        $this->assertSame(1, Consignor::count());
        $this->assertFalse(Consignor::where('name', 'CN99')->exists());
    }

    private function upload(User $owner, string $content): string
    {
        $response = $this->actingAs($owner)
            ->post('/master/import/consignment_in/upload', [
                'import_file' => UploadedFile::fake()->createWithContent('consignment.csv', $content."\n"),
            ])
            ->assertRedirect();

        return parse_url($response->headers->get('Location'), PHP_URL_PATH);
    }

    private function preview(User $owner, string $mappingUrl, array $mapping): TestResponse
    {
        return $this->actingAs($owner)->post($mappingUrl.'/preview', $mapping)->assertOk();
    }

    private function commit(User $owner, string $mappingUrl): TestResponse
    {
        return $this->actingAs($owner)->post($mappingUrl.'/commit')->assertRedirect();
    }
}
