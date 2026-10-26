<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\AdjustmentReason;
use App\Enums\AuditAction;
use App\Enums\LotStatus;
use App\Enums\MovementType;
use App\Models\AuditLog;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private function lot(int $qty = 5, LotStatus $status = LotStatus::Available, string $sku = 'OW00-HW-001'): StockLot
    {
        return StockLot::factory()
            ->own()
            ->state([
                'sku' => $sku,
                'qty_on_hand' => $qty,
                'qty_received' => $qty,
                'status' => $status,
            ])
            ->create();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function reduce(User $actor, StockLot $lot, int $qty, int $expectedQty, array $extra = []): TestResponse
    {
        return $this->actingAs($actor)
            ->withHeader('X-Device-Id', 'TEST-DEVICE')
            ->from(route('inventory.live-stock'))
            ->post(route('inventory.live-stock.kurangi', $lot), [
                'qty' => $qty,
                'expected_qty' => $expectedQty,
                ...$extra,
            ]);
    }

    #[Test]
    public function a_miscount_correction_reduces_stock_and_writes_its_trail(): void
    {
        $owner = User::factory()->owner()->create();
        $lot = $this->lot(5);

        $this->reduce($owner, $lot, 2, 5)
            ->assertRedirect(route('inventory.live-stock'))
            ->assertSessionHas('toast');

        $lot->refresh();

        $this->assertSame(3, $lot->qty_on_hand);
        $this->assertSame(LotStatus::Available, $lot->status);

        $movement = StockMovement::query()
            ->where('lot_id', $lot->id)
            ->sole();

        $this->assertSame(MovementType::AdjMinus, $movement->type);
        $this->assertSame(-2, $movement->qty_delta);
        $this->assertSame(3, $movement->balance_after);
        $this->assertSame(AdjustmentReason::Miscount->value, $movement->reason);
        $this->assertSame($owner->id, $movement->actor_id);
        $this->assertSame('TEST-DEVICE', $movement->device_id);

        $audit = AuditLog::query()
            ->where('action', AuditAction::StockAdjustment->value)
            ->sole();

        $this->assertSame(StockLot::class, $audit->entity);
        $this->assertSame($lot->id, $audit->entity_id);
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertSame(['qty_on_hand' => 5, 'status' => LotStatus::Available->value], $audit->before);
        $this->assertSame(['qty_on_hand' => 3, 'status' => LotStatus::Available->value], $audit->after);
        $this->assertSame(AdjustmentReason::Miscount->value, $audit->reason);
        $this->assertSame('Penyesuaian Stok Manual', $audit->action_label);
        $this->assertSame('warning', $audit->action_type);
    }

    #[Test]
    public function correcting_the_last_units_marks_the_lot_as_sold_out(): void
    {
        $owner = User::factory()->owner()->create();
        $lot = $this->lot(2);

        $this->reduce($owner, $lot, 2, 2)->assertSessionHas('toast');

        $lot->refresh();

        $this->assertSame(0, $lot->qty_on_hand);
        $this->assertSame(LotStatus::SoldOut, $lot->status);
        $this->assertNull($lot->last_sold_at);

        $this->assertDatabaseHas('stock_movements', [
            'lot_id' => $lot->id,
            'type' => MovementType::AdjMinus->value,
            'qty_delta' => -2,
            'balance_after' => 0,
        ]);
    }

    #[Test]
    public function a_consignment_miscount_does_not_touch_the_consignor_ledger(): void
    {
        $owner = User::factory()->owner()->create();
        $lot = StockLot::factory()->qty(5)->create(['sku' => 'CN01-HW-001']);

        $this->reduce($owner, $lot, 1, 5, ['reason' => AdjustmentReason::Lost->value])
            ->assertSessionHas('toast');

        $this->assertSame(4, $lot->refresh()->qty_on_hand);
        $this->assertDatabaseCount('consignor_ledger', 0);

        // Alasan ditetapkan server: field `reason` dari peramban tidak bisa
        // mengubah kasus salah hitung menjadi kerugian yang ditanggung penitip.
        $this->assertDatabaseHas('stock_movements', [
            'lot_id' => $lot->id,
            'reason' => AdjustmentReason::Miscount->value,
        ]);
    }

    #[Test]
    public function a_stale_expected_quantity_is_refused(): void
    {
        $owner = User::factory()->owner()->create();
        $lot = $this->lot(5);

        $this->reduce($owner, $lot, 1, 4)->assertSessionHasErrors('qty');

        $this->assertSame(5, $lot->refresh()->qty_on_hand);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[Test]
    public function the_reduction_cannot_exceed_the_stock_on_hand(): void
    {
        $owner = User::factory()->owner()->create();
        $lot = $this->lot(2);

        $this->reduce($owner, $lot, 3, 2)->assertSessionHasErrors('qty');

        $this->assertSame(2, $lot->refresh()->qty_on_hand);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    #[Test]
    public function zero_is_not_a_reduction(): void
    {
        $owner = User::factory()->owner()->create();
        $lot = $this->lot(5);

        $this->reduce($owner, $lot, 0, 5)->assertSessionHasErrors('qty');

        $this->assertSame(5, $lot->refresh()->qty_on_hand);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    #[Test]
    public function a_lot_outside_available_status_cannot_be_reduced(): void
    {
        $owner = User::factory()->owner()->create();
        $lot = $this->lot(5, LotStatus::Returned);

        $this->reduce($owner, $lot, 1, 5)->assertSessionHasErrors('qty');

        $this->assertSame(5, $lot->refresh()->qty_on_hand);
        $this->assertSame(LotStatus::Returned, $lot->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    #[Test]
    public function staff_cannot_reduce_stock(): void
    {
        $staff = User::factory()->staff()->create();
        $lot = $this->lot(5);

        $this->actingAs($staff)
            ->post(route('inventory.live-stock.kurangi', $lot), [
                'qty' => 1,
                'expected_qty' => 5,
            ])
            ->assertForbidden();

        $this->assertSame(5, $lot->refresh()->qty_on_hand);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $lot = $this->lot(5);

        $this->post(route('inventory.live-stock.kurangi', $lot), [
            'qty' => 1,
            'expected_qty' => 5,
        ])->assertRedirect(route('login'));

        $this->assertSame(5, $lot->refresh()->qty_on_hand);
    }

    #[Test]
    public function an_unknown_lot_is_not_found(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->post(route('inventory.live-stock.kurangi', ['lot' => 999_999]), [
                'qty' => 1,
                'expected_qty' => 5,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function the_live_stock_page_exposes_the_reduction_dialog(): void
    {
        $owner = User::factory()->owner()->create();
        $this->lot(3);

        $this->actingAs($owner)
            ->get(route('inventory.live-stock'))
            ->assertOk()
            ->assertSee('kurangi-stok')
            ->assertSee('Kurangi Stok');
    }

    #[Test]
    public function the_reduction_action_only_appears_for_lots_with_available_stock(): void
    {
        $sellable = Blade::render(
            '<x-inventory.lot-actions :row="$row" :data="[\'canTransfer\' => false]" />',
            ['row' => $this->lot(3)],
        );

        $this->assertStringContainsString('Kurangi', $sellable);
        $this->assertStringContainsString('live-stock:reduce', $sellable);

        $returned = Blade::render(
            '<x-inventory.lot-actions :row="$row" :data="[\'canTransfer\' => false]" />',
            ['row' => $this->lot(3, LotStatus::Returned, 'OW00-HW-002')],
        );

        $this->assertStringNotContainsString('Kurangi', $returned);
        $this->assertStringNotContainsString('live-stock:reduce', $returned);
    }
}
