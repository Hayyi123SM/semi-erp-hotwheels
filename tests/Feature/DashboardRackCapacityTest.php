<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rack;
use App\Models\StockLot;
use App\Services\Report\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kartu "Kapasitas Rak" di beranda memakai satuan yang sama dengan halaman
 * rak dan formulir penerimaan: unit fisik, bukan jumlah lot.
 */
class DashboardRackCapacityTest extends TestCase
{
    use RefreshDatabase;

    private function lot(Rack $rack, int $qty): StockLot
    {
        return StockLot::factory()->create([
            'rack_id' => $rack->id,
            'qty_on_hand' => $qty,
            'qty_received' => $qty,
        ]);
    }

    #[Test]
    public function rack_usage_is_reported_in_units_instead_of_lot_count(): void
    {
        $rack = Rack::factory()->create(['code' => 'A-01-03', 'capacity' => 24]);
        $this->lot($rack, 30);
        $this->lot($rack, 18);

        $row = collect(app(DashboardService::class)->rackCapacity())
            ->firstWhere('code', 'A-01-03');

        $this->assertSame(48, $row['items']);
        $this->assertSame(24, $row['capacity']);
        $this->assertSame(100, $row['usage']);
    }

    #[Test]
    public function a_rack_without_capacity_is_reported_without_a_percentage(): void
    {
        $rack = Rack::factory()->create(['code' => 'B-01-05', 'capacity' => null]);
        $this->lot($rack, 7);

        $row = collect(app(DashboardService::class)->rackCapacity())
            ->firstWhere('code', 'B-01-05');

        $this->assertSame(7, $row['items']);
        $this->assertNull($row['capacity']);
        $this->assertSame(0, $row['usage']);
    }
}
