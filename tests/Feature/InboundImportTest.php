<?php

namespace Tests\Feature;

use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\Rack;
use App\Models\StockInPribadiDraft;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Impor Excel untuk modul Inbound.
 *
 * Dua modul ini memakai mesin impor yang sama dengan Master Data, tetapi
 * tujuan akhirnya berbeda: pribadi hanya mengisi DRAFT, sedangkan consignment
 * langsung menjadi dokumen. Test di sini menjaga perbedaan itu supaya impor
 * tidak pernah diam-diam mengubah stok lewat pintu yang tidak dipantau form.
 */
class InboundImportTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function owner_can_open_both_inbound_import_pages(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/inbound/stock-in-pribadi/impor')->assertOk();
        $this->actingAs($owner)->get('/inbound/consignment-in/impor')->assertOk();
    }

    #[Test]
    public function staff_is_blocked_from_both_inbound_import_pages(): void
    {
        $staff = User::factory()->staff()->create();
        $file = UploadedFile::fake()->createWithContent('stock.csv', "nama produk\nSkyline\n");

        $this->actingAs($staff)->get('/inbound/stock-in-pribadi/impor')->assertForbidden();
        $this->actingAs($staff)->get('/inbound/consignment-in/impor')->assertForbidden();

        $this->actingAs($staff)
            ->post('/master/import/stock_in_pribadi/upload', ['import_file' => $file])
            ->assertForbidden();

        $this->actingAs($staff)
            ->post('/master/import/consignment_in/upload', ['import_file' => $file])
            ->assertForbidden();
    }

    #[Test]
    public function inbound_template_download_covers_both_modules(): void
    {
        $owner = User::factory()->owner()->create();

        foreach (['stock_in_pribadi', 'consignment_in'] as $module) {
            $this->actingAs($owner)
                ->get("/master/import/{$module}/template")
                ->assertOk()
                ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
    }

    /**
     * Impor pribadi hanya menyiapkan draft. Kalau baris ini justru membuat
     * lot atau movement, orang akan mengira barang sudah masuk padahal Owner
     * belum pernah menekan Simpan -- dan HPP yang belum dikoreksi pun ikut
     * terpakai di laporan.
     */
    #[Test]
    public function stock_in_pribadi_import_fills_the_draft_without_touching_stock(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        $product = Product::factory()->create([
            'series_id' => $series->id,
            'name' => 'Skyline R34',
            'casting_code' => 'BAS12345',
        ]);
        $rack = Rack::factory()->create(['code' => 'A-01-01']);

        $upload = $this->actingAs($owner)
            ->post('/master/import/stock_in_pribadi/upload', [
                'import_file' => UploadedFile::fake()->createWithContent(
                    'stock.csv',
                    "kategori,nama produk,kode casting,hpp,qty,rak\nHW,Skyline R34,BAS12345,275000,2,A-01-01\n",
                ),
            ])
            ->assertRedirect();

        $this->previewThenCommit($owner, $this->urlFromResponse($upload), [
            'header_row' => '0',
            'map' => [
                'series_name' => 'A',
                'product_id' => 'B',
                'casting_code' => 'C',
                'cost_price' => 'D',
                'qty' => 'E',
                'rack_id' => 'F',
            ],
        ])->assertRedirect(route('inbound.stock-in-pribadi'));

        $draft = StockInPribadiDraft::query()->sole();
        $items = $draft->items;

        $this->assertCount(1, $items);
        $this->assertSame($product->id, (int) $items[0]['product_id']);
        $this->assertSame(275000, (int) $items[0]['cost_price']);
        $this->assertSame(2, (int) $items[0]['qty']);
        $this->assertSame($rack->id, (int) $items[0]['rack_id']);

        $this->assertDatabaseCount('stock_lots', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'IMPORT']);
    }

    /**
     * Regression: berkas yang lebih panjang dari batas lama form (100 baris)
     * harus berakhir sebagai stok, bukan sebagai daftar yang ditolak.
     *
     * Dulu impor menerima sampai `SpreadsheetService::MAX_ROWS` baris ke draft,
     * sementara kedua request form dibatasi `max:100` -- akibatnya daftar yang
     * baru saja diisi impor sendiri tidak bisa disimpan oleh form, dan pesan
     * validasinya hilang diam-diam di balik auto-save. rantai penuhnya diuji di
     * sini: impor mengisi draft, lalu form meng-commit isi draft itu.
     */
    #[Test]
    public function an_import_larger_than_the_old_form_limit_can_be_committed_from_the_form(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::factory()->create(['code' => 'HW', 'name' => 'Hot Wheels']);
        $products = collect(range(1, 150))->map(fn (int $index): Product => Product::factory()->create([
            'series_id' => $series->id,
            'name' => "Skyline R{$index}",
            'casting_code' => "BAS{$index}",
        ]));

        $csv = implode("\n", array_merge(
            ['kategori,nama produk,kode casting,hpp,qty'],
            $products->map(
                fn (Product $product): string => sprintf(
                    'HW,%s,%s,275000,1',
                    $product->name,
                    $product->casting_code,
                ),
            )->all(),
        ))."\n";

        $upload = $this->actingAs($owner)
            ->post('/master/import/stock_in_pribadi/upload', [
                'import_file' => UploadedFile::fake()->createWithContent('stock.csv', $csv),
            ])
            ->assertRedirect();

        $this->previewThenCommit($owner, $this->urlFromResponse($upload), [
            'header_row' => '0',
            'map' => [
                'series_name' => 'A',
                'product_id' => 'B',
                'casting_code' => 'C',
                'cost_price' => 'D',
                'qty' => 'E',
            ],
        ])->assertRedirect(route('inbound.stock-in-pribadi'));

        $draft = StockInPribadiDraft::query()->sole();
        $items = $draft->items;

        $this->assertCount(150, $items);
        $this->assertSame($products->first()->id, (int) $items[0]['product_id']);

        // Metadata tampilan ikut terisi oleh kommitter: baris hasil impor
        // menampilkan badge seri, warna, dan tahun seperti baris picker.
        $this->assertSame('Hot Wheels', $items[0]['series_name']);
        $this->assertSame($products->first()->color, $items[0]['color']);
        $this->assertSame($products->first()->year, (int) $items[0]['year']);

        // Form meng-commit daftar persis yang diisi impor.
        $this->actingAs($owner)
            ->post('/inbound/stock-in-pribadi', [
                'source' => 'INV-2026-0917',
                'verified' => '1',
                'items' => array_map(fn (array $item): array => [
                    'product_id' => (int) $item['product_id'],
                    'qty' => (int) $item['qty'],
                    'cost_price' => (int) $item['cost_price'],
                ], $items),
            ])
            ->assertRedirect(route('inbound.stock-in-pribadi'))
            ->assertSessionHasNoErrors();

        $this->assertSame('CANCELLED', StockInPribadiDraft::query()->sole()->status);
        $this->assertDatabaseCount('stock_movements', 150);
        $this->assertDatabaseCount('stock_lots', 150);
    }

    /**
     * Satu (penitip, tanggal) menjadi satu dokumen, dan barisnya ditulis
     * dalam satu transaksi. Yang dipin di sini adalah hasil akhirnya: satu
     * dokumen berisi dua SKU, bukan dua dokumen setengah jadi.
     */
    #[Test]
    public function consignment_import_groups_rows_into_one_document_per_consignor_and_date(): void
    {
        $owner = User::factory()->owner()->create();
        $consignor = Consignor::factory()->create(['consignor_code' => 'CN01']);
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        $productA = Product::factory()->create(['series_id' => $series->id, 'name' => 'Skyline R34', 'casting_code' => 'BAS12345']);
        $productB = Product::factory()->create(['series_id' => $series->id, 'name' => 'AE86 Trueno', 'casting_code' => 'BAS99999']);

        $upload = $this->actingAs($owner)
            ->post('/master/import/consignment_in/upload', [
                'import_file' => UploadedFile::fake()->createWithContent(
                    'consignment.csv',
                    implode("\n", [
                        'kode penitip,nama produk,kode casting,harga jual,qty,tgl masuk',
                        'CN01,Skyline R34,BAS12345,500000,2,2026-10-01',
                        'CN01,AE86 Trueno,BAS99999,,1,2026-10-01',
                    ])."\n",
                ),
            ])
            ->assertRedirect();

        $this->previewThenCommit($owner, $this->urlFromResponse($upload), [
            'header_row' => '0',
            'map' => [
                'consignor_id' => 'A',
                'product_id' => 'B',
                'casting_code' => 'C',
                'list_price' => 'D',
                'qty' => 'E',
                'consignment_date' => 'F',
            ],
        ])->assertRedirect(route('inbound.consignment-in.riwayat'));

        $consignment = Consignment::sole();

        $this->assertSame($consignor->id, $consignment->consignor_id);
        $this->assertSame(3, (int) $consignment->qty_received);
        $this->assertSame(2, $consignment->stockLots()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'RECEIVE_CONSIGN']);

        // Harga jual mengikuti barisnya; yang kosong jatuh ke kontrak penitip
        // atau harga bawaan produk, bukan ke 0.
        $priced = StockLot::query()->where('product_id', $productA->id)->sole();
        $this->assertSame(500000, (int) $priced->list_price);

        $unpriced = StockLot::query()->where('product_id', $productB->id)->sole();
        $this->assertNotSame(0, (int) $unpriced->list_price);

        $this->assertDatabaseCount('stock_in_pribadi_drafts', 0);
    }

    /**
     * Produk yang tidak dikenal membuat barisnya gagal di langkah validasi,
     * bukan menunggu sampai commit. Tanpa ini baris yang lolos akan menulis
     * dokumen tanpa SKU-nya sendiri dan hitungannya tidak pernah cocok.
     */
    #[Test]
    public function an_unknown_product_is_rejected_at_preview_for_inbound_modules(): void
    {
        $owner = User::factory()->owner()->create();
        Consignor::factory()->create(['consignor_code' => 'CN01']);

        $upload = $this->actingAs($owner)
            ->post('/master/import/consignment_in/upload', [
                'import_file' => UploadedFile::fake()->createWithContent(
                    'consignment.csv',
                    "kode penitip,nama produk\nCN01,Produk Yang Tidak Ada\n",
                ),
            ])
            ->assertRedirect();

        $this->actingAs($owner)
            ->post($this->urlFromResponse($upload).'/preview', [
                'header_row' => '0',
                'map' => ['consignor_id' => 'A', 'product_id' => 'B'],
            ])
            ->assertOk()
            ->assertSee('tidak ditemukan')
            ->assertSee('Tidak Ada yang Bisa Diimpor');

        $this->assertDatabaseCount('consignments', 0);
        $this->assertDatabaseCount('stock_lots', 0);
    }

    /**
     * Berkas dari template kita sendiri harus langsung terpetakan tanpa
     * pemilihan manual. Kalau tebakannya meleset, orang akan mengira kolom
     * "Nama Produk" dipakai untuk kolom lain dan satu sel jadi menulis dua
     * atribut sekaligus.
     */
    #[Test]
    public function the_mapping_page_suggests_inbound_columns_from_the_file_headers(): void
    {
        $owner = User::factory()->owner()->create();

        $upload = $this->actingAs($owner)
            ->post('/master/import/consignment_in/upload', [
                'import_file' => UploadedFile::fake()->createWithContent(
                    'consignment.csv',
                    "kode penitip,nama produk,harga jual\nCN01,Skyline R34,500000\n",
                ),
            ])
            ->assertRedirect();

        $page = $this->actingAs($owner)
            ->get($this->urlFromResponse($upload))
            ->assertOk();

        $this->assertSame(
            3,
            substr_count($page->getContent(), 'Terdeteksi otomatis dari judul kolom berkas.'),
            'Kode Penitip, Nama Produk, dan Harga Jual seharusnya sama-sama terdeteksi otomatis.',
        );
    }

    /**
     * Baris yang lolos validasi lalu gagal waktu disimpan tidak boleh disimpan
     * sebagian. Satu berkas Inbound adalah satu kiriman: menerima separuhnya
     * tanpa pesan berarti orang menerima kiriman yang tidak lengkap tanpa tahu
     * berkasnya menolak sisanya.
     *
     * Sesinya sengaja dibiarkan hidup, supaya daftar baris yang gagal masih
     * bisa dibuka dan dicoba lagi tanpa mengunggah ulang berkas.
     */
    #[Test]
    public function a_row_that_fails_at_commit_holds_back_the_whole_inbound_file(): void
    {
        $owner = User::factory()->owner()->create();
        Consignor::factory()->create(['consignor_code' => 'CN01']);
        $removed = Consignor::factory()->create(['consignor_code' => 'CN02']);
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        Product::factory()->create([
            'series_id' => $series->id,
            'name' => 'Skyline R34',
            'casting_code' => 'BAS12345',
        ]);

        $upload = $this->actingAs($owner)
            ->post('/master/import/consignment_in/upload', [
                'import_file' => UploadedFile::fake()->createWithContent(
                    'consignment.csv',
                    implode("\n", [
                        'kode penitip,nama produk,kode casting,qty',
                        'CN01,Skyline R34,BAS12345,2',
                        'CN02,Skyline R34,BAS12345,1',
                    ])."\n",
                ),
            ])
            ->assertRedirect();

        $mappingUrl = $this->urlFromResponse($upload);
        $validateUrl = route('master.import.validate', ['consignment_in', basename($mappingUrl)]);

        // Kedua baris lolos pada awalnya: belum ada satu pun yang gagal.
        $this->actingAs($owner)
            ->post($mappingUrl.'/preview', [
                'header_row' => '0',
                'map' => [
                    'consignor_id' => 'A',
                    'product_id' => 'B',
                    'casting_code' => 'C',
                    'qty' => 'D',
                ],
            ])
            ->assertOk()
            ->assertDontSee('Baris yang Gagal Diperiksa');

        // Data di aplikasi berubah di antara validasi dan simpan.
        $removed->delete();

        $this->actingAs($owner)
            ->post($mappingUrl.'/commit')
            ->assertRedirect($validateUrl);

        // Satu baris seharusnya disimpan sebelum perbaikan ini, karena hanya
        // baris satunya yang ikut gugur. Sekarang tidak ada yang tersimpan.
        $this->assertDatabaseCount('consignments', 0);
        $this->assertDatabaseCount('stock_lots', 0);

        // `assertSessionHasErrors` sengaja tidak dipakai: assertion itu menyalakan
        // session store lebih dulu, dan karena tes tidak membawa cookie sesi antar
        // request, flash error-nya hilang sebelum halaman Validasi sempat membacanya.
        // Banner di bawah membuktikan pesannya sampai ke halaman itu.
        $this->actingAs($owner)
            ->get($validateUrl)
            ->assertOk()
            ->assertSee('Simpan dibatalkan')
            ->assertSee('Berkas Belum Bisa Disimpan')
            ->assertSee('Baris yang Gagal Diperiksa')
            ->assertDontSee('Setujui');

        // Peta kolom dan berkasnya masih ada: cukup lewat pemetaan lalu
        // validasi lagi, tidak perlu mengunggah ulang.
        $this->actingAs($owner)->get($mappingUrl)->assertOk();
    }

    /**
     * Skema komisi yang belum lengkap baru ketahuan ketika baris benar-benar
     * ditulis, bukan di langkah validasi. Pesannya harus sampai ke orang di
     * halaman Validasi, bukan berhenti di halaman error, dan sesinya harus
     * tetap hidup supaya kesalahannya bisa diperbaiki lalu dicoba lagi.
     */
    #[Test]
    public function a_commit_time_validation_error_is_shown_on_the_validate_step(): void
    {
        $owner = User::factory()->owner()->create();
        Consignor::factory()->create([
            'consignor_code' => 'CN01',
            'scheme_rate' => null,
        ]);
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        Product::factory()->create([
            'series_id' => $series->id,
            'name' => 'Skyline R34',
            'casting_code' => 'BAS12345',
        ]);

        $upload = $this->actingAs($owner)
            ->post('/master/import/consignment_in/upload', [
                'import_file' => UploadedFile::fake()->createWithContent(
                    'consignment.csv',
                    "kode penitip,nama produk,kode casting,qty\nCN01,Skyline R34,BAS12345,2\n",
                ),
            ])
            ->assertRedirect();

        $mappingUrl = $this->urlFromResponse($upload);
        $validateUrl = route('master.import.validate', ['consignment_in', basename($mappingUrl)]);

        // Validasi tidak memeriksa skema, jadi barisnya lolos di sini.
        $this->actingAs($owner)
            ->post($mappingUrl.'/preview', [
                'header_row' => '0',
                'map' => [
                    'consignor_id' => 'A',
                    'product_id' => 'B',
                    'casting_code' => 'C',
                    'qty' => 'D',
                ],
            ])
            ->assertOk()
            ->assertSee('Siap Disimpan')
            ->assertDontSee('Baris yang Gagal Diperiksa');

        $this->actingAs($owner)
            ->post($mappingUrl.'/commit')
            ->assertRedirect($validateUrl);

        $this->assertDatabaseCount('consignments', 0);
        $this->assertDatabaseCount('stock_lots', 0);

        // Sama seperti tes di atas: banner Validasi, bukan `assertSessionHasErrors`,
        // yang membuktikan pesan kesalahan waktu simpan sampai ke halaman itu.
        $this->actingAs($owner)
            ->get($validateUrl)
            ->assertOk()
            ->assertSee('Simpan dibatalkan')
            ->assertSee('Parameter skema wajib diisi');

        $this->actingAs($owner)->get($mappingUrl)->assertOk();
    }

    /**
     * Kapasitas rak diperiksa saat baris benar-benar ditulis. Kegagalan di
     * langkah ini harus membatalkan seluruh berkas, bukan menyimpan sebagian.
     */
    #[Test]
    public function a_consignment_import_that_exceeds_the_rack_capacity_is_rejected(): void
    {
        $owner = User::factory()->owner()->create();
        Consignor::factory()->create(['consignor_code' => 'CN01']);
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        Product::factory()->create([
            'series_id' => $series->id,
            'name' => 'Skyline R34',
            'casting_code' => 'BAS12345',
        ]);
        Rack::factory()->create(['code' => 'A-01-01', 'capacity' => 5]);

        $upload = $this->actingAs($owner)
            ->post('/master/import/consignment_in/upload', [
                'import_file' => UploadedFile::fake()->createWithContent(
                    'consignment.csv',
                    implode("\n", [
                        'kode penitip,nama produk,kode casting,harga jual,qty,tgl masuk,rak',
                        'CN01,Skyline R34,BAS12345,500000,6,2026-10-01,A-01-01',
                    ])."\n",
                ),
            ])
            ->assertRedirect();

        $mappingUrl = $this->urlFromResponse($upload);

        $this->previewThenCommit($owner, $mappingUrl, [
            'header_row' => '0',
            'map' => [
                'consignor_id' => 'A',
                'product_id' => 'B',
                'casting_code' => 'C',
                'list_price' => 'D',
                'qty' => 'E',
                'consignment_date' => 'F',
                'rack_id' => 'G',
            ],
        ])->assertRedirect(route('master.import.validate', ['consignment_in', basename($mappingUrl)]))
            ->assertSessionHasErrors([
                'items' => 'Rak A-01-01 tidak cukup: kapasitas 5 unit, terpakai 0, diminta 6 (sisa 5).',
            ]);

        $this->assertDatabaseCount('consignments', 0);
        $this->assertDatabaseCount('stock_lots', 0);
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
     * yang disimpan langkah validasi.
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
