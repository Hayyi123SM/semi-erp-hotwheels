<?php

namespace Tests\Unit\Report;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Report\SalesReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Laporan penjualan: angka yang tidak bicara soal laba.
 *
 * Angka laporan ini harus bisa dijelaskan dari baris-baris `sales`: total, jumlah
 * nota, item, dan metode pembayaran. Baris yang dibatalkan (VOID) tidak boleh
 * ikut terhitung, `Transfer` tidak boleh muncul di rekap metode POS, dan kasir
 * Staff hanya melihat shift miliknya sendiri -- batas yang sama dengan halaman
 * riwayat nota.
 */
class SalesReportServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function summary_counts_only_paid_sales_in_the_period(): void
    {
        $cashier = User::factory()->staff()->create(['name' => 'Kasir A']);
        $shift = Shift::factory()->forUser($cashier)->create();
        $sale = Sale::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'total' => 140_000,
            'status' => SaleStatus::Paid,
            'sold_at' => now()->subDays(2),
        ]);
        Sale::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'total' => 60_000,
            'status' => SaleStatus::Paid,
            'sold_at' => now()->subDays(2),
        ]);
        Sale::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'total' => 500_000,
            'status' => SaleStatus::Voided,
            'sold_at' => now()->subDays(2),
        ]);
        Sale::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'total' => 999_999,
            'status' => SaleStatus::Paid,
            'sold_at' => now()->subDays(45),
        ]);

        $summary = (new SalesReportService)->summary($cashier, now()->subDays(7)->toDateString(), now()->toDateString());

        $this->assertSame(200_000, $summary['total'], 'Total harus menjumlahkan nota PAID saja dalam periode.');
        $this->assertSame(2, $summary['nota']);
        $this->assertSame('Kasir A', $summary['topKasir']['name']);
        $this->assertSame(200_000, $summary['topKasir']['total']);
    }

    #[Test]
    public function per_method_never_lists_transfer_for_pos(): void
    {
        $cashier = User::factory()->staff()->create();
        $shift = Shift::factory()->forUser($cashier)->create();
        $sale = Sale::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'total' => 140_000,
            'status' => SaleStatus::Paid,
            'sold_at' => now()->subDays(1),
        ]);

        SalePayment::factory()->create(['sale_id' => $sale->id, 'method' => PaymentMethod::Cash, 'amount' => 50_000]);
        SalePayment::factory()->create(['sale_id' => $sale->id, 'method' => PaymentMethod::Qris, 'amount' => 90_000]);
        // Pembayaran transfer tidak pernah terjadi di kasir; rekap yang
        // mencantumkannya akan tampak sebagai data hilang di laci.
        SalePayment::factory()->create(['sale_id' => $sale->id, 'method' => PaymentMethod::Transfer, 'amount' => 1_000_000]);

        $summary = (new SalesReportService)->summary($cashier, now()->subDays(7)->toDateString(), now()->toDateString());

        $labels = array_column($summary['perMetode'], 'label');
        $amounts = array_column($summary['perMetode'], 'amount');

        $this->assertNotContains('Transfer', $labels);
        $this->assertCount(2, $amounts, 'Hanya metode POS yang muncul (Tunai + QRIS).');
        $this->assertSame(140_000, array_sum($amounts), 'Rupiah transfer tidak boleh ikut terhitung.');
    }

    #[Test]
    public function staff_only_sees_their_own_shift(): void
    {
        $kasirA = User::factory()->staff()->create(['name' => 'Kasir A']);
        $kasirB = User::factory()->staff()->create(['name' => 'Kasir B']);
        $owner = User::factory()->owner()->create(['name' => 'Owner']);

        $shiftA = Shift::factory()->forUser($kasirA)->create();
        $shiftB = Shift::factory()->forUser($kasirB)->create();

        Sale::factory()->create(['shift_id' => $shiftA->id, 'user_id' => $kasirA->id, 'total' => 100_000, 'status' => SaleStatus::Paid]);
        Sale::factory()->create(['shift_id' => $shiftB->id, 'user_id' => $kasirB->id, 'total' => 300_000, 'status' => SaleStatus::Paid]);

        $service = new SalesReportService;

        $this->assertSame(300_000, $service->summary($kasirB, null, null)['total'], 'Kasir B tidak boleh melihat shift Kasir A.');
        $this->assertSame(400_000, $service->summary($owner, null, null)['total'], 'Owner melihat semua shift.');
        $this->assertSame(100_000, $service->summary($kasirA, null, null)['total']);
    }

    #[Test]
    public function shifts_grouped_by_shift_with_main_payment_method(): void
    {
        $cashier = User::factory()->staff()->create(['name' => 'Kasir C']);
        $shift = Shift::factory()->forUser($cashier)->create();
        $sale = Sale::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'total' => 110_000,
            'status' => SaleStatus::Paid,
            'sold_at' => now()->subHours(3),
        ]);
        SalePayment::factory()->create(['sale_id' => $sale->id, 'method' => PaymentMethod::Cash, 'amount' => 70_000]);
        SalePayment::factory()->create(['sale_id' => $sale->id, 'method' => PaymentMethod::Qris, 'amount' => 40_000]);

        $shifts = (new SalesReportService)->shifts($cashier, null, null);

        $this->assertCount(1, $shifts);
        $this->assertSame('Tunai', $shifts[0]['metode'], 'Metode utama adalah yang memegang rupiah terbanyak.');
        $this->assertSame(110_000, $shifts[0]['total']);
    }

    #[Test]
    public function period_defaults_to_the_last_30_days_end_of_day(): void
    {
        $cashier = User::factory()->staff()->create();
        $shift = Shift::factory()->forUser($cashier)->create();
        Sale::factory()->create(['shift_id' => $shift->id, 'user_id' => $cashier->id, 'total' => 50_000, 'status' => SaleStatus::Paid, 'sold_at' => now()->subDays(31)]);
        Sale::factory()->create(['shift_id' => $shift->id, 'user_id' => $cashier->id, 'total' => 25_000, 'status' => SaleStatus::Paid, 'sold_at' => now()->subDays(29)]);

        $summary = (new SalesReportService)->summary($cashier, null, null);

        $this->assertSame(25_000, $summary['total']);
        $this->assertSame(1, $summary['nota']);
    }

    #[Test]
    public function sold_at_closes_the_day_at_its_end(): void
    {
        $cashier = User::factory()->staff()->create();
        $shift = Shift::factory()->forUser($cashier)->create();
        Sale::factory()->create([
            'shift_id' => $shift->id,
            'user_id' => $cashier->id,
            'total' => 30_000,
            'status' => SaleStatus::Paid,
            'sold_at' => Carbon::parse('2026-09-20 20:00:00'),
        ]);

        $summary = (new SalesReportService)->summary($cashier, '2026-09-20', '2026-09-20');

        $this->assertSame(30_000, $summary['total'], 'Penjualan malam hari di tanggal 20 harus tetap masuk periode 20.');
    }
}
