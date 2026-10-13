<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\CardCondition;
use App\Enums\MovementType;
use App\Enums\QuarantineReason;
use App\Enums\QuarantineStatus;
use App\Enums\RackType;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\QuarantineCase;
use App\Models\Rack;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Alur inti Fase 2 karantina.
 *
 * Registrasi kasus (nomor QC-YYYYMMDD-NNN), pencarian kandidat lot,
 * lalu resolusi assign/eskalasi/write-off dengan validasi selisih S-C-Q-V.
 */
class QuarantineFlowTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    private function registerData(Rack $rack, array $overrides = []): array
    {
        return $overrides + [
            'qty' => 2,
            'rack_id' => $rack->id,
            'keyword' => 'Ford Mustang GT',
            'series' => 'American Scene',
            'color' => 'Oxford White',
            'condition' => CardCondition::Mint->value,
            'reason' => QuarantineReason::LabelRusak->value,
        ];
    }

    #[Test]
    public function owner_can_register_a_case_and_it_appears_in_the_queue(): void
    {
        $owner = $this->owner();
        $rack = Rack::factory()->create(['type' => RackType::Quarantine]);

        $this->actingAs($owner)
            ->post(route('inventory.karantina.store'), $this->registerData($rack))
            ->assertRedirect();

        $case = QuarantineCase::query()->first();
        $this->assertNotNull($case);
        $this->assertMatchesRegularExpression('/^QC-\d{8}-\d{3}$/', $case->case_no);
        $this->assertSame(QuarantineStatus::Open, $case->status);
        $this->assertSame(2, $case->qty);
        $this->assertSame($rack->id, $case->rack_id);
        $this->assertSame($owner->id, $case->opened_by);
        $this->assertSame('Ford Mustang GT', $case->attributes['model']);
        $this->assertSame('American Scene', $case->attributes['series']);
        $this->assertSame('Oxford White', $case->attributes['color']);
        $this->assertSame(CardCondition::Mint->value, $case->attributes['condition']);
        $this->assertSame(QuarantineReason::LabelRusak->value, $case->attributes['reason']);

        $this->actingAs($owner)
            ->get(route('inventory.karantina'))
            ->assertOk()
            ->assertSee($case->case_no);
    }

    #[Test]
    public function case_numbers_increment_throughout_the_day(): void
    {
        $owner = $this->owner();
        $makeCase = function () use ($owner): void {
            $rack = Rack::factory()->create(['type' => RackType::Quarantine]);

            $this->actingAs($owner)
                ->post(route('inventory.karantina.store'), $this->registerData($rack))
                ->assertRedirect();
        };

        $makeCase();
        $makeCase();

        $numbers = QuarantineCase::query()->orderBy('id')->pluck('case_no')->all();
        $today = now()->format('Ymd');

        $this->assertSame(["QC-{$today}-001", "QC-{$today}-002"], $numbers);
    }

    #[Test]
    public function staff_cannot_register_a_case(): void
    {
        $staff = User::factory()->staff()->create();
        $rack = Rack::factory()->create(['type' => RackType::Quarantine]);

        $this->actingAs($staff)
            ->post(route('inventory.karantina.store'), $this->registerData($rack))
            ->assertForbidden();

        $this->assertDatabaseCount('quarantine_cases', 0);
    }

    #[Test]
    public function registration_requires_qty_rack_and_keyword(): void
    {
        $this->actingAs($this->owner())
            ->post(route('inventory.karantina.store'), ['qty' => 0])
            ->assertSessionHasErrors(['qty', 'rack_id', 'keyword']);
    }

    #[Test]
    public function assign_with_clean_variance_closes_the_case_and_pushes_a_movement(): void
    {
        $owner = $this->owner();
        $lot = StockLot::factory()->qty(4)->create();
        $case = QuarantineCase::factory()->create(['qty' => 2, 'opened_by' => $owner->id]);

        $this->actingAs($owner)
            ->post(route('inventory.karantina.assign', $case), [
                'lot_id' => $lot->id,
                'counted_c' => 2,
            ])
            ->assertRedirect();

        $case->refresh();
        $this->assertSame(QuarantineStatus::Closed, $case->status);
        $this->assertSame($lot->id, $case->assigned_lot_id);
        $this->assertSame(4, $case->S);
        $this->assertSame(2, $case->C);
        $this->assertSame(2, $case->Q);
        $this->assertSame(0, $case->V);
        $this->assertSame($owner->id, $case->decided_by);
        $this->assertNotNull($case->resolved_at);

        $this->assertDatabaseHas('stock_movements', [
            'lot_id' => $lot->id,
            'type' => MovementType::QuarantineOut->value,
            'ref_type' => QuarantineCase::class,
            'ref_id' => $case->id,
            'qty_delta' => 0,
        ]);
    }

    #[Test]
    public function assign_with_missing_units_is_blocked(): void
    {
        $owner = $this->owner();
        $lot = StockLot::factory()->qty(4)->create();
        $case = QuarantineCase::factory()->create(['qty' => 2, 'opened_by' => $owner->id]);

        $this->actingAs($owner)
            ->post(route('inventory.karantina.assign', $case), [
                'lot_id' => $lot->id,
                'counted_c' => 1,
            ])
            ->assertSessionHasErrors('counted_c');

        $this->assertSame(QuarantineStatus::Open, $case->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    #[Test]
    public function assign_with_surplus_units_is_blocked(): void
    {
        $owner = $this->owner();
        $lot = StockLot::factory()->qty(2)->create();
        $case = QuarantineCase::factory()->create(['qty' => 2, 'opened_by' => $owner->id]);

        $this->actingAs($owner)
            ->post(route('inventory.karantina.assign', $case), [
                'lot_id' => $lot->id,
                'counted_c' => 2,
            ])
            ->assertSessionHasErrors('counted_c');

        $this->assertSame(QuarantineStatus::Open, $case->fresh()->status);
    }

    #[Test]
    public function assign_to_a_sold_out_lot_is_rejected_by_validation(): void
    {
        $owner = $this->owner();
        $lot = StockLot::factory()->soldOut()->create();
        $case = QuarantineCase::factory()->create(['qty' => 2, 'opened_by' => $owner->id]);

        $this->actingAs($owner)
            ->post(route('inventory.karantina.assign', $case), [
                'lot_id' => $lot->id,
                'counted_c' => 2,
            ])
            ->assertSessionHasErrors('lot_id');
    }

    #[Test]
    public function ambiguous_lots_require_explicit_confirmation(): void
    {
        $owner = $this->owner();
        $product = Product::factory()->create();
        $first = Consignor::factory()->create();
        $second = Consignor::factory()->create();

        $lotA = StockLot::factory()->ownedBy($first)->qty(4)->create([
            'product_id' => $product->id,
            'card_condition' => CardCondition::Mint,
        ]);
        StockLot::factory()->ownedBy($second)->qty(4)->create([
            'product_id' => $product->id,
            'card_condition' => CardCondition::Mint,
        ]);
        $case = QuarantineCase::factory()->create(['qty' => 2, 'opened_by' => $owner->id]);

        $this->actingAs($owner)
            ->post(route('inventory.karantina.assign', $case), [
                'lot_id' => $lotA->id,
                'counted_c' => 2,
            ])
            ->assertSessionHasErrors('confirm');

        $this->assertSame(QuarantineStatus::Open, $case->fresh()->status);

        $this->actingAs($owner)
            ->post(route('inventory.karantina.assign', $case), [
                'lot_id' => $lotA->id,
                'counted_c' => 2,
                'confirm' => '1',
            ])
            ->assertRedirect();

        $this->assertSame(QuarantineStatus::Closed, $case->fresh()->status);
    }

    #[Test]
    public function escalate_marks_the_case_and_immediate_confirmation_is_refused(): void
    {
        $owner = $this->owner();
        $case = QuarantineCase::factory()->create(['opened_by' => $owner->id]);

        $this->actingAs($owner)
            ->post(route('inventory.karantina.eskalasi', $case), ['note' => 'Butuh bukti tambahan'])
            ->assertRedirect();

        $case->refresh();
        $this->assertSame(QuarantineStatus::Escalated, $case->status);
        $this->assertSame($owner->id, $case->decided_by);
        $this->assertSame('ESCALATED', $case->evidence[0]['action']);
        $this->assertSame('Butuh bukti tambahan', $case->evidence[0]['note']);

        $this->actingAs($owner)
            ->post(route('inventory.karantina.eskalasi', $case), ['note' => 'ditolak'])
            ->assertSessionHasErrors('note');
    }

    #[Test]
    public function write_off_closes_the_case_without_a_movement(): void
    {
        $owner = $this->owner();
        $case = QuarantineCase::factory()->create(['opened_by' => $owner->id]);

        $this->actingAs($owner)
            ->post(route('inventory.karantina.write-off', $case), ['note' => 'Barang tidak layak jual'])
            ->assertRedirect();

        $case->refresh();
        $this->assertSame(QuarantineStatus::Closed, $case->status);
        $this->assertSame('WRITE_OFF', $case->evidence[0]['action']);
        $this->assertSame('Barang tidak layak jual', $case->evidence[0]['note']);
        $this->assertSame($owner->id, $case->decided_by);
        $this->assertNotNull($case->resolved_at);
        $this->assertNull($case->assigned_lot_id);
    }

    #[Test]
    public function a_closed_case_cannot_be_assigned(): void
    {
        $owner = $this->owner();
        $lot = StockLot::factory()->qty(4)->create();
        $closed = QuarantineCase::factory()->closed()->create(['qty' => 2]);

        $this->actingAs($owner)
            ->post(route('inventory.karantina.assign', $closed), [
                'lot_id' => $lot->id,
                'counted_c' => 2,
            ])
            ->assertSessionHasErrors('case');
    }

    #[Test]
    public function pretend_lot_lookup_prefills_and_lists_candidates_on_the_resolution_view(): void
    {
        $owner = $this->owner();
        $product = Product::factory()->create(['name' => 'Ford Mustang GT']);
        $lot = StockLot::factory()->qty(3)->create([
            'product_id' => $product->id,
            'card_condition' => CardCondition::Mint,
        ]);
        $case = QuarantineCase::factory()->create([
            'qty' => 1,
            'opened_by' => $owner->id,
            'attributes' => ['model' => 'Ford Mustang GT', 'condition' => CardCondition::Mint->value],
        ]);

        $html = $this->actingAs($owner)
            ->get(route('inventory.karantina', ['case' => $case->case_no]))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('Ford Mustang GT', $html);
        $this->assertStringContainsString($lot->sku, $html);
        $this->assertStringContainsString('Assign', $html);
    }
}
