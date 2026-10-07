<?php

namespace Tests\Unit;

use App\Enums\CardCondition;
use App\Enums\ConsignmentStatus;
use App\Enums\DiscountPolicy;
use App\Enums\LabelStatus;
use App\Enums\LotStatus;
use App\Enums\MovementType;
use App\Enums\OwnerType;
use App\Enums\SchemeType;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\LabelPrintJob;
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
use App\Services\Label\LabelPrinterSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StockLotServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockLotService $service;

    private ProductSeries $series;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StockLotService::class);
        $this->series = ProductSeries::factory()->create(['code' => 'HW']);
    }

    private function product(): Product
    {
        return Product::factory()->for($this->series, 'series')->create([
            'default_list_price' => 45_000,
        ]);
    }

    #[Test]
    public function it_creates_a_lot_for_consigned_stock(): void
    {
        $consignor = Consignor::factory()->create(['consignor_code' => 'CN01']);
        $product = $this->product();

        $lot = $this->service->receive(ReceiveStock::forConsignment(
            Consignment::factory()->for($consignor)->create(),
            $consignor,
            $product,
            4,
        ));

        $this->assertSame('CN01-HW-001', $lot->sku);
        $this->assertSame(OwnerType::Consign, $lot->owner_type);
        $this->assertSame('CN01', $lot->owner_code);
        $this->assertSame($consignor->id, $lot->consignor_id);
        $this->assertSame(4, $lot->qty_received);
        $this->assertSame(4, $lot->qty_on_hand);
        $this->assertSame(LotStatus::Available, $lot->status);
    }

    #[Test]
    public function it_snapshots_consignor_terms_onto_the_lot(): void
    {
        $consignor = Consignor::factory()->nett(38_000)->sharedDiscount()->create([
            'consignor_code' => 'CN01',
        ]);

        $lot = $this->service->receive(ReceiveStock::forConsignment(
            Consignment::factory()->for($consignor)->create(),
            $consignor,
            $this->product(),
            1,
        ));

        $this->assertSame(SchemeType::Nett, $lot->scheme_type);
        $this->assertNull($lot->scheme_rate);
        $this->assertSame(38_000, $lot->scheme_amount);
        $this->assertSame(DiscountPolicy::Shared, $lot->discount_policy);
    }

    #[Test]
    public function later_changes_to_consignor_terms_do_not_rewrite_existing_lots(): void
    {
        $consignor = Consignor::factory()->percentage(20)->create(['consignor_code' => 'CN01']);
        $product = $this->product();

        $lot = $this->service->receive(ReceiveStock::forConsignment(
            Consignment::factory()->for($consignor)->create(),
            $consignor,
            $product,
            1,
        ));

        // Penitip menaikkan skema menjadi 30% setelah lot diterima.
        $consignor->update(['scheme_type' => SchemeType::Percentage, 'scheme_rate' => 30]);

        $lot->refresh();

        $this->assertSame(20.0, (float) $lot->scheme_rate, 'Lot lama harus menyimpan snapshot lama.');
    }

    #[Test]
    public function consigned_stock_does_not_require_cost_price(): void
    {
        $consignor = Consignor::factory()->create(['consignor_code' => 'CN01']);

        $lot = $this->service->receive(ReceiveStock::forConsignment(
            Consignment::factory()->for($consignor)->create(),
            $consignor,
            $this->product(),
            1,
        ));

        $this->assertNull($lot->cost_price);
    }

    #[Test]
    public function it_creates_a_lot_for_personal_stock_without_consignment(): void
    {
        $lot = $this->service->receive(ReceiveStock::forOwnStock(
            $this->product(),
            6,
            32_000,
        ));

        $this->assertSame('OW00-HW-001', $lot->sku);
        $this->assertSame(OwnerType::Own, $lot->owner_type);
        $this->assertSame(SkuService::OWN_CODE, $lot->owner_code);
        $this->assertNull($lot->consignment_id, 'Stok toko tidak boleh punya consignment.');
        $this->assertNull($lot->consignor_id, 'Stok toko tidak boleh punya penitip.');
        $this->assertSame(32_000, $lot->cost_price);
        $this->assertNull($lot->scheme_type);
    }

    #[Test]
    public function personal_stock_records_an_in_own_movement(): void
    {
        $lot = $this->service->receive(ReceiveStock::forOwnStock($this->product(), 6, 32_000));

        $movement = StockMovement::query()->where('lot_id', $lot->id)->firstOrFail();

        $this->assertSame(MovementType::InOwn, $movement->type);
        $this->assertSame(6, $movement->qty_delta);
        $this->assertSame(6, $movement->balance_after);
        $this->assertNull($movement->ref_type, 'Stok toko tidak punya acuan consignment.');
    }

    #[Test]
    public function it_marks_the_consignment_as_committed(): void
    {
        $consignor = Consignor::factory()->create(['consignor_code' => 'CN01']);
        $consignment = Consignment::factory()->for($consignor)->create([
            'status' => ConsignmentStatus::Draft,
        ]);

        $this->service->receive(ReceiveStock::forConsignment(
            $consignment,
            $consignor,
            $this->product(),
            1,
        ));

        $consignment->refresh();

        $this->assertSame(ConsignmentStatus::Committed, $consignment->status);
        $this->assertNotNull($consignment->committed_at);
    }

    #[Test]
    public function it_queues_an_initial_label_job(): void
    {
        $consignor = Consignor::factory()->create(['consignor_code' => 'CN01']);

        $this->service->receive(ReceiveStock::forConsignment(
            Consignment::factory()->for($consignor)->create(),
            $consignor,
            $this->product(),
            1,
        ));

        $job = LabelPrintJob::query()->firstOrFail();

        $this->assertSame(LabelStatus::Queued, $job->status);
        $this->assertSame(1, $job->copies);
        $this->assertSame('3x2', $job->template);
    }

    #[Test]
    public function label_queueing_can_be_skipped(): void
    {
        $this->service->receive(ReceiveStock::forOwnStock(
            $this->product(),
            1,
            30_000,
            queueLabel: false,
        ));

        $this->assertSame(0, LabelPrintJob::query()->count());
    }

    #[Test]
    public function receiving_many_rows_allocates_a_contiguous_sequence(): void
    {
        $consignor = Consignor::factory()->create(['consignor_code' => 'CN01']);
        $consignment = Consignment::factory()->for($consignor)->create();
        $products = Product::factory()->count(3)->for($this->series, 'series')->create()->all();

        $lots = $this->service->receiveMany(array_map(
            fn (Product $product) => ReceiveStock::forConsignment(
                $consignment,
                $consignor,
                $product,
                2,
            ),
            $products,
        ));

        $this->assertSame(
            ['CN01-HW-001', 'CN01-HW-002', 'CN01-HW-003'],
            array_map(fn (StockLot $lot) => $lot->sku, $lots)
        );

        $consignment->refresh();
        $this->assertSame(ConsignmentStatus::Committed, $consignment->status);
    }

    #[Test]
    public function receiving_many_rejects_rows_from_different_consignments(): void
    {
        $a = Consignor::factory()->create(['consignor_code' => 'CN01']);
        $b = Consignor::factory()->create(['consignor_code' => 'CN02']);
        $product = $this->product();

        $this->expectException(ValidationException::class);

        $this->service->receiveMany([
            ReceiveStock::forConsignment(Consignment::factory()->for($a)->create(), $a, $product, 1),
            ReceiveStock::forConsignment(Consignment::factory()->for($b)->create(), $b, $product, 1),
        ]);
    }

    #[Test]
    public function receiving_many_rejects_an_empty_batch(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->receiveMany([]);
    }

    #[Test]
    public function receiving_many_does_not_partially_commit(): void
    {
        $consignor = Consignor::factory()->create(['consignor_code' => 'CN01']);
        $consignment = Consignment::factory()->for($consignor)->create();
        $products = Product::factory()->count(2)->for($this->series, 'series')->create()->all();

        // Alokasi kedua dipaksa gagal untuk membuktikan batch bersifat atomik:
        // baris pertama yang sudah diproses harus ikut batal.
        $skus = \Mockery::mock(SkuService::class);
        $skus->shouldReceive('nextSequence')
            ->once()
            ->andReturn(1);
        $skus->shouldReceive('nextSequence')
            ->once()
            ->andThrow(new \RuntimeException('Simulasi kegagalan di tengah batch.'));
        $skus->shouldReceive('format')
            ->once()
            ->andReturn('CN01-HW-001');

        $service = new StockLotService($skus, new LabelPrinterSettings);

        try {
            $service->receiveMany([
                ReceiveStock::forConsignment($consignment, $consignor, $products[0], 1),
                ReceiveStock::forConsignment($consignment, $consignor, $products[1], 1),
            ]);
            $this->fail('Batch harus gagal pada baris kedua.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulasi kegagalan di tengah batch.', $e->getMessage());
        }

        $this->assertSame(0, StockLot::query()->count(), 'Tidak boleh ada lot yang tersisa.');
        $this->assertSame(0, StockMovement::query()->count(), 'Tidak boleh ada movement tersisa.');
        $this->assertSame(0, LabelPrintJob::query()->count(), 'Tidak boleh ada label tersisa.');
        $this->assertSame(0, SkuSequence::query()->count(), 'Nomor urut harus ikut batal.');

        $consignment->refresh();
        $this->assertSame(ConsignmentStatus::Draft, $consignment->status, 'Consignment tidak boleh ikut ter-commit.');
    }

    #[Test]
    public function it_rejects_a_consignment_belonging_to_another_consignor(): void
    {
        $a = Consignor::factory()->create();
        $b = Consignor::factory()->create();

        $this->expectException(ValidationException::class);

        ReceiveStock::forConsignment(
            Consignment::factory()->for($a)->create(),
            $b,
            $this->product(),
            1,
        );
    }

    #[Test]
    public function it_rejects_zero_quantity(): void
    {
        $consignor = Consignor::factory()->create();

        $this->expectException(ValidationException::class);

        ReceiveStock::forConsignment(
            Consignment::factory()->for($consignor)->create(),
            $consignor,
            $this->product(),
            0,
        );
    }

    #[Test]
    public function it_rejects_negative_cost_price_for_personal_stock(): void
    {
        $this->expectException(ValidationException::class);

        ReceiveStock::forOwnStock($this->product(), 1, -1);
    }

    #[Test]
    public function it_accepts_a_generator_once_for_a_whole_batch(): void
    {
        $consignor = Consignor::factory()->create(['consignor_code' => 'CN01']);
        $consignment = Consignment::factory()->for($consignor)->create();
        $products = Product::factory()->count(2)->for($this->series, 'series')->create()->all();

        // Generator hanya bisa diiterasi sekali; receiveMany harus menanganinya.
        $generator = (function () use ($consignment, $consignor, $products) {
            foreach ($products as $product) {
                yield ReceiveStock::forConsignment($consignment, $consignor, $product, 1);
            }
        })();

        $lots = $this->service->receiveMany($generator);

        $this->assertCount(2, $lots);
        $this->assertSame('CN01-HW-002', $lots[1]->sku);
    }

    #[Test]
    public function it_records_the_actor_and_marks_the_consignment_committer(): void
    {
        $staff = User::factory()->staff()->create();
        $consignor = Consignor::factory()->create(['consignor_code' => 'CN01']);
        $consignment = Consignment::factory()->for($consignor)->create();

        $this->service->receive(ReceiveStock::forConsignment(
            $consignment,
            $consignor,
            $this->product(),
            1,
            actor: $staff,
        ));

        $consignment->refresh();
        $movement = StockMovement::query()->firstOrFail();

        $this->assertSame($staff->id, $consignment->committed_by);
        $this->assertSame($staff->id, $movement->actor_id);
        $this->assertSame($staff->id, LabelPrintJob::query()->firstOrFail()->requested_by);
    }

    #[Test]
    public function it_assigns_rack_and_keeps_capacity_untouched(): void
    {
        $rack = Rack::factory()->create(['capacity' => 10]);
        $consignor = Consignor::factory()->create(['consignor_code' => 'CN01']);

        $lot = $this->service->receive(ReceiveStock::forConsignment(
            Consignment::factory()->for($consignor)->create(),
            $consignor,
            $this->product(),
            3,
            $rack,
        ));

        $this->assertSame($rack->id, $lot->rack_id);
        $this->assertSame(10, $rack->refresh()->capacity, 'Kapasitas rak tidak boleh berubah saat penerimaan.');
    }

    #[Test]
    public function it_uses_product_conditions_unless_overridden(): void
    {
        $consignor = Consignor::factory()->create(['consignor_code' => 'CN01']);
        $product = $this->product();

        $fromProduct = $this->service->receive(ReceiveStock::forConsignment(
            Consignment::factory()->for($consignor)->create(),
            $consignor,
            $product,
            1,
        ));

        $overridden = $this->service->receive(ReceiveStock::forConsignment(
            Consignment::factory()->for($consignor)->create(),
            $consignor,
            $product,
            1,
            cardCondition: CardCondition::Damaged,
        ));

        $this->assertSame($product->card_condition, $fromProduct->card_condition);
        $this->assertSame(CardCondition::Damaged, $overridden->card_condition);
    }

    #[Test]
    public function it_copies_the_product_list_price_onto_the_lot(): void
    {
        $consignor = Consignor::factory()->create(['consignor_code' => 'CN01']);

        $lot = $this->service->receive(ReceiveStock::forConsignment(
            Consignment::factory()->for($consignor)->create(),
            $consignor,
            $this->product(),
            1,
        ));

        $this->assertSame(45_000, $lot->list_price);
    }
}
