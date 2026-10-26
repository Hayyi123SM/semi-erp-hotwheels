<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\LotStatus;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\Rack;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Picker SKU untuk modal Mulai Sesi opname.
 *
 * Kontrak yang dijaga: yang ditawarkan hanya lot yang juga akan diterima
 * `OpnameService::start()`, dan payload tidak membawa angka sistem. Picker yang
 * menawarkan lot nol membuat pengguna memilih SKU yang pasti ditolak; picker
 * yang mengirim qty membocorkan hal yang justru disembunyikan sesi opname.
 */
class OpnameSkuLookupTest extends TestCase
{
    use RefreshDatabase;

    private function lot(
        string $sku,
        string $productName = 'Nissan Skyline GT-R R34',
        int $qty = 3,
        LotStatus $status = LotStatus::Available,
        ?string $rackCode = null,
    ): StockLot {
        $product = Product::factory()->create([
            'series_id' => ProductSeries::factory()->create(['name' => 'Hot Wheels'])->id,
            'name' => $productName,
            'color' => 'Blue',
            'year' => 1999,
        ]);

        $rack = Rack::factory()->create($rackCode === null ? [] : ['code' => $rackCode]);

        return StockLot::factory()
            ->sku($sku)
            ->state([
                'product_id' => $product->id,
                'rack_id' => $rack->id,
                'qty_received' => $qty,
                'qty_on_hand' => $qty,
                'status' => $status,
            ])
            ->create();
    }

    private function search(User $actor, string $term): TestResponse
    {
        return $this->actingAs($actor)
            ->postJson(route('inventory.stok-opname.cari-sku'), ['q' => $term]);
    }

    #[Test]
    public function an_owner_gets_only_the_fields_needed_to_pick_a_lot(): void
    {
        $this->lot('CN01-HW-001', rackCode: 'A-01');

        $this->search(User::factory()->owner()->create(), 'CN01-HW-001')
            ->assertOk()
            ->assertExactJson([
                'items' => [
                    [
                        'sku' => 'CN01-HW-001',
                        'name' => 'Nissan Skyline GT-R R34',
                        'series' => 'Hot Wheels',
                        'rack' => 'A-01',
                        'color' => 'Blue',
                        'year' => 1999,
                    ],
                ],
            ]);
    }

    #[Test]
    public function a_product_name_search_finds_the_lot(): void
    {
        $this->lot('CN01-HW-001', 'Nissan Skyline GT-R R34');
        $this->lot('CD01-HW-002', 'Mazda RX-7');

        $this->search(User::factory()->owner()->create(), 'skyline')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.sku', 'CN01-HW-001');
    }

    #[Test]
    public function lots_that_opname_cannot_count_are_not_offered(): void
    {
        $this->lot('CN01-HW-001', qty: 3, status: LotStatus::Available);
        $this->lot('CN02-HW-002', qty: 2, status: LotStatus::SoldOut);
        $this->lot('CN03-HW-003', qty: 0, status: LotStatus::Available);
        $this->lot('CN04-HW-004', qty: 3, status: LotStatus::Returned);
        $this->lot('CN05-HW-005', qty: 3, status: LotStatus::WrittenOff);
        $this->lot('CN06-HW-006', qty: 3, status: LotStatus::Void);

        $response = $this->search(User::factory()->owner()->create(), 'HW')
            ->assertOk();

        $this->assertSame(
            ['CN01-HW-001', 'CN02-HW-002'],
            collect($response->json('items'))->pluck('sku')->all(),
        );
    }

    #[Test]
    public function an_exact_sku_match_is_listed_before_other_matches(): void
    {
        $this->lot('AAA-HW-EXACT');
        $this->lot('HW-EXACT');

        $response = $this->search(User::factory()->owner()->create(), 'HW-EXACT')
            ->assertOk();

        $this->assertSame(
            ['HW-EXACT', 'AAA-HW-EXACT'],
            collect($response->json('items'))->pluck('sku')->all(),
        );
    }

    #[Test]
    public function a_term_shorter_than_two_characters_returns_no_items(): void
    {
        $this->lot('CN01-HW-001');

        $this->search(User::factory()->owner()->create(), 'C')
            ->assertOk()
            ->assertExactJson(['items' => []]);
    }

    #[Test]
    public function the_result_is_capped_at_thirty_lots(): void
    {
        StockLot::factory()
            ->count(31)
            ->sequence(fn ($sequence): array => ['sku' => sprintf('BULK-%03d', $sequence->index)])
            ->create();

        $this->search(User::factory()->owner()->create(), 'BULK')
            ->assertOk()
            ->assertJsonCount(30, 'items');
    }

    #[Test]
    public function a_very_long_term_is_rejected(): void
    {
        $this->search(User::factory()->owner()->create(), str_repeat('a', 256))
            ->assertStatus(422)
            ->assertJsonValidationErrors('q');
    }

    #[Test]
    public function a_staff_user_is_forbidden(): void
    {
        $this->search(User::factory()->staff()->create(), 'CN01')
            ->assertForbidden();
    }

    #[Test]
    public function a_guest_is_rejected(): void
    {
        $this->postJson(route('inventory.stok-opname.cari-sku'), ['q' => 'CN01'])
            ->assertUnauthorized();
    }
}
