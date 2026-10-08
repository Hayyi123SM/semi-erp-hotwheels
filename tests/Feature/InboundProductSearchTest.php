<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Endpoint pencarian produk untuk popup Stock In Pribadi.
 *
 * Yang dijaga di sini bukan hanya isinya, tapi juga sifatnya: pencarian ini
 * level produk, bukan level lot seperti picker kasir, karena form ini diisi
 * sebelum barang diterima dan produk yang dicari belum tentu punya satu lot
 * pun. Karena itu `product_id` wajib ada di setiap hasil -- tanpa primary key
 * itu baris tidak bisa disimpan, dan kegagalannya terlihat sebagai "klik tidak
 * melakukan apa-apa" di layar.
 *
 * Produk `INACTIVE` juga dijaga: dropdown lama sebelum halaman ini dipindai ke
 * popup tidak pernah menampilkan produk nonaktif, dan endpoint ini harus
 * mewarisi batas yang sama.
 */
class InboundProductSearchTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, array $overrides = []): Product
    {
        return Product::factory()->create($overrides + [
            'series_id' => ProductSeries::factory()->create(['name' => 'Hot Wheels', 'code' => 'HW'])->id,
            'name' => $name,
        ]);
    }

    // ================= Bentuk jawaban =================

    #[Test]
    public function a_search_returns_products_with_the_primary_key_the_form_needs(): void
    {
        $product = $this->product('Nissan Skyline GT-R R34');

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('inbound.produk.cari'), ['q' => 'skyline'])
            ->assertOk()
            ->assertJsonStructure([
                'items' => [[
                    'product_id',
                    'name',
                    'series',
                    'casting_code',
                    'barcode',
                ]],
            ])
            ->assertJsonPath('items.0.product_id', $product->id)
            ->assertJsonPath('items.0.name', 'Nissan Skyline GT-R R34')
            ->assertJsonPath('items.0.series', 'Hot Wheels');
    }

    #[Test]
    public function a_search_finds_a_product_by_its_casting_code(): void
    {
        $product = $this->product('Toyota Supra', ['casting_code' => 'HWX-42']);

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('inbound.produk.cari'), ['q' => 'HWX-42'])
            ->assertOk()
            ->assertJsonPath('items.0.product_id', $product->id);
    }

    #[Test]
    public function a_search_finds_a_product_by_its_series_name(): void
    {
        $product = $this->product('Mazda RX-7');

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('inbound.produk.cari'), ['q' => 'hot wheels'])
            ->assertOk()
            ->assertJsonPath('items.0.product_id', $product->id);
    }

    #[Test]
    public function a_blank_term_returns_an_empty_list_without_error(): void
    {
        $this->product('Nissan Skyline GT-R R34');

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('inbound.produk.cari'), ['q' => ''])
            ->assertOk()
            ->assertExactJson(['items' => []]);
    }

    #[Test]
    public function a_one_letter_term_returns_an_empty_list_without_error(): void
    {
        // Sengaja identik dengan batas klien (`MIN_TERM_LENGTH`): server yang
        // menjawab lebih dulu dari yang diharapkan panel picker membuat
        // "Tidak ditemukan" muncul sebelum operator selesai mengetik.
        $this->product('Nissan Skyline GT-R R34');

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('inbound.produk.cari'), ['q' => 'n'])
            ->assertOk()
            ->assertExactJson(['items' => []]);
    }

    // ================= Barcode =================

    #[Test]
    public function a_scan_by_factory_barcode_finds_the_product(): void
    {
        $product = $this->product('Nissan Skyline GT-R R34', [
            'factory_barcode_ref' => '8991234567890',
        ]);

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('inbound.produk.cari'), ['barcode' => '8991234567890'])
            ->assertOk()
            ->assertJsonPath('items.0.product_id', $product->id)
            ->assertJsonPath('items.0.barcode', '8991234567890');
    }

    #[Test]
    public function a_scan_by_casting_code_finds_the_product(): void
    {
        $product = $this->product('Toyota Supra', ['casting_code' => 'HWX-42']);

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('inbound.produk.cari'), ['barcode' => 'HWX-42'])
            ->assertOk()
            ->assertJsonPath('items.0.product_id', $product->id);
    }

    #[Test]
    public function a_scan_by_an_old_wms_label_finds_the_product_it_belongs_to(): void
    {
        $product = $this->product('Nissan Skyline GT-R R34');

        StockLot::factory()->create([
            'product_id' => $product->id,
            'sku' => 'OW00-HW-001',
        ]);

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('inbound.produk.cari'), ['barcode' => 'OW00-HW-001'])
            ->assertOk()
            ->assertJsonPath('items.0.product_id', $product->id);
    }

    #[Test]
    public function a_scan_that_matches_nothing_returns_an_empty_list(): void
    {
        $this->product('Nissan Skyline GT-R R34');

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('inbound.produk.cari'), ['barcode' => '0000000000000'])
            ->assertOk()
            ->assertExactJson(['items' => []]);
    }

    #[Test]
    public function a_blank_barcode_is_not_treated_as_a_scan(): void
    {
        $this->product('Nissan Skyline GT-R R34', [
            'factory_barcode_ref' => '8991234567890',
        ]);

        // Scanner yang memicu Enter dengan kolom kosong tidak boleh melihat
        // daftar semua produk sebagai hasil pindaian.
        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('inbound.produk.cari'), ['barcode' => ''])
            ->assertOk()
            ->assertExactJson(['items' => []]);
    }

    // ================= Batas =================

    #[Test]
    public function an_inactive_product_is_not_searchable(): void
    {
        $this->product('Nissan Skyline GT-R R34', ['status' => ProductStatus::Inactive]);

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('inbound.produk.cari'), ['q' => 'skyline'])
            ->assertOk()
            ->assertExactJson(['items' => []]);
    }

    #[Test]
    public function an_inactive_product_cannot_be_found_by_scan_either(): void
    {
        $this->product('Nissan Skyline GT-R R34', [
            'factory_barcode_ref' => '8991234567890',
            'status' => ProductStatus::Inactive,
        ]);

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('inbound.produk.cari'), ['barcode' => '8991234567890'])
            ->assertOk()
            ->assertExactJson(['items' => []]);
    }

    #[Test]
    public function a_guest_cannot_search_the_product_catalog(): void
    {
        $this->product('Nissan Skyline GT-R R34');

        $this->postJson(route('inbound.produk.cari'), ['q' => 'skyline'])
            ->assertUnauthorized();
    }

    #[Test]
    public function a_very_long_term_is_rejected(): void
    {
        $this->product('Nissan Skyline GT-R R34');

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('inbound.produk.cari'), ['q' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }
}
