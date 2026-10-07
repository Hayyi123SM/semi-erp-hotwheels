<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Consignor;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Endpoint pencarian produk untuk layar kasir.
 *
 * Yang dijaga di sini adalah bentuk jawabannya, bukan hanya isinya. Panel picker
 * membaca `lot` untuk memutuskan barang yang dipindai bisa langsung masuk keranjang
 * atau harus dialog "tidak ditemukan", dan `items` untuk mengisi daftar. Kalau
 * keduanya tidak dibedakan, kasir tidak punya cara tahu barang mana yang dipindai --
 * dan itu kesalahan yang baru terlihat saat dia menjual barang yang salah.
 *
 * Authentication juga dijaga. Pencarian produk bukan data sensitif, jadi keduanya
 * boleh; yang tidak boleh adalah request tanpa login, karena itu mengembalikan
 * daftar stok toko ini ke publik.
 */
class ProductLookupControllerTest extends TestCase
{
    use RefreshDatabase;

    private function lot(string $sku, string $productName = 'Nissan Skyline GT-R R34', array $overrides = []): Factory
    {
        $product = Product::factory()->create([
            'series_id' => ProductSeries::factory()->create(['name' => 'Hot Wheels'])->id,
            'name' => $productName,
        ]);

        return StockLot::factory()
            ->sku($sku)
            ->state(fn (): array => $overrides + [
                'product_id' => $product->id,
                'list_price' => 220_000,
                'qty_on_hand' => 3,
            ]);
    }

    // ================= Bentuk jawaban =================

    #[Test]
    public function a_scan_returns_the_lot_and_a_list_holding_it(): void
    {
        $this->lot('CN01-HW-001')->create();

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('pos.produk.cari'), ['barcode' => 'CN01-HW-001'])
            ->assertOk()
            ->assertJsonStructure(['lot' => ['id', 'sku', 'name', 'price', 'ownership', 'sellable'], 'items'])
            ->assertJsonPath('lot.sku', 'CN01-HW-001')
            ->assertJsonPath('items.0.sku', 'CN01-HW-001');
    }

    #[Test]
    public function a_scan_that_matches_nothing_reports_null_lot_and_no_items(): void
    {
        $this->lot('CN01-HW-001')->create();

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('pos.produk.cari'), ['barcode' => 'CN99-HW-999'])
            ->assertOk()
            ->assertExactJson(['lot' => null, 'items' => []]);
    }

    #[Test]
    public function a_name_search_returns_a_list_and_no_single_lot(): void
    {
        $this->lot('CN01-HW-001')->create();

        $response = $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('pos.produk.cari'), ['q' => 'skyline'])
            ->assertOk()
            ->assertJsonPath('lot', null)
            ->assertJsonPath('items.0.sku', 'CN01-HW-001');

        $this->assertCount(1, $response->json('items'));
    }

    #[Test]
    public function a_blank_term_returns_an_empty_list_without_error(): void
    {
        $this->lot('CN01-HW-001')->create();

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('pos.produk.cari'), ['q' => ''])
            ->assertOk()
            ->assertExactJson(['lot' => null, 'items' => []]);
    }

    #[Test]
    public function a_blank_barcode_is_not_treated_as_a_scan(): void
    {
        $this->lot('CN01-HW-001')->create();

        // Scanner yang memicu Enter dengan kolom kosong tidak boleh melihat
        // dirinya seperti kasir yang tidak sengaja memindai barang pertama yang
        // ada di tabel.
        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('pos.produk.cari'), ['barcode' => ''])
            ->assertOk()
            ->assertExactJson(['lot' => null, 'items' => []]);
    }

    #[Test]
    public function both_roles_get_the_same_answer(): void
    {
        $this->lot('CN01-HW-001')->create();

        $staff = $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('pos.produk.cari'), ['barcode' => 'CN01-HW-001'])
            ->json();

        $owner = $this->actingAs(User::factory()->owner()->create())
            ->postJson(route('pos.produk.cari'), ['barcode' => 'CN01-HW-001'])
            ->json();

        // A difference here would look like a permission bug in the search
        // rather than a lookup bug, and would be reported as the wrong one.
        $this->assertSame($staff, $owner);
    }

    // ================= Batas =================

    #[Test]
    public function a_guest_cannot_search_the_store_stock(): void
    {
        $this->lot('CN01-HW-001')->create();

        $this->postJson(route('pos.produk.cari'), ['barcode' => 'CN01-HW-001'])
            ->assertUnauthorized();
    }

    #[Test]
    public function a_very_long_term_is_rejected(): void
    {
        $this->lot('CN01-HW-001')->create();

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('pos.produk.cari'), ['q' => str_repeat('a', 256)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    #[Test]
    public function a_scan_returns_a_lot_that_is_not_for_sale_so_the_cashier_sees_why(): void
    {
        // It is reported, not hidden. The picker greys it out and says why, which
        // is the difference between a cashier who looks for the item elsewhere and
        // one who knows it is already sold.
        $this->lot('CN01-HW-001')->soldOut()->create();

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('pos.produk.cari'), ['barcode' => 'CN01-HW-001'])
            ->assertOk()
            ->assertJsonPath('lot.sellable', false)
            ->assertJsonPath('lot.status', 'SOLD_OUT');
    }

    #[Test]
    public function the_answer_names_the_consignor_so_the_cashier_can_confirm_the_right_goods(): void
    {
        $consignor = Consignor::factory()->create(['name' => 'Dewi Lestari']);

        $this->lot('CN01-HW-001')
            ->ownedBy($consignor)
            ->create();

        $this->actingAs(User::factory()->staff()->create())
            ->postJson(route('pos.produk.cari'), ['barcode' => 'CN01-HW-001'])
            ->assertOk()
            ->assertJsonPath('lot.ownership', 'TITIP')
            ->assertJsonPath('lot.consignor', 'Dewi Lestari');
    }
}
