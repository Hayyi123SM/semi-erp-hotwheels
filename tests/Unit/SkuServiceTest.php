<?php

namespace Tests\Unit;

use App\Enums\MovementType;
use App\Enums\OwnerType;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\Rack;
use App\Models\SkuSequence;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\ReceiveStock;
use App\Services\Inventory\SkuService;
use App\Services\Inventory\StockLotService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SkuServiceTest extends TestCase
{
    use RefreshDatabase;

    private SkuService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SkuService::class);
    }

    #[Test]
    public function it_formats_sku_according_to_srs_regex(): void
    {
        $this->assertSame('CN01-HW-001', $this->service->format('CN01', 'HW', 1));
        $this->assertSame('CN01-HW-999', $this->service->format('CN01', 'HW', 999));
        $this->assertSame('OW00-HW-001', $this->service->format('OW00', 'HW', 1));
    }

    #[Test]
    public function it_grows_beyond_three_digits_without_breaking_the_regex(): void
    {
        // SRS: minimal 3 digit, melebar otomatis ke 4 digit.
        $this->assertSame('CN01-HW-1000', $this->service->format('CN01', 'HW', 1000));
        $this->assertSame('CN01-HW-100000', $this->service->format('CN01', 'HW', 100000));
    }

    #[Test]
    public function it_normalizes_case_of_owner_and_category(): void
    {
        $this->assertSame('CN01-HW-042', $this->service->format('cn01', 'hw', 42));
    }

    #[Test]
    public function it_validates_sku_against_the_official_regex(): void
    {
        $this->assertTrue(SkuService::isValidSku('CN01-HW-001'));
        $this->assertTrue(SkuService::isValidSku('OW00-MB-999999'));
        $this->assertTrue(SkuService::isValidSku('CN123-HW-1000'));

        $this->assertFalse(SkuService::isValidSku('XX01-HW-001'), 'Pemilik tidak dikenal.');
        $this->assertFalse(SkuService::isValidSku('CN01-H-001'), 'Kategori terlalu pendek.');
        $this->assertFalse(SkuService::isValidSku('CN01-HW-01'), 'Nomor urut kurang dari 3 digit.');
        $this->assertFalse(SkuService::isValidSku('CN01-HW-1234567'), 'Nomor urut melebihi 6 digit.');
    }

    #[Test]
    public function it_allocates_sequential_numbers_per_owner_and_category(): void
    {
        $first = $this->service->reserve('CN01');
        $second = $this->service->reserve('CN01');
        $third = $this->service->reserve('CN01');

        $this->assertSame(['CN01-HW-001', 'CN01-HW-002', 'CN01-HW-003'], [$first, $second, $third]);
    }

    #[Test]
    public function sequences_are_independent_per_owner(): void
    {
        $this->service->reserve('CN01');
        $this->service->reserve('CN01');

        $other = $this->service->reserve('CN02');

        $this->assertSame('CN02-HW-001', $other, 'Pemilik berbeda punya urut sendiri.');
    }

    #[Test]
    public function sequences_are_independent_per_category(): void
    {
        $this->service->reserve('CN01', 'HW');
        $this->service->reserve('CN01', 'HW');

        $other = $this->service->reserve('CN01', 'MB');

        $this->assertSame('CN01-MB-001', $other);
    }

    #[Test]
    public function it_never_reuses_a_number_after_a_lot_is_voided(): void
    {
        // SRS: "nomor tidak dipakai ulang" walau SKU di-void.
        $sku = $this->service->reserve('CN01');

        StockLot::factory()->sku($sku)->voided()->create();

        $this->assertSame('CN01-HW-002', $this->service->reserve('CN01'));
    }

    #[Test]
    public function it_continues_from_an_existing_sequence_row(): void
    {
        SkuSequence::factory()->forOwner('CN01', 'HW')->at(41)->create();

        $this->assertSame('CN01-HW-042', $this->service->reserve('CN01'));
    }

    #[Test]
    public function reserved_numbers_never_gap_under_sequential_use(): void
    {
        $numbers = [];

        for ($i = 0; $i < 25; $i++) {
            $numbers[] = (int) explode('-', $this->service->reserve('CN07'))[2];
        }

        $this->assertSame(range(1, 25), $numbers, 'Urutan tidak boleh bolong atau melompat.');
    }

    #[Test]
    public function it_persists_the_last_sequence(): void
    {
        $this->service->reserve('CN05');

        $this->assertSame(1, (int) SkuSequence::query()
            ->where('owner_code', 'CN05')
            ->where('category_code', 'HW')
            ->value('last_seq'));
    }

    #[Test]
    public function stock_lot_sku_is_unique_at_the_database_level(): void
    {
        // Jaring pengaman terakhir: walau alokasi nomor lolos, insert lot
        // dengan SKU kembar tetap gagal keras.
        $sku = $this->service->reserve('CN01');
        StockLot::factory()->sku($sku)->create();

        $this->expectException(QueryException::class);

        StockLot::factory()->sku($sku)->create();
    }

    #[Test]
    public function it_uses_the_product_series_code_as_category(): void
    {
        $series = ProductSeries::factory()->create(['code' => 'MB']);
        $product = Product::factory()->for($series, 'series')->create();

        $consignor = Consignor::factory()->create(['consignor_code' => 'CN09']);

        $lots = app(StockLotService::class)->receiveMany([
            ReceiveStock::forConsignment(
                Consignment::factory()->for($consignor)->create(),
                $consignor,
                $product,
                2,
            ),
        ]);

        $this->assertSame('CN09-MB-001', $lots[0]->sku);
    }

    #[Test]
    public function it_falls_back_to_default_category_when_series_code_is_empty(): void
    {
        $series = ProductSeries::factory()->create(['code' => null]);
        $product = Product::factory()->for($series, 'series')->create();

        $consignor = Consignor::factory()->create(['consignor_code' => 'CN09']);

        $lots = app(StockLotService::class)->receiveMany([
            ReceiveStock::forConsignment(
                Consignment::factory()->for($consignor)->create(),
                $consignor,
                $product,
                2,
            ),
        ]);

        $this->assertSame('CN09-HW-001', $lots[0]->sku);
    }

    #[Test]
    public function allocation_is_scoped_to_owner_type(): void
    {
        // Stok toko dan titipan punya urutan terpisah karena owner_code berbeda.
        $consignor = Consignor::factory()->create(['consignor_code' => 'CN01']);
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        $product = Product::factory()->for($series, 'series')->create();
        $service = app(StockLotService::class);

        $service->receive(ReceiveStock::forConsignment(
            Consignment::factory()->for($consignor)->create(),
            $consignor,
            $product,
            1,
        ));

        $own = $service->receive(ReceiveStock::forOwnStock(
            $product,
            1,
            30_000,
        ));

        $this->assertSame('OW00-HW-001', $own->sku);
        $this->assertSame(OwnerType::Own, $own->owner_type);
    }

    #[Test]
    public function movement_is_recorded_for_every_lot(): void
    {
        $consignor = Consignor::factory()->create(['consignor_code' => 'CN01']);
        $product = Product::factory()->create();
        $rack = Rack::factory()->create();

        $lot = app(StockLotService::class)->receive(
            ReceiveStock::forConsignment(
                Consignment::factory()->for($consignor)->create(),
                $consignor,
                $product,
                3,
                $rack,
            )
        );

        $movement = StockMovement::query()->where('lot_id', $lot->id)->firstOrFail();

        $this->assertSame(MovementType::InConsign, $movement->type);
        $this->assertSame(3, $movement->qty_delta);
        $this->assertSame(3, $movement->balance_after);
    }

    #[Test]
    public function actor_and_device_are_carried_into_the_movement(): void
    {
        $staff = User::factory()->create();

        $lot = app(StockLotService::class)->receive(
            ReceiveStock::forOwnStock(
                Product::factory()->create(),
                1,
                25_000,
                actor: $staff,
                deviceId: 'device-abc',
            )
        );

        $movement = StockMovement::query()->where('lot_id', $lot->id)->firstOrFail();

        $this->assertSame($staff->id, $movement->actor_id);
        $this->assertSame('device-abc', $movement->device_id);
    }
}
