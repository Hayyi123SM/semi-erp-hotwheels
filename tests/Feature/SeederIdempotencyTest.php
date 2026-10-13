<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Consignment;
use App\Models\ConsignorLedger;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\WaTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Seeder harus aman dijalankan berulang.
 *
 * `migrate --seed` dijalankan setiap kali lingkungan disiapkan. Operator yang
 * menjalankannya dua kali tidak boleh mendapat nota penjualan kedua atau barang
 * yang tiba-tiba bertambah; hitungan baris adalah buktinya.
 */
class SeederIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeding_twice_produces_the_same_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00'));

        $this->seed();
        $first = $this->counts();

        $this->seed();

        $this->assertSame($first, $this->counts());

        // Angka yang sama bisa juga berarti seeder tidak jalan sama sekali.
        $this->assertGreaterThan(0, $first['consignments']);
        $this->assertGreaterThan(0, $first['stock_lots']);
        $this->assertGreaterThan(0, $first['sales']);

        Carbon::setTestNow();
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'consignments' => Consignment::count(),
            'stock_lots' => StockLot::count(),
            'sales' => Sale::count(),
            'sale_items' => SaleItem::count(),
            'sale_payments' => SalePayment::count(),
            'stock_movements' => StockMovement::count(),
            'ledger_entries' => ConsignorLedger::count(),
            'shifts' => Shift::count(),
            'settings' => Setting::count(),
            'wa_templates' => WaTemplate::count(),
        ];
    }
}
