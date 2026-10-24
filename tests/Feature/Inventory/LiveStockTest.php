<?php

namespace Tests\Feature\Inventory;

use App\Enums\AuditAction;
use App\Enums\MovementType;
use App\Enums\OwnerType;
use App\Http\Requests\Inventory\PindahRakBulkRequest;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductSeries;
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

    private function consignLot(Rack $rack, int $qty, string $sku, ?int $productId = null): StockLot
    {
        $state = [
            'sku' => $sku,
            'rack_id' => $rack->id,
            'qty_on_hand' => $qty,
            'qty_received' => $qty,
        ];

        // Produk bawaan factory cukup untuk tes lain; ikat ke produk tertentu
        // hanya bila tes membutuhkannya, supaya perilaku pemanggil lama tidak
        // berubah.
        if ($productId !== null) {
            $state['product_id'] = $productId;
        }

        return StockLot::factory()
            ->state($state)
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
     * Katalog lama masuk tanpa warna, dan tahun hanya terisi sebagian. Dulu
     * baris seperti ini menggagalkan seluruh halaman (TypeError di filter sel
     * produk) begitu pagination membukanya -- halaman 1 lolos hanya karena
     * kebetulan semua produknya berwarna.
     */
    #[Test]
    public function a_product_without_a_color_or_year_still_renders(): void
    {
        $owner = User::factory()->owner()->create();
        $product = Product::factory()->create([
            'name' => 'HW Porsche 911',
            'color' => null,
            'year' => null,
        ]);

        $this->consignLot($this->rack(), 2, 'CN01-HW-001', $product->id);

        $html = $this->actingAs($owner)
            ->get(route('inventory.live-stock'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('HW Porsche 911', $html);
    }

    #[Test]
    public function a_product_with_a_color_and_year_keeps_both_in_its_detail_line(): void
    {
        $owner = User::factory()->owner()->create();
        $product = Product::factory()->create([
            'name' => 'HW Twin Mill',
            'color' => 'Red',
            'year' => 1998,
        ]);

        $this->consignLot($this->rack(), 2, 'CN01-HW-001', $product->id);

        $html = $this->actingAs($owner)
            ->get(route('inventory.live-stock'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('Red · 1998', $html);
    }

    #[Test]
    public function searching_filters_by_series_name_without_an_sql_error(): void
    {
        // Dulu pencarian apapun di halaman ini menghasilkan 'Unknown column
        // products.series.name': field pencarian 'product.series.name' (dua
        // tingkat relasi) diurai di titik pertama, lalu sisanya diteruskan ke
        // whereRaw seolah-olah itu kolom pada tabel products.
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::factory()->create(['name' => 'Car Culture', 'code' => 'CC']);
        $product = Product::factory()->create(['series_id' => $series->id, 'name' => 'Mazda RX-7']);
        $matching = $this->consignLot($this->rack(), 2, 'CN01-HW-001', $product->id);
        $other = $this->consignLot($this->rack(), 1, 'CN01-HW-002');

        $html = $this->actingAs($owner)
            ->get(route('inventory.live-stock', ['q' => 'car culture']))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('CN01-HW-001', $html);
        $this->assertStringNotContainsString('CN01-HW-002', $html);

        $none = $this->actingAs($owner)
            ->get(route('inventory.live-stock', ['q' => 'gundam']))
            ->assertOk()
            ->content();
        $this->assertStringNotContainsString('CN01-HW-001', $none);
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
    public function moving_many_lots_at_once_writes_one_movement_and_audit_per_lot(): void
    {
        $user = User::factory()->owner()->create();
        $from = $this->rack();
        $to = $this->rack();
        $first = $this->consignLot($from, 5, 'CN01-HW-001');
        $second = $this->consignLot($from, 2, 'CN01-HW-002');
        $third = $this->ownLot($from, 1, 'OW00-HW-003');

        $response = $this->actingAs($user)
            ->from(route('inventory.live-stock'))
            ->post(route('inventory.live-stock.rak.bulk'), [
                'lot_ids' => [$first->id, $second->id, $third->id],
                'rack_id' => $to->id,
                'reason' => 'penataan ulang',
            ]);

        $response->assertRedirect(route('inventory.live-stock'));

        foreach ([$first, $second, $third] as $lot) {
            $this->assertSame($to->id, $lot->refresh()->rack_id);
        }

        // Satu gerakan per lot, semuanya nol unit: rak berubah, saldo tidak.
        $movements = StockMovement::query()
            ->whereIn('lot_id', [$first->id, $second->id, $third->id])
            ->where('type', MovementType::Transfer)
            ->get();

        $this->assertCount(3, $movements);
        $this->assertSame([0], $movements->pluck('qty_delta')->unique()->values()->all());
        $this->assertSame(['penataan ulang'], $movements->pluck('reason')->unique()->values()->all());

        $this->assertSame(3, AuditLog::query()
            ->where('action', AuditAction::TransferRack->value)
            ->where('entity', StockLot::class)
            ->where('user_id', $user->id)
            ->count());

        $response->assertSessionHas('toast.type', 'success');
        $response->assertSessionHas('toast.message', '3 lot dipindah ke rak '.$to->code.'.');
    }

    #[Test]
    public function lots_already_in_the_target_rack_or_with_no_stock_are_skipped_and_reported(): void
    {
        $user = User::factory()->owner()->create();
        $from = $this->rack();
        $to = $this->rack();
        $movable = $this->consignLot($from, 5, 'CN01-HW-001');
        $empty = $this->consignLot($from, 0, 'CN01-HW-002');
        $alreadyThere = $this->consignLot($to, 4, 'CN01-HW-003');

        $response = $this->actingAs($user)
            ->from(route('inventory.live-stock'))
            ->post(route('inventory.live-stock.rak.bulk'), [
                'lot_ids' => [$movable->id, $empty->id, $alreadyThere->id],
                'rack_id' => $to->id,
            ]);

        $response->assertRedirect(route('inventory.live-stock'));

        $this->assertSame($to->id, $movable->refresh()->rack_id);
        $this->assertSame($from->id, $empty->refresh()->rack_id);
        $this->assertSame($to->id, $alreadyThere->refresh()->rack_id);

        // Baris yang dilewati tidak boleh meninggalkan gerakan atau audit
        // palsu: yang tercatat hanya lot yang benar-benar berpindah.
        $this->assertSame(1, StockMovement::query()->where('type', MovementType::Transfer)->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::TransferRack->value)->count());

        $response->assertSessionHas('toast.type', 'success');
        $response->assertSessionHas(
            'toast.message',
            '1 lot dipindah ke rak '.$to->code.'. 2 lot dilewati (sudah di rak tujuan atau stoknya kosong).',
        );
    }

    #[Test]
    public function a_bulk_move_to_an_inactive_rack_moves_nothing(): void
    {
        $user = User::factory()->owner()->create();
        $from = $this->rack();
        $lot = $this->consignLot($from, 5, 'CN01-HW-001');

        $this->actingAs($user)
            ->post(route('inventory.live-stock.rak.bulk'), [
                'lot_ids' => [$lot->id],
                'rack_id' => $this->rack(active: false)->id,
            ])
            ->assertSessionHasErrors('rack_id');

        $this->assertSame($from->id, $lot->refresh()->rack_id);
        $this->assertSame(0, StockMovement::query()->where('type', MovementType::Transfer)->count());
    }

    #[Test]
    public function a_bulk_move_needs_at_least_one_lot(): void
    {
        $user = User::factory()->owner()->create();
        $to = $this->rack();

        $this->actingAs($user)
            ->post(route('inventory.live-stock.rak.bulk'), ['rack_id' => $to->id])
            ->assertSessionHasErrors(['lot_ids' => 'Pilih dulu lot yang mau dipindahkan.']);

        $this->assertSame(0, StockMovement::query()->count());
    }

    #[Test]
    public function a_bulk_move_is_capped_at_the_request_limit(): void
    {
        $user = User::factory()->owner()->create();
        $to = $this->rack();

        // Id-nya tidak perlu ada: yang diuji pagar jumlahnya, dan pesannya
        // membedakan galat ini dari "lot tidak ditemukan" per baris.
        $this->actingAs($user)
            ->post(route('inventory.live-stock.rak.bulk'), [
                'lot_ids' => range(1, PindahRakBulkRequest::MAX_LOTS + 1),
                'rack_id' => $to->id,
            ])
            ->assertSessionHasErrors([
                'lot_ids' => 'Satu kali pindah maksimal '.PindahRakBulkRequest::MAX_LOTS.' lot. Pecah menjadi beberapa kirim.',
            ]);
    }

    #[Test]
    public function the_page_offers_a_checkbox_bound_to_the_bulk_form(): void
    {
        $owner = User::factory()->owner()->create();
        $lot = $this->consignLot($this->rack(), 2, 'CN01-HW-001');

        $html = $this->actingAs($owner)
            ->get(route('inventory.live-stock'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('form="pindah-rak-bulk"', $html);
        $this->assertStringContainsString('name="lot_ids[]"', $html);
        $this->assertStringContainsString('value="'.$lot->id.'"', $html);
        $this->assertStringContainsString('id="pindah-rak-bulk"', $html);
        $this->assertStringContainsString('action="'.route('inventory.live-stock.rak.bulk').'"', $html);
        $this->assertStringContainsString('Pilih yang tampil', $html);
    }

    #[Test]
    public function staff_cannot_move_lots_in_bulk(): void
    {
        $staff = User::factory()->staff()->create();
        $from = $this->rack();
        $lot = $this->consignLot($from, 2, 'CN01-HW-001');

        $this->actingAs($staff)
            ->post(route('inventory.live-stock.rak.bulk'), [
                'lot_ids' => [$lot->id],
                'rack_id' => $this->rack()->id,
            ])
            ->assertForbidden();

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

    #[Test]
    public function moving_a_lot_that_does_not_fit_the_target_rack_is_rejected(): void
    {
        $user = User::factory()->owner()->create();
        $from = $this->rack();
        $to = Rack::factory()->create(['capacity' => 4]);
        $lot = $this->consignLot($from, 5, 'CN01-HW-001');

        $this->actingAs($user)
            ->from(route('inventory.live-stock'))
            ->patch(route('inventory.live-stock.rak', $lot), ['rack_id' => $to->id])
            ->assertSessionHasErrors('rack_id');

        $this->assertSame($from->id, $lot->refresh()->rack_id);
        $this->assertSame(0, StockMovement::query()->where('type', MovementType::Transfer)->count());
    }

    #[Test]
    public function a_bulk_move_fills_the_target_rack_and_reports_lots_that_do_not_fit(): void
    {
        $user = User::factory()->owner()->create();
        $from = $this->rack();
        $to = Rack::factory()->create(['capacity' => 5]);
        $this->consignLot($from, 3, 'CN01-HW-001');
        $this->consignLot($from, 4, 'CN01-HW-002');

        $response = $this->actingAs($user)
            ->from(route('inventory.live-stock'))
            ->post(route('inventory.live-stock.rak.bulk'), [
                'lot_ids' => StockLot::query()->orderBy('id')->pluck('id')->all(),
                'rack_id' => $to->id,
            ]);

        $response->assertRedirect(route('inventory.live-stock'));
        $response->assertSessionHas('toast.type', 'success');
        $response->assertSessionHas(
            'toast.message',
            '1 lot dipindah ke rak '.$to->code.'. 1 lot dilewati (tidak muat di rak tujuan).',
        );

        // Yang pindah saja yang mengisi rak, dan totalnya tidak melewati kapasitas.
        $this->assertSame(1, StockLot::query()->where('rack_id', $to->id)->count());
        $this->assertLessThanOrEqual(5, (int) StockLot::query()->where('rack_id', $to->id)->sum('qty_on_hand'));
        $this->assertSame(1, StockMovement::query()->where('type', MovementType::Transfer)->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::TransferRack->value)->count());
    }

    #[Test]
    public function a_bulk_move_reports_when_nothing_fits_at_all(): void
    {
        $user = User::factory()->owner()->create();
        $from = $this->rack();
        $to = Rack::factory()->create(['capacity' => 2]);
        $lot = $this->consignLot($from, 3, 'CN01-HW-001');

        $response = $this->actingAs($user)
            ->from(route('inventory.live-stock'))
            ->post(route('inventory.live-stock.rak.bulk'), [
                'lot_ids' => [$lot->id],
                'rack_id' => $to->id,
            ]);

        $response->assertSessionHas('toast.type', 'warning');
        $response->assertSessionHas('toast.message', 'Tidak ada lot yang dipindah: tidak muat di rak tujuan.');

        $this->assertSame($from->id, $lot->refresh()->rack_id);
        $this->assertSame(0, StockMovement::query()->where('type', MovementType::Transfer)->count());
    }

    #[Test]
    public function a_bulk_move_into_a_rack_without_capacity_has_no_limit(): void
    {
        $user = User::factory()->owner()->create();
        $from = $this->rack();
        $to = Rack::factory()->create(['capacity' => null]);
        $this->consignLot($from, 100, 'CN01-HW-001');
        $this->consignLot($from, 100, 'CN01-HW-002');

        $this->actingAs($user)
            ->from(route('inventory.live-stock'))
            ->post(route('inventory.live-stock.rak.bulk'), [
                'lot_ids' => StockLot::query()->orderBy('id')->pluck('id')->all(),
                'rack_id' => $to->id,
            ])
            ->assertSessionHas('toast.message', '2 lot dipindah ke rak '.$to->code.'.');

        $this->assertSame(200, (int) StockLot::query()->where('rack_id', $to->id)->sum('qty_on_hand'));
    }
}
