<?php

namespace Tests\Feature\Inventory;

use App\Enums\AuditAction;
use App\Enums\MovementType;
use App\Enums\OwnerType;
use App\Models\Rack;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LiveStockTest extends TestCase
{
    use RefreshDatabase;

    private function rack(bool $active = true): Rack
    {
        return Rack::factory()->create(['is_active' => $active]);
    }

    private function consignLot(Rack $rack, int $qty, string $sku): StockLot
    {
        return StockLot::factory()
            ->state([
                'sku' => $sku,
                'rack_id' => $rack->id,
                'qty_on_hand' => $qty,
                'qty_received' => $qty,
            ])
            ->create();
    }

    private function ownLot(Rack $rack, int $qty, string $sku): StockLot
    {
        return StockLot::factory()
            ->own()
            ->state([
                'sku' => $sku,
                'rack_id' => $rack->id,
                'qty_on_hand' => $qty,
                'qty_received' => $qty,
            ])
            ->create();
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $this->get(route('inventory.live-stock'))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_page_shows_stock_that_exists_in_the_database(): void
    {
        $owner = User::factory()->owner()->create();
        $ownRack = $this->rack();
        $consignRack = $this->rack();

        $this->ownLot($ownRack, 5, 'OW00-HW-001');
        $this->consignLot($consignRack, 2, 'CN01-HW-002');

        $html = $this->actingAs($owner)
            ->get(route('inventory.live-stock'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('OW00-HW-001', $html);
        $this->assertStringContainsString('CN01-HW-002', $html);
        $this->assertStringContainsString('Total Unit', $html);
        $this->assertStringContainsString($ownRack->code, $html);
        $this->assertStringContainsString($consignRack->code, $html);

        // Pintu ke tindakan berikutnya: dialog pindah rak dan tombol yang
        // membukanya harus sudah ada di halaman, bukan menyusul belakangan.
        $this->assertStringContainsString('pindah-rak', $html);
        $this->assertStringContainsString('Kartu', $html);
    }

    /**
     * Halaman live stock kini di balik middleware Owner; staff ditolak 403.
     */
    #[Test]
    public function staff_cannot_open_the_live_stock_page(): void
    {
        $staff = User::factory()->staff()->create();

        $this->ownLot($this->rack(), 5, 'OW00-HW-001');
        $this->consignLot($this->rack(), 1, 'CN01-HW-002');

        $this->actingAs($staff)
            ->get(route('inventory.live-stock'))
            ->assertForbidden();
    }

    #[Test]
    public function the_owner_summary_shows_the_value_that_staff_never_sees(): void
    {
        $owner = User::factory()->owner()->create();

        // Satu lot pribadi: 2 unit × HPP 30.000 = 60.000.
        StockLot::factory()->own()->state([
            'sku' => 'OW00-HW-001',
            'qty_on_hand' => 2,
            'qty_received' => 2,
            'cost_price' => 30_000,
            'list_price' => 100_000,
        ])->create();

        $html = $this->actingAs($owner)
            ->get(route('inventory.live-stock'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('Nilai Stok', $html);
        $this->assertStringContainsString('Rp60.000', $html);
    }

    #[Test]
    public function the_ownership_filter_narrows_the_rows(): void
    {
        $owner = User::factory()->owner()->create();

        $this->ownLot($this->rack(), 3, 'OW00-HW-001');
        $this->consignLot($this->rack(), 3, 'CN01-HW-002');

        $response = $this->actingAs($owner)->get(route('inventory.live-stock', ['pemilik' => 'PRIBADI']));

        $response->assertOk()->assertSee('OW00-HW-001')->assertDontSee('CN01-HW-002');
    }

    #[Test]
    public function the_rack_and_low_stock_filters_narrow_the_rows(): void
    {
        $owner = User::factory()->owner()->create();
        $rackA = $this->rack();
        $rackB = $this->rack();

        $this->consignLot($rackA, 4, 'CN01-HW-001');
        $this->consignLot($rackB, 1, 'CN01-HW-002');

        $this->actingAs($owner)
            ->get(route('inventory.live-stock', ['rak' => $rackA->id]))
            ->assertOk()
            ->assertSee('CN01-HW-001')
            ->assertDontSee('CN01-HW-002');

        // Menipis = qty ≤ 1, jadi hanya lot berisi satu unit yang tersisa.
        $this->actingAs($owner)
            ->get(route('inventory.live-stock', ['menipis' => 1]))
            ->assertOk()
            ->assertSee('CN01-HW-002')
            ->assertDontSee('CN01-HW-001');
    }

    #[Test]
    public function an_unknown_filter_value_does_not_turn_the_table_into_a_wall_of_nothing(): void
    {
        $owner = User::factory()->owner()->create();

        $this->consignLot($this->rack(), 3, 'CN01-HW-001');

        // Enum yang tidak dikenal diabaikan, bukan menjadi syarat yang tidak
        // pernah cocok: yang gagal salah ketik filter, bukan tabelnya kosong.
        $this->actingAs($owner)
            ->get(route('inventory.live-stock', ['kondisi' => 'sempurna', 'status' => 'hilang']))
            ->assertOk()
            ->assertSee('CN01-HW-001');
    }

    #[Test]
    public function the_stock_card_lists_every_movement_of_the_lot(): void
    {
        $owner = User::factory()->owner()->create();
        $lot = $this->consignLot($this->rack(), 5, 'CN01-HW-001');

        StockMovement::factory()->create([
            'lot_id' => $lot->id,
            'type' => MovementType::Sale,
            'qty_delta' => -1,
            'balance_after' => 4,
            'reason' => 'Terjual lewat POS',
        ]);

        $html = $this->actingAs($owner)
            ->get(route('inventory.kartu-stok', $lot))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('CN01-HW-001', $html);
        $this->assertStringContainsString('Terjual lewat POS', $html);
        $this->assertStringContainsString('Saldo', $html);
    }

    #[Test]
    public function the_stock_card_of_an_unknown_lot_is_not_found(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get(route('inventory.kartu-stok', ['lot' => 999_999]))
            ->assertNotFound();
    }

    #[Test]
    public function moving_a_lot_changes_its_rack_and_writes_a_zero_movement(): void
    {
        $user = User::factory()->owner()->create();
        $from = $this->rack();
        $to = $this->rack();
        $lot = $this->consignLot($from, 5, 'CN01-HW-001');

        $this->actingAs($user)
            ->from(route('inventory.live-stock'))
            ->patch(route('inventory.live-stock.rak', $lot), [
                'rack_id' => $to->id,
                'reason' => 'penataan ulang',
            ])
            ->assertRedirect(route('inventory.live-stock'))
            ->assertSessionHas('toast');

        $lot->refresh();

        $this->assertSame($to->id, $lot->rack_id);
        $this->assertSame(5, $lot->qty_on_hand);

        $movement = StockMovement::query()
            ->where('lot_id', $lot->id)
            ->where('type', MovementType::Transfer)
            ->firstOrFail();

        $this->assertSame(0, $movement->qty_delta);
        $this->assertSame(5, $movement->balance_after);
        $this->assertSame('penataan ulang', $movement->reason);
        $this->assertSame($user->id, $movement->actor_id);

        // Keputusan pindah rak harus tertulis di jejak audit, bukan hanya di
        // gerakan stok: yang satu menjawab "berapa saldonya", yang lain
        // menjawab "siapa yang memindahkan". Aksinya memakai kode enum --
        // `TRANSFER_RACK` -- persis seperti semua aksi audit lain yang lahir
        // dari `AuditAction`.
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::TransferRack->value,
            'entity' => StockLot::class,
            'entity_id' => $lot->id,
            'user_id' => $user->id,
        ]);

    }

    #[Test]
    public function moving_a_lot_to_the_same_or_an_inactive_rack_is_refused(): void
    {
        $user = User::factory()->owner()->create();
        $from = $this->rack();
        $lot = $this->consignLot($from, 5, 'CN01-HW-001');

        $this->actingAs($user)
            ->patch(route('inventory.live-stock.rak', $lot), ['rack_id' => $from->id])
            ->assertSessionHasErrors('rack_id');

        $inactive = $this->rack(active: false);

        $this->actingAs($user)
            ->patch(route('inventory.live-stock.rak', $lot), ['rack_id' => $inactive->id])
            ->assertSessionHasErrors('rack_id');

        $this->assertSame($from->id, $lot->refresh()->rack_id);
        $this->assertSame(0, StockMovement::query()->where('lot_id', $lot->id)->count());
    }

    #[Test]
    public function an_empty_lot_cannot_be_moved(): void
    {
        $user = User::factory()->owner()->create();
        $from = $this->rack();
        $to = $this->rack();
        $lot = $this->consignLot($from, 0, 'CN01-HW-001');

        // Rak tujuan valid, jadi galatnya datang dari service: stok yang sudah
        // nol memang tidak bisa berpindah secara fisik.
        $this->actingAs($user)
            ->patch(route('inventory.live-stock.rak', $lot), ['rack_id' => $to->id])
            ->assertSessionHasErrors('rack_id');

        $this->assertSame($from->id, $lot->refresh()->rack_id);
    }

    #[Test]
    public function the_export_follows_the_active_filters(): void
    {
        $owner = User::factory()->owner()->create();

        $this->ownLot($this->rack(), 5, 'OW00-HW-001');
        $this->consignLot($this->rack(), 2, 'CN01-HW-002');

        $content = $this->actingAs($owner)
            ->get(route('inventory.live-stock.ekspor', ['pemilik' => 'PRIBADI']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('OW00-HW-001', $content);
        $this->assertStringNotContainsString('CN01-HW-002', $content);
        $this->assertStringContainsString('HPP', $content);
    }

    /**
     * Ekspor live stock kini di balik middleware Owner; staff ditolak 403.
     */
    #[Test]
    public function staff_cannot_export_the_stock(): void
    {
        $staff = User::factory()->staff()->create();

        $this->consignLot($this->rack(), 2, 'CN01-HW-001');

        $this->actingAs($staff)
            ->get(route('inventory.live-stock.ekspor'))
            ->assertForbidden();
    }

    #[Test]
    public function the_owner_type_is_reported_the_way_the_table_reports_it(): void
    {
        $owner = User::factory()->owner()->create();
        $lot = $this->consignLot($this->rack(), 2, 'CN01-HW-001');

        $this->assertSame(OwnerType::Consign, $lot->owner_type);

        $content = $this->actingAs($owner)
            ->get(route('inventory.live-stock.ekspor'))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('TITIP', $content);
    }
}
