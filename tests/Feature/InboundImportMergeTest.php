<?php

namespace Tests\Feature;

use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\LabelPrintJob;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\Rack;
use App\Models\StockInPribadiDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penggabungan baris lanjutan pada berkas Inbound.
 *
 * Blok identitas produk yang di-merge di Excel menyisakan sel kosong pada
 * baris-baris lanjutannya. Pengguna memaknainya sebagai "satu produk,
 * banyak baris satuan" -- jadi qty-nya harus ditotal jadi satu penyimpanan,
 * sementara satuannya tetap dihitung jumlahnya. Test di sini menjaga
 * pengertian itu supaya tidak bergeser diam-diam jadi baris terpisah.
 */
class InboundImportMergeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tujuh baris asli (satu induk + empat lanjutan) harus menjadi SATU baris
     * draft berisi qty 5, lengkap dengan HPP dan rak yang diwarisi dari
     * induknya. Kalau ini berubah jadi lima baris, form Stock In Pribadi akan
     * menampilkan lima entri padahal cuma ada satu blok produk.
     */
    #[Test]
    public function a_merged_identity_block_becomes_a_single_draft_line_with_total_qty(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        $product = Product::factory()->create([
            'series_id' => $series->id,
            'name' => 'Skyline R34',
            'casting_code' => 'BAS12345',
        ]);
        $rack = Rack::factory()->create(['code' => 'A-01-01']);

        $url = $this->upload($owner, 'stock_in_pribadi', implode("\n", [
            'kategori,nama produk,kode casting,warna,hpp,qty,rak',
            'HW,Skyline R34,BAS12345,Merah,275000,1,A-01-01',
            ',,,,,1,',
            ',,,,,1,',
            ',,,,,1,',
            ',,,,,1,',
        ]));

        $response = $this->preview($owner, $url, [
            'header_row' => '0',
            'map' => [
                'series_name' => 'A',
                'product_id' => 'B',
                'casting_code' => 'C',
                'color' => 'D',
                'cost_price' => 'E',
                'qty' => 'F',
                'rack_id' => 'G',
            ],
        ]);

        $response->assertOk()
            ->assertSee('Baris Digabung')
            ->assertSee('#2–#6')
            ->assertSee('Skyline R34');

        // Total Baris mengikuti berkas asli (5 baris data); hanya jumlah
        // penyimpanan yang menyusut jadi satu.
        $this->assertMatchesRegularExpression(
            '/Total Baris<\/p>.*?<p[^>]*>5<\/p>/s',
            $response->getContent(),
        );

        $this->commit($owner, $url);

        $items = StockInPribadiDraft::query()->sole()->items;

        $this->assertCount(1, $items);
        $this->assertSame($product->id, (int) $items[0]['product_id']);
        $this->assertSame(275000, (int) $items[0]['cost_price']);
        $this->assertSame(5, (int) $items[0]['qty']);
        $this->assertSame($rack->id, (int) $items[0]['rack_id']);
    }

    /**
     * Arah yang sama di Consignment In, tapi berakhir sebagai satu lot:
     * satu baris InboundLine berisi qty 5, bukan lima lot kecil yang membuat
     * label dan mutasinya hitungan lima kali lipat.
     */
    #[Test]
    public function a_merged_consignment_block_creates_one_lot_with_total_qty(): void
    {
        $owner = User::factory()->owner()->create();
        Consignor::factory()->create(['consignor_code' => 'CN01']);
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        Product::factory()->create(['series_id' => $series->id, 'name' => 'Skyline R34', 'casting_code' => 'BAS12345']);

        $url = $this->upload($owner, 'consignment_in', implode("\n", [
            'kode penitip,nama produk,harga jual,qty,tgl masuk',
            'CN01,Skyline R34,500000,1,2026-10-01',
            ',,,1,',
            ',,,1,',
            ',,,1,',
            ',,,1,',
        ]));

        $this->preview($owner, $url, [
            'header_row' => '0',
            'map' => [
                'consignor_id' => 'A',
                'product_id' => 'B',
                'list_price' => 'C',
                'qty' => 'D',
                'consignment_date' => 'E',
            ],
        ]);

        $this->commit($owner, $url);

        $consignment = Consignment::sole();

        $this->assertSame(5, (int) $consignment->qty_received);
        $this->assertSame(1, $consignment->stockLots()->count());
        $this->assertSame(5, (int) $consignment->stockLots()->sole()->qty_on_hand);
        $this->assertSame(1, LabelPrintJob::query()->count());
    }

    /**
     * Baris pertama tidak punya induk untuk mewarisi, jadi tidak bisa ditebak
     * produk apa yang dia maksud. Menjadikannya baris lanjutan berarti
     * diam-diam menempelkan baris itu ke produk apapun di atasnya.
     */
    #[Test]
    public function the_first_row_may_not_have_a_blank_identity(): void
    {
        $owner = User::factory()->owner()->create();

        $this->preview($owner, $this->upload($owner, 'stock_in_pribadi', implode("\n", [
            'kategori,nama produk,hpp,qty',
            ',,275000,5',
            'HW,Skyline R34,275000,1',
        ])), [
            'header_row' => '0',
            'map' => [
                'series_name' => 'A',
                'product_id' => 'B',
                'cost_price' => 'C',
                'qty' => 'D',
            ],
        ])
            ->assertOk()
            ->assertSee('Baris pertama tidak boleh kosong kolom identitasnya')
            ->assertSee('#2');

        $this->assertDatabaseCount('stock_in_pribadi_drafts', 0);
    }

    /**
     * Qty tidak diwariskan dari induknya: selnya kosong memang berarti
     * "satu unit", seperti kolom qty yang memang tidak diisi sama sekali.
     * Menghitungnya sebagai qty induk akan melipatgandakan jumlah barang
     * yang tidak pernah ditulis siapapun.
     */
    #[Test]
    public function a_blank_qty_on_a_continuation_row_counts_as_one_unit(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        Product::factory()->create(['series_id' => $series->id, 'name' => 'Skyline R34', 'casting_code' => 'BAS12345']);
        Rack::factory()->create(['code' => 'A-01-01']);

        $url = $this->upload($owner, 'stock_in_pribadi', implode("\n", [
            'kategori,nama produk,hpp,rak,qty',
            'HW,Skyline R34,275000,A-01-01,3',
            ',,,A-01-01,',
        ]));

        $this->preview($owner, $url, [
            'header_row' => '0',
            'map' => [
                'series_name' => 'A',
                'product_id' => 'B',
                'cost_price' => 'C',
                'rack_id' => 'D',
                'qty' => 'E',
            ],
        ]);

        $this->commit($owner, $url);

        $items = StockInPribadiDraft::query()->sole()->items;

        $this->assertCount(1, $items);
        $this->assertSame(4, (int) $items[0]['qty']);
    }

    /**
     * Baris lanjutan yang menulis nilai berbeda berarti sang penulis memang
     * ingin baris itu tercatat terpisah. Menyatukannya akan menimpa nilai
     * yang sengaja ditulis, jadi barisnya dipertahankan dan perbedaannya
     * ditampilkan supaya tidak ada yang tersimpan diam-diam.
     */
    #[Test]
    public function a_divergent_continuation_row_stays_separate_and_is_explained_on_the_validate_page(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        Product::factory()->create(['series_id' => $series->id, 'name' => 'Skyline R34', 'casting_code' => 'BAS12345']);

        $url = $this->upload($owner, 'stock_in_pribadi', implode("\n", [
            'kategori,nama produk,hpp,qty',
            'HW,Skyline R34,275000,1',
            ',,300000,2',
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
            ->assertSee('tidak bisa digabung')
            ->assertSee('tidak digabung dengan baris #2')
            ->assertSee('Harga (HPP) berbeda')
            ->assertSee('Rp300.000')
            ->assertSee('Rp275.000');

        $this->commit($owner, $url);

        $items = StockInPribadiDraft::query()->sole()->items;

        $this->assertCount(2, $items);
        $this->assertSame(1, (int) $items[0]['qty']);
        $this->assertSame(275000, (int) $items[0]['cost_price']);
        $this->assertSame(2, (int) $items[1]['qty']);
        $this->assertSame(300000, (int) $items[1]['cost_price']);
    }

    /**
     * Penggabungan hanya masuk akal untuk Inbound, tempat satu blok berarti
     * satu produk satuan. Modul Master Data tetap baris-per-baris: satu baris
     * nama kosong tetap baris yang gagal, bukan lanjutan dari baris di atasnya.
     */
    #[Test]
    public function master_modules_are_not_merged_and_still_reject_a_row_without_a_name(): void
    {
        $owner = User::factory()->owner()->create();

        $response = $this->preview($owner, $this->upload($owner, 'katalog', implode("\n", [
            'nama produk,harga jual',
            ',100000',
            'Skyline R34,200000',
        ])), [
            'header_row' => '0',
            'map' => [
                'name:p1' => 'A',
                'default_list_price' => 'B',
            ],
        ]);

        $response->assertOk()
            ->assertSee('#2')
            ->assertDontSee('Baris Digabung');

        // Baris tanpa nama tetap gagal sendirian; baris lengkapnya tetap lolos
        // sebagai baris kedua, bukan disatukan dengan baris di atasnya.
        $this->assertMatchesRegularExpression('/Siap Disimpan<\/p>.*?<p[^>]*>1<\/p>/s', $response->getContent());
        $this->assertMatchesRegularExpression('/Gagal<\/p>.*?<p[^>]*>1<\/p>/s', $response->getContent());

        $this->assertDatabaseCount('products', 0);
    }

    /**
     * Penggabungan tidak ditentukan tampilan Excel-nya, melainkan isinya.
     * Menulis tiga baris identik berarti tiga satuan dari satu produk yang
     * sama; menghitungnya terpisah memecah satu penerimaan jadi tiga baris
     * yang tidak pernah diminta siapapun.
     */
    #[Test]
    public function repeated_identical_rows_without_merged_cells_are_stacked_into_one_line(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        $product = Product::factory()->create([
            'series_id' => $series->id,
            'name' => 'Skyline R34',
            'casting_code' => 'BAS12345',
        ]);
        Rack::factory()->create(['code' => 'A-01-01']);

        $url = $this->upload($owner, 'stock_in_pribadi', implode("\n", [
            'kategori,nama produk,kode casting,warna,hpp,qty,rak',
            'HW,Skyline R34,BAS12345,Merah,275000,1,A-01-01',
            'HW,Skyline R34,BAS12345,Merah,275000,2,A-01-01',
            'HW,Skyline R34,BAS12345,Merah,275000,1,A-01-01',
        ]));

        $response = $this->preview($owner, $url, [
            'header_row' => '0',
            'map' => [
                'series_name' => 'A',
                'product_id' => 'B',
                'casting_code' => 'C',
                'color' => 'D',
                'cost_price' => 'E',
                'qty' => 'F',
                'rack_id' => 'G',
            ],
        ]);

        $response->assertOk()
            ->assertSee('Baris Digabung')
            ->assertSee('#2–#4')
            ->assertSee('Skyline R34');

        $this->commit($owner, $url);

        $items = StockInPribadiDraft::query()->sole()->items;

        $this->assertCount(1, $items);
        $this->assertSame($product->id, (int) $items[0]['product_id']);
        $this->assertSame(4, (int) $items[0]['qty']);
    }

    /**
     * Baris berulang yang kolomnya tidak persis sama tetap berdiri sendiri:
     * menyatukannya berarti memilih harga untuk orang lain. Perbedaannya
     * harus terbaca di layar Validasi, bukan hilang diam-diam.
     */
    #[Test]
    public function repeated_rows_with_a_different_price_stay_apart_and_explain_why(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        Product::factory()->create([
            'series_id' => $series->id,
            'name' => 'Skyline R34',
            'casting_code' => 'BAS12345',
        ]);

        $url = $this->upload($owner, 'stock_in_pribadi', implode("\n", [
            'kategori,nama produk,kode casting,warna,hpp,qty',
            'HW,Skyline R34,BAS12345,Merah,275000,1',
            'HW,Skyline R34,BAS12345,Merah,300000,2',
        ]));

        $response = $this->preview($owner, $url, [
            'header_row' => '0',
            'map' => [
                'series_name' => 'A',
                'product_id' => 'B',
                'casting_code' => 'C',
                'color' => 'D',
                'cost_price' => 'E',
                'qty' => 'F',
            ],
        ]);

        $response->assertOk()
            ->assertSee('tidak bisa digabung')
            ->assertSee('Harga (HPP) berbeda')
            ->assertSee('Rp300.000')
            ->assertSee('Rp275.000');

        $this->commit($owner, $url);

        $items = StockInPribadiDraft::query()->sole()->items;

        $this->assertCount(2, $items);
        $this->assertSame(275000, (int) $items[0]['cost_price']);
        $this->assertSame(300000, (int) $items[1]['cost_price']);
    }

    /**
     * Empat penentu identitas -- Seri, produk, warna, HPP -- yang sama
     * membuat baris bersatu, walau kolom lain seperti rak ditulis beda.
     * Rak bukan penentu: baris kedua menambah qty, dan raknya mengikuti
     * baris pertama, tanpa dianggap "tidak bisa digabung".
     */
    #[Test]
    public function rows_sharing_the_four_identity_keys_merge_even_when_the_rack_differs(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        $product = Product::factory()->create([
            'series_id' => $series->id,
            'name' => 'Skyline R34',
            'casting_code' => 'BAS12345',
        ]);
        $firstRack = Rack::factory()->create(['code' => 'A-01-01']);
        Rack::factory()->create(['code' => 'A-01-02']);

        $url = $this->upload($owner, 'stock_in_pribadi', implode("\n", [
            'kategori,nama produk,kode casting,warna,hpp,qty,rak',
            'HW,Skyline R34,BAS12345,Merah,275000,1,A-01-01',
            'HW,Skyline R34,BAS12345,Merah,275000,2,A-01-02',
        ]));

        $response = $this->preview($owner, $url, [
            'header_row' => '0',
            'map' => [
                'series_name' => 'A',
                'product_id' => 'B',
                'casting_code' => 'C',
                'color' => 'D',
                'cost_price' => 'E',
                'qty' => 'F',
                'rack_id' => 'G',
            ],
        ]);

        $response->assertOk()
            ->assertSee('Disatukan jadi total qty')
            ->assertDontSee('tidak bisa digabung');

        $this->commit($owner, $url);

        $items = StockInPribadiDraft::query()->sole()->items;

        $this->assertCount(1, $items);
        $this->assertSame($product->id, (int) $items[0]['product_id']);
        $this->assertSame(3, (int) $items[0]['qty']);
        $this->assertSame($firstRack->id, (int) $items[0]['rack_id']);
    }

    /**
     * Unggah file, lalu ambil URL langkah Pemetaan dari redirect-nya.
     */
    private function upload(User $owner, string $module, string $content): string
    {
        $response = $this->actingAs($owner)
            ->post("/master/import/{$module}/upload", [
                'import_file' => UploadedFile::fake()->createWithContent('import.csv', $content."\n"),
            ])
            ->assertRedirect();

        return $this->urlFromResponse($response);
    }

    /**
     * Jalankan langkah validasi dan kembalikan halaman Validasi.
     */
    private function preview(User $owner, string $mappingUrl, array $mapping): TestResponse
    {
        return $this->actingAs($owner)->post($mappingUrl.'/preview', $mapping)->assertOk();
    }

    /**
     * Setujui hasil validasi -- halaman simpan memakai pemetaan tersimpan.
     */
    private function commit(User $owner, string $mappingUrl): TestResponse
    {
        return $this->actingAs($owner)->post($mappingUrl.'/commit')->assertRedirect();
    }

    private function urlFromResponse(TestResponse $response): string
    {
        return parse_url($response->headers->get('Location'), PHP_URL_PATH);
    }
}
