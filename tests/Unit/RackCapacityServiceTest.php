<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Rack;
use App\Models\StockLot;
use App\Services\Inventory\RackCapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RackCapacityServiceTest extends TestCase
{
    use RefreshDatabase;

    private RackCapacityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RackCapacityService::class);
    }

    private function rack(?int $capacity, string $code = 'A-01-01'): Rack
    {
        return Rack::factory()->create(['code' => $code, 'capacity' => $capacity]);
    }

    private function lot(Rack $rack, int $qty): StockLot
    {
        return StockLot::factory()->create([
            'rack_id' => $rack->id,
            'qty_on_hand' => $qty,
            'qty_received' => $qty,
        ]);
    }

    #[Test]
    public function usage_is_counted_in_units_and_remaining_space_is_reported(): void
    {
        $rack = $this->rack(10);
        $this->lot($rack, 4);
        $this->lot($rack, 3);

        $this->assertSame(7, $this->service->usedUnits($rack));
        $this->assertSame(3, $this->service->available($rack));
    }

    #[Test]
    public function a_null_or_zero_capacity_means_unlimited(): void
    {
        foreach ([null, 0] as $capacity) {
            $rack = $this->rack($capacity, 'A-01-'.($capacity === null ? '02' : '03'));
            $this->lot($rack, 50);

            $this->assertNull($this->service->available($rack));
            $this->assertFalse($this->service->isLimited($rack));
            $this->service->assertFits($rack, 1_000);
        }
    }

    #[Test]
    public function incoming_that_exactly_fills_the_remaining_space_is_allowed(): void
    {
        $rack = $this->rack(5);
        $this->lot($rack, 2);

        $this->service->assertFits($rack, 3);

        $this->assertSame(3, $this->service->available($rack));
    }

    #[Test]
    public function incoming_that_exceeds_the_remaining_space_is_rejected_with_the_numbers(): void
    {
        $rack = $this->rack(5, 'A-01-03');
        $this->lot($rack, 4);

        try {
            $this->service->assertFits($rack, 2);
            $this->fail('Penerimaan melebihi kapasitas seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Rak A-01-03 tidak cukup: kapasitas 5 unit, terpakai 4, diminta 2 (sisa 1).',
                $exception->getMessage(),
            );
        }
    }

    #[Test]
    public function an_already_over_capacity_rack_reports_zero_remaining_space(): void
    {
        $rack = $this->rack(5);
        $this->lot($rack, 8);

        $this->assertSame(0, $this->service->available($rack));
    }

    #[Test]
    public function capacity_may_not_be_lowered_below_current_usage(): void
    {
        $rack = $this->rack(10);
        $this->lot($rack, 8);

        try {
            $this->service->assertCapacityCoversUsage($rack, 7);
            $this->fail('Kapasitas di bawah pemakaian seharusnya ditolak.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Kapasitas 7 unit tidak boleh di bawah pemakaian saat ini (8 unit). Kosongkan untuk tanpa batas.',
                $exception->getMessage(),
            );
        }
    }

    #[Test]
    public function capacity_equal_to_usage_or_without_a_limit_is_accepted(): void
    {
        $this->expectNotToPerformAssertions();

        $rack = $this->rack(10);
        $this->lot($rack, 8);

        $this->service->assertCapacityCoversUsage($rack, 8);
        $this->service->assertCapacityCoversUsage($rack, null);
        $this->service->assertCapacityCoversUsage($rack, 0);
        $this->service->assertCapacityCoversUsage($rack, 9);
    }

    #[Test]
    public function an_existing_over_capacity_rack_keeps_its_unchanged_capacity(): void
    {
        $this->expectNotToPerformAssertions();

        $rack = $this->rack(5);
        $this->lot($rack, 8);

        // Data lama yang sudah melebihi kapasitas tidak boleh mengunci edit
        // bagian lain selama kapasitasnya tidak diturunkan lagi.
        $this->service->assertCapacityCoversUsage($rack, 5);
    }
}
