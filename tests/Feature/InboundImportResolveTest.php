<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\StockInPribadiDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pencocokan nama produk ke satu baris katalog saat impor Inbound.
 *
 * Nama produk tidak unik di katalog, dan Kode Casting boleh kosong di
 * hampir seluruh baris. Dua keadaan yang harus dijaga: kandidat yang
 * isinya sama persis tidak boleh menjegal impor, dan kandidat yang
 * benar-benar berbeda tetap dipilihkan satu wakil dengan peringatan yang
 * menyebut idnya -- bukan sebuah kegagalan yang menghentikan berkas
 * seluruhnya.
 */
class InboundImportResolveTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Dua baris katalog yang isinya identik adalah satu produk yang pernah
     * tercatat dua kali, bukan dua pilihan. Menganggapnya ambigu berarti
     * menghentikan impor karena kesalahan data yang tidak bisa diperbaiki
     * orang dari layar ini -- jadi dipilihkan satu wakil, dengan peringatan
     * supaya kejadiannya tetap terbaca.
     */
    #[Test]
    public function a_name_pointing_at_identical_twins_picks_one_and_warns(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        $original = Product::factory()->create([
            'series_id' => $series->id,
            'name' => 'Skyline R34',
            'color' => 'Merah',
        ]);
        $twin = $original->replicate();
        $twin->save();

        $url = $this->upload($owner, implode("\n", [
            'kategori,nama produk,warna,hpp,qty',
            'HW,Skyline R34,Merah,275000,2',
        ]));

        $response = $this->preview($owner, $url, [
            'header_row' => '0',
            'map' => [
                'series_name' => 'A',
                'product_id' => 'B',
                'color' => 'C',
                'cost_price' => 'D',
                'qty' => 'E',
            ],
        ]);

        $response->assertOk()
            ->assertSee('baris menunjuk ke lebih dari satu produk katalog')
            ->assertSee('cocok dengan 2 produk yang isinya identik')
            ->assertSee('dipakai id '.$original->id);

        $this->commit($owner, $url);

        $items = StockInPribadiDraft::query()->sole()->items;

        $this->assertSame($original->id, (int) $items[0]['product_id']);
        $this->assertSame(2, (int) $items[0]['qty']);
    }

    /**
     * Warna ikut mempersempit kandidat, bukan sekadar tampil di layar.
     * Tanpa ini, dua produk dengan nama sama tapi warna berbeda selalu
     * dianggap sama-sama cocok, dan kolom Warna yang sudah diisi orang
     * tidak dianggap apa-apa.
     */
    #[Test]
    public function a_color_in_the_file_narrows_candidates_to_the_right_product(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        Product::factory()->create(['series_id' => $series->id, 'name' => 'Skyline R34', 'color' => 'Red']);
        $biru = Product::factory()->create(['series_id' => $series->id, 'name' => 'Skyline R34', 'color' => 'Blue']);

        $url = $this->upload($owner, implode("\n", [
            'kategori,nama produk,warna,hpp,qty',
            'HW,Skyline R34,Blue,275000,2',
        ]));

        $response = $this->preview($owner, $url, [
            'header_row' => '0',
            'map' => [
                'series_name' => 'A',
                'product_id' => 'B',
                'color' => 'C',
                'cost_price' => 'D',
                'qty' => 'E',
            ],
        ]);

        $response->assertOk()
            ->assertDontSee('cocok dengan')
            ->assertDontSee('tidak ditemukan');

        $this->commit($owner, $url);

        $items = StockInPribadiDraft::query()->sole()->items;

        $this->assertSame($biru->id, (int) $items[0]['product_id']);
    }

    /**
     * Kandidat yang benar-benar berbeda tidak menjegal impor: satu wakil
     * dipilihkan (id terkecil) dengan peringatan yang menyebut warna
     * pemisah dan id yang dipakai, supaya orang bisa memeriksa katalog
     * setelahnya. Yang tetap gagal hanyalah nama yang tidak ditemukan
     * sama sekali.
     */
    #[Test]
    public function genuinely_different_candidates_are_picked_with_a_warning_instead_of_blocking(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        $merah = Product::factory()->create(['series_id' => $series->id, 'name' => 'Skyline R34', 'color' => 'Red']);
        Product::factory()->create(['series_id' => $series->id, 'name' => 'Skyline R34', 'color' => 'Blue']);

        $url = $this->upload($owner, implode("\n", [
            'kategori,nama produk,hpp,qty',
            'HW,Skyline R34,275000,1',
        ]));

        $response = $this->preview($owner, $url, [
            'header_row' => '0',
            'map' => [
                'series_name' => 'A',
                'product_id' => 'B',
                'cost_price' => 'C',
                'qty' => 'D',
            ],
        ]);

        $response->assertOk()
            ->assertSee('cocok dengan 2 produk berbeda')
            ->assertSee('Warna: Red, Blue')
            ->assertSee('dipakai id '.$merah->id)
            ->assertDontSee('tidak ditemukan');

        $this->commit($owner, $url);

        $items = StockInPribadiDraft::query()->sole()->items;

        $this->assertSame($merah->id, (int) $items[0]['product_id']);
        $this->assertSame(1, (int) $items[0]['qty']);
    }

    private function upload(User $owner, string $content): string
    {
        $response = $this->actingAs($owner)
            ->post('/master/import/stock_in_pribadi/upload', [
                'import_file' => UploadedFile::fake()->createWithContent('import.csv', $content."\n"),
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
