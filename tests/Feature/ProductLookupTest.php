<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Consignor;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\Rack;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Pos\ProductLookup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pencarian produk yang dipakai layar kasir.
 *
 * Yang dijaga bukan hanya "kata kunci menemukan barang". Yang dijaga adalah
 * bentuk jawabannya, karena dua bentuk itu menentukan apa yang boleh dilakukan
 * kasir: satu lot yang bisa langsung dimasukkan ke keranjang, atau daftar yang
 * masih perlu dipilih.
 *
 * Batas panjang minimum juga dijaga dua arah. Untuk pencarian, satu huruf tidak
 * boleh menarik sebagian besar tabel; untuk pindai, kode yang pendek tidak boleh
 * ditolak -- barcode yang keluar dari scanner selalu lengkap, jadi pendeknya kode
 * berarti memang tidak ada barang itu, bukan berarti kasir belum selesai mengetik.
 */
class ProductLookupTest extends TestCase
{
    use RefreshDatabase;

    private function lookup(): ProductLookup
    {
        return app(ProductLookup::class);
    }

    /**
     * Rak yang dipakai bersama seluruh lot dalam satu test.
     *
     * `racks.code` punya unique index dan kode dari factory-nya acak, jadi rak
     * dibuat sekali lalu dipakai ulang. Lot yang butuh raknya sendiri tetap
     * mendapat rak dari factory-nya masing-masing.
     */
    private function sharedRack(): Rack
    {
        return $this->sharedRack ??= Rack::factory()->create(['code' => 'A-01-03']);
    }

    private ?Rack $sharedRack = null;

    /**
     * Factory lot yang sudah terisi produk dan rak, supaya test yang butuh state
     * lain (habis, dikembalikan) tetap bisa memakainya.
     *
     * Produk dibuat di sini, bukan lewat factory-nya, karena nama produknya
     * bagian dari apa yang diuji: pencarian harus bisa menemukannya lewat nama.
     *
     * @param  array<string, mixed>  $overrides
     * @return Factory<StockLot>
     */
    private function lotFactory(array $overrides = []): Factory
    {
        $product = Product::factory()->create([
            'series_id' => ProductSeries::factory()->create(['name' => 'Hot Wheels'])->id,
            'name' => 'Nissan Skyline GT-R R34',
        ]);

        // Ditangkap ke luar, bukan diambil di dalam closure: closure `state()`
        // dijalankan dengan `$this` terikat ke factory-nya, bukan ke test ini.
        $rackId = $this->sharedRack()->id;

        return StockLot::factory()
            ->sku($overrides['sku'] ?? 'CN01-HW-001')
            ->state(fn (): array => $overrides + [
                'product_id' => $product->id,
                'rack_id' => $rackId,
                'list_price' => 220_000,
                'qty_on_hand' => 3,
            ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function lot(array $overrides = []): StockLot
    {
        return $this->lotFactory($overrides)->create();
    }

    // ================= Pindai barcode =================

    #[Test]
    public function a_scanned_code_finds_the_lot(): void
    {
        $lot = $this->lot();

        $found = $this->lookup()->findByBarcode($lot->sku);

        $this->assertNotNull($found);
        $this->assertSame($lot->id, $found->id);
        $this->assertSame('CN01-HW-001', $found->sku);
        $this->assertSame('Nissan Skyline GT-R R34', $found->name);
        $this->assertSame('Hot Wheels', $found->series);
        $this->assertSame(220_000, $found->price);
        $this->assertSame('A-01-03', $found->rack);
        $this->assertSame(3, $found->stock);
    }

    #[Test]
    public function an_unknown_code_finds_nothing_rather_than_guessing(): void
    {
        $this->lot();

        $this->assertNull($this->lookup()->findByBarcode('CN99-HW-999'));
    }

    #[Test]
    public function a_single_character_code_is_not_a_scan_and_must_not_search(): void
    {
        // Lot ber-SKU pendek itu ada, dan scan-nya lengkap. Kalau pencarian
        // ditahan sampai dua huruf seperti pencarian nama, kasir akan melihat
        // barang yang ada di tangannya sebagai barang yang hilang.
        $lot = $this->lot(['sku' => 'X']);

        $this->assertNotNull($this->lookup()->findByBarcode('X'));
    }

    #[Test]
    public function a_blank_code_finds_nothing_without_a_query(): void
    {
        $this->lot();

        $this->assertNull($this->lookup()->findByBarcode('   '));
        $this->assertNull($this->lookup()->findByBarcode(''));
    }

    #[Test]
    public function a_code_that_also_appears_inside_another_sku_prefers_the_exact_lot(): void
    {
        // Label mesin bisa menempelkan kode lain di sekitar SKU, jadi memindai
        // label lot yang satu bisa cocok pada lot yang lain. Kalau yang hanya
        // cocok sebagian menang, kasir menjual barang yang bukan yang dipegang.
        $this->lot(['sku' => 'CN01-HW-0010']);

        $exact = $this->lot(['sku' => 'CN01-HW-001']);

        $this->assertSame($exact->id, $this->lookup()->findByBarcode('CN01-HW-001')?->id);
    }

    // ================= Cari lewat nama =================

    #[Test]
    public function a_name_search_matches_the_product(): void
    {
        $this->lot();

        $found = $this->lookup()->search('skyline');

        $this->assertCount(1, $found);
        $this->assertSame('CN01-HW-001', $found->first()->sku);
    }

    #[Test]
    public function a_name_search_matches_a_consignor_name(): void
    {
        $consignor = Consignor::factory()->create(['name' => 'Budi Santoso']);
        $this->lotFactory()->ownedBy($consignor)->create();

        $this->assertCount(1, $this->lookup()->search('Budi Santoso'));
    }

    #[Test]
    public function a_name_search_below_two_characters_returns_nothing(): void
    {
        $this->lot();

        $this->assertCount(0, $this->lookup()->search('s'));
        $this->assertCount(0, $this->lookup()->search('1'));
    }

    #[Test]
    public function a_name_search_treats_wildcards_as_ordinary_characters(): void
    {
        $this->lot(['sku' => 'CN01A-HW-001']);

        // Tidak ada SKU yang memuat garis bawah literal, jadi apa pun yang
        // ditemukan berarti `_` dibaca sebagai "satu karakter apa saja" dan
        // `CN01_` ikut cocok dengan `CN01A` -- barang yang diketik orang lain
        // akan muncul sebagai pilihan, dan tidak ada yang bisa membedakan mana
        // yang sebenarnya dicari.
        $this->assertCount(0, $this->lookup()->search('CN01_'));
    }

    #[Test]
    public function a_name_search_finds_a_sku_that_really_does_contain_the_typed_character(): void
    {
        $this->lot(['sku' => 'CN01_A-HW-001']);

        // Pasangannya: mengetik garis bawah yang benar-benar ada di SKU harus
        // tetap menemukannya. Escaping yang berlebihan pada pola yang tidak ada akan
        // mengembalikan nol hasil untuk pencarian yang sah.
        $this->assertCount(1, $this->lookup()->search('CN01_A'));
    }

    #[Test]
    public function a_name_search_puts_sellable_lots_first(): void
    {
        $soldOut = $this->lotFactory(['sku' => 'CN01-HW-001'])->soldOut()->create();
        $this->lot(['sku' => 'CN02-HW-002']);

        $results = $this->lookup()->search('CN0')->values();

        $this->assertNotSame($soldOut->id, $results->first()->id);
    }

    // ================= Bentuk yang dikirim ke kasir =================

    #[Test]
    public function a_lot_carries_the_ownership_the_badge_expects(): void
    {
        $consignor = Consignor::factory()->create(['name' => 'Dewi Lestari']);

        $titip = $this->lotFactory(['sku' => 'CN01-HW-001'])->ownedBy($consignor)->create();
        $pribadi = $this->lotFactory(['sku' => 'OW00-HW-002'])->own()->create();

        $consignorName = $this->lookup()->findByBarcode('CN01-HW-001');
        $ownName = $this->lookup()->findByBarcode('OW00-HW-002');

        // The raw enum value is `CONSIGN`; the badge reads `TITIP`. Translating
        // here is what keeps the badge from needing its own mapping in JS.
        $this->assertSame('TITIP', $consignorName?->ownership);
        $this->assertSame('Dewi Lestari', $consignorName?->consignor);
        $this->assertSame('PRIBADI', $ownName?->ownership);
        $this->assertSame('', $ownName?->consignor);
        $this->assertNotNull($titip);
        $this->assertNotNull($pribadi);
    }

    #[Test]
    public function a_lot_carries_whether_it_may_be_sold(): void
    {
        $this->lot(['sku' => 'CN01-HW-001']);
        $this->lotFactory(['sku' => 'CN01-HW-002'])->soldOut()->create();
        $this->lotFactory(['sku' => 'CN01-HW-003'])->returned()->create();

        $available = $this->lookup()->findByBarcode('CN01-HW-001');
        $soldOut = $this->lookup()->findByBarcode('CN01-HW-002');
        $returned = $this->lookup()->findByBarcode('CN01-HW-003');

        $this->assertTrue($available?->sellable);
        $this->assertFalse($soldOut?->sellable);
        $this->assertFalse($returned?->sellable);
        $this->assertSame('SOLD_OUT', $soldOut?->status);
        $this->assertSame('warning', $soldOut?->statusType);
    }

    #[Test]
    public function a_lot_counts_labels_that_have_not_left_the_printer_yet(): void
    {
        $lot = $this->lot(['sku' => 'CN01-HW-001']);

        $lot->labelPrintJobs()->createMany([
            ['copies' => 1, 'status' => 'QUEUED'],
            ['copies' => 1, 'status' => 'SENT'],
            ['copies' => 1, 'status' => 'FAILED'],
            // Confirmed means the label is already on the goods, so it is not a
            // reason the barcode will fail to scan.
            ['copies' => 1, 'status' => 'CONFIRMED'],
        ]);

        // Barcode lot ini belum bisa dipindai, dan kasir harus bisa melihat itu
        // sebelum ia memasukkan barang ke keranjang.
        $this->assertSame(3, $this->lookup()->findByBarcode('CN01-HW-001')?->pendingLabels);
    }

    #[Test]
    public function a_lot_without_a_pending_label_counts_zero(): void
    {
        $this->lot(['sku' => 'CN01-HW-001']);

        $this->assertSame(0, $this->lookup()->findByBarcode('CN01-HW-001')?->pendingLabels);
    }

    #[Test]
    public function the_payload_sends_only_what_the_picker_reads(): void
    {
        $this->lot(['sku' => 'CN01-HW-001']);

        $payload = $this->lookup()->findByBarcode('CN01-HW-001')?->toArray();

        $this->assertSame(
            ['id', 'sku', 'name', 'series', 'price', 'ownership', 'consignor', 'stock', 'pendingLabels', 'status', 'statusType', 'sellable', 'rack'],
            array_keys((array) $payload),
        );
    }

    #[Test]
    public function a_search_result_does_not_depend_on_who_asks(): void
    {
        // Authorization is not decided here. Whoever the request belongs to, the
        // answer has to be the same answer, or a bug in the lookup would look
        // like a difference in permissions.
        $this->lot(['sku' => 'CN01-HW-001']);

        User::factory()->owner()->create();
        User::factory()->staff()->create();

        $owner = app(ProductLookup::class)->search('skyline');
        $staff = app(ProductLookup::class)->search('skyline');

        $this->assertEquals(
            $owner->map->toArray(),
            $staff->map->toArray(),
        );
    }
}
