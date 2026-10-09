<?php

namespace Tests\Feature;

use App\Enums\ConsignorStatus;
use App\Enums\Role;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\Rack;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MasterCrudTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guest_requires_authentication_for_master_areas(): void
    {
        $this->get('/master/penitip')->assertRedirect(route('login'));
        $this->get('/settings/pengguna-role')->assertRedirect(route('login'));
    }

    #[Test]
    public function owner_can_create_consignor_with_auto_code(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/master/penitip', [
            'name' => 'Budi Santoso',
            'wa_number' => '081234567890',
            'address' => 'Jl. Merdeka 1',
            'agreement_date' => '2026-01-01',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => '10',
            'discount_policy' => 'SHARED',
            'loss_liability' => 'CONSIGNOR',
            'settlement_cycle' => 'MONTHLY',
            'min_payout' => '500000',
            'bank_name' => 'BCA',
            'bank_account' => '1234567890',
            'bank_holder' => 'Budi',
        ])->assertRedirect(route('master.penitip'));

        $consignor = Consignor::where('name', 'Budi Santoso')->firstOrFail();
        $this->assertSame('CN01', $consignor->consignor_code);
        $this->assertSame(10.0, (float) $consignor->scheme_rate);
        $this->assertSame('BCA', $consignor->bank_name);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    /**
     * Pembuatan penitip kini di balik middleware Owner; staff ditolak 403.
     */
    #[Test]
    public function staff_cannot_create_consignor(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post('/master/penitip', [
            'name' => 'Siti Aminah',
            'wa_number' => '08999999999',
            'scheme_type' => 'NETT',
            'scheme_amount' => '20000',
            'bank_name' => 'BNI',
            'bank_account' => '999888777',
            'bank_holder' => 'Siti',
        ])->assertForbidden();
    }

    #[Test]
    public function owner_can_update_consignor(): void
    {
        $owner = User::factory()->owner()->create();
        $consignor = Consignor::create([
            'consignor_code' => 'CN01',
            'name' => 'Lama',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => 5,
        ]);

        $this->actingAs($owner)->put('/master/penitip/'.$consignor->id, [
            'name' => 'Baru',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => '12.5',
        ])->assertRedirect(route('master.penitip'));

        $consignor->refresh();
        $this->assertSame('Baru', $consignor->name);
        $this->assertSame(12.5, (float) $consignor->scheme_rate);
    }

    #[Test]
    public function staff_cannot_archive_consignor(): void
    {
        $staff = User::factory()->staff()->create();
        $consignor = Consignor::create([
            'consignor_code' => 'CN01',
            'name' => 'Penitip',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => 5,
        ]);

        $this->actingAs($staff)
            ->patch('/master/penitip/'.$consignor->id.'/archive')
            ->assertForbidden();

        $this->assertSame(ConsignorStatus::Active, $consignor->fresh()->status);
    }

    #[Test]
    public function owner_can_archive_and_restore_consignor(): void
    {
        $owner = User::factory()->owner()->create();
        $consignor = Consignor::create([
            'consignor_code' => 'CN01',
            'name' => 'Penitip',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => 5,
        ]);

        $this->actingAs($owner)->patch('/master/penitip/'.$consignor->id.'/archive');
        $this->assertSame(ConsignorStatus::Archived, $consignor->fresh()->status);

        $this->actingAs($owner)->patch('/master/penitip/'.$consignor->id.'/restore');
        $this->assertSame(ConsignorStatus::Active, $consignor->fresh()->status);
    }

    #[Test]
    public function owner_created_product_needs_no_review(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/master/katalog-produk', [
            'name' => 'Honda Civic 1998',
            'year' => '1998',
            'color' => 'Red',
            'default_list_price' => '75000',
            'tags' => 'asli, ejen',
        ])->assertRedirect(route('master.katalog-produk'));

        $product = Product::where('name', 'Honda Civic 1998')->firstOrFail();
        $this->assertFalse($product->needs_review);
        $this->assertSame(['asli', 'ejen'], $product->tags);
        $this->assertSame(75000, $product->default_list_price);
    }

    /**
     * Pembuatan produk kini di balik middleware Owner; staff ditolak 403.
     */
    #[Test]
    public function staff_cannot_create_product(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post('/master/katalog-produk', [
            'name' => 'Lambo Countach',
            'default_list_price' => '150000',
        ])->assertForbidden();
    }

    #[Test]
    public function duplicate_product_name_is_blocked(): void
    {
        $owner = User::factory()->owner()->create();

        $payload = ['name' => 'Mini GT', 'default_list_price' => '200000'];
        $this->actingAs($owner)->post('/master/katalog-produk', $payload)->assertRedirect(route('master.katalog-produk'));

        $this->actingAs($owner)
            ->from('/master/katalog-produk/create')
            ->post('/master/katalog-produk', ['name' => 'mini gt', 'default_list_price' => '300000'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('products', 1);
    }

    #[Test]
    public function owner_can_approve_product(): void
    {
        $owner = User::factory()->owner()->create();
        $product = Product::create(['name' => 'P1', 'default_list_price' => 100, 'needs_review' => true]);

        $this->actingAs($owner)->patch('/master/katalog-produk/'.$product->id.'/approve')->assertRedirect();

        $this->assertFalse($product->fresh()->needs_review);
    }

    #[Test]
    public function staff_cannot_edit_or_delete_product(): void
    {
        $staff = User::factory()->staff()->create();
        $product = Product::create(['name' => 'P1', 'default_list_price' => 100]);

        $this->actingAs($staff)->get('/master/katalog-produk/'.$product->id.'/edit')->assertForbidden();
        $this->actingAs($staff)->delete('/master/katalog-produk/'.$product->id)->assertForbidden();
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    #[Test]
    public function product_delete_is_blocked_when_lots_exist(): void
    {
        $owner = User::factory()->owner()->create();
        [$product] = $this->productWithLot();

        $this->actingAs($owner)->from('/master/katalog-produk')->delete('/master/katalog-produk/'.$product->id)->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    #[Test]
    public function owner_can_delete_orphan_product(): void
    {
        $owner = User::factory()->owner()->create();
        $product = Product::create(['name' => 'Raksasa', 'default_list_price' => 400]);

        $this->actingAs($owner)->from('/master/katalog-produk')->delete('/master/katalog-produk/'.$product->id)->assertRedirect();
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    #[Test]
    public function staff_cannot_create_rack(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get('/master/lokasi-rak/create')->assertForbidden();
        $this->actingAs($staff)->post('/master/lokasi-rak', ['code' => 'A-1'])->assertForbidden();
        $this->assertDatabaseCount('racks', 0);
    }

    #[Test]
    public function owner_creates_rack_and_code_is_uppercased(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/master/lokasi-rak', [
            'code' => 'a-01',
            'zone' => 'A',
            'type' => 'DISPLAY',
            'capacity' => '120',
            'is_active' => '1',
        ])->assertRedirect(route('master.lokasi-rak'));

        $rack = Rack::where('code', 'A-01')->firstOrFail();
        $this->assertSame('DISPLAY', $rack->type->value);
        $this->assertTrue($rack->is_active);
        $this->assertDatabaseHas('audit_logs', ['entity' => 'Rack']);
    }

    #[Test]
    public function owner_can_update_rack(): void
    {
        $owner = User::factory()->owner()->create();
        $rack = Rack::create(['code' => 'A-01', 'zone' => 'A', 'type' => 'STORAGE', 'capacity' => 50, 'is_active' => true]);

        $this->actingAs($owner)->put('/master/lokasi-rak/'.$rack->id, [
            'code' => 'A-02',
            'zone' => 'B',
            'type' => 'STORAGE',
            'capacity' => '80',
            'is_active' => '0',
        ])->assertRedirect(route('master.lokasi-rak'));

        $rack->refresh();
        $this->assertSame('A-02', $rack->code);
        $this->assertSame('B', $rack->zone);
        $this->assertFalse($rack->is_active);
    }

    #[Test]
    public function rack_toggle_is_blocked_when_it_still_has_stock(): void
    {
        $owner = User::factory()->owner()->create();
        [$product, $rack] = $this->productWithLot();

        $this->actingAs($owner)->from('/master/lokasi-rak')->patch('/master/lokasi-rak/'.$rack->id.'/toggle')->assertRedirect();

        $this->assertTrue($rack->fresh()->is_active);
    }

    #[Test]
    public function owner_can_toggle_empty_rack(): void
    {
        $owner = User::factory()->owner()->create();
        $rack = Rack::create(['code' => 'Q-1', 'type' => 'STORAGE', 'is_active' => true]);

        $this->actingAs($owner)->patch('/master/lokasi-rak/'.$rack->id.'/toggle')->assertRedirect();

        $this->assertFalse($rack->fresh()->is_active);
    }

    #[Test]
    public function staff_cannot_create_or_manage_users(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get('/settings/pengguna-role/create')->assertForbidden();
        $this->actingAs($staff)->post('/settings/pengguna-role', [
            'name' => 'X', 'username' => 'x', 'role' => 'STAFF', 'password' => 'secret123',
        ])->assertForbidden();
        $this->assertDatabaseCount('users', 1);
    }

    #[Test]
    public function owner_can_create_staff_user_with_hashed_password(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/settings/pengguna-role', [
            'name' => 'Kasir Pagi',
            'username' => 'kasir.pagi',
            'email' => 'kasir@toko.com',
            'role' => 'STAFF',
            'is_active' => '1',
            'password' => 'secret123',
            'pin' => '123456',
        ])->assertRedirect(route('setting.pengguna'));

        $user = User::where('username', 'kasir.pagi')->firstOrFail();
        $this->assertSame(Role::Staff, $user->role);
        $this->assertNotSame('secret123', $user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->is_active);
    }

    #[Test]
    public function owner_cannot_deactivate_own_account(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->from('/settings/pengguna-role')->patch('/settings/pengguna-role/'.$owner->id.'/toggle')->assertRedirect();

        $this->assertTrue($owner->fresh()->is_active);
    }

    #[Test]
    public function owner_cannot_demote_self(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->from('/settings/pengguna-role/'.$owner->id.'/edit')
            ->put('/settings/pengguna-role/'.$owner->id, [
                'name' => $owner->name,
                'username' => $owner->username,
                'role' => 'STAFF',
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(Role::Owner, $owner->fresh()->role);
    }

    #[Test]
    public function owner_can_toggle_other_user(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($owner)->patch('/settings/pengguna-role/'.$staff->id.'/toggle')->assertRedirect();
        $this->assertFalse($staff->fresh()->is_active);
    }

    #[Test]
    public function staff_cannot_manage_series(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->postJson('/master/seri', ['name' => 'Hot Wheels'])->assertForbidden();
        $this->assertDatabaseCount('product_series', 0);
    }

    #[Test]
    public function owner_can_create_series(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->postJson('/master/seri', ['name' => 'Hot Wheels', 'code' => 'HW'])
            ->assertCreated()
            ->assertJsonFragment(['name' => 'Hot Wheels']);

        $this->assertDatabaseHas('product_series', ['code' => 'HW']);
    }

    #[Test]
    public function series_delete_is_blocked_when_used(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::create(['name' => 'HW', 'code' => 'HW']);
        Product::create(['name' => 'P', 'series_id' => $series->id, 'default_list_price' => 500]);

        $this->actingAs($owner)->deleteJson('/master/seri/'.$series->id)->assertUnprocessable();
        $this->assertDatabaseHas('product_series', ['id' => $series->id]);
    }

    #[Test]
    public function owner_can_delete_unused_series(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::create(['name' => 'Lonely', 'code' => 'L1']);

        $this->actingAs($owner)->deleteJson('/master/seri/'.$series->id)->assertOk();
        $this->assertDatabaseMissing('product_series', ['id' => $series->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'DELETED', 'entity' => 'ProductSeries']);
    }

    #[Test]
    public function owner_create_pages_render(): void
    {
        $owner = User::factory()->owner()->create();

        foreach (['/master/penitip/create', '/master/katalog-produk/create', '/master/lokasi-rak/create', '/settings/pengguna-role/create'] as $url) {
            $this->actingAs($owner)->get($url)->assertOk();
        }
    }

    /**
     * Seluruh halaman create Master/Pengguna kini di balik middleware Owner.
     */
    #[Test]
    public function staff_cannot_open_management_create_pages(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get('/master/penitip/create')->assertForbidden();
        $this->actingAs($staff)->get('/master/katalog-produk/create')->assertForbidden();
        $this->actingAs($staff)->get('/master/lokasi-rak/create')->assertForbidden();
        $this->actingAs($staff)->get('/settings/pengguna-role/create')->assertForbidden();
    }

    #[Test]
    public function owner_sees_katalog_modal_and_actions_with_data(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::create(['name' => 'Hot Wheels', 'code' => 'HW']);
        Product::create(['name' => 'Siap Jual', 'series_id' => $series->id, 'default_list_price' => 50000]);
        Product::create(['name' => 'Perlu Review', 'default_list_price' => 60000, 'needs_review' => true]);

        $this->actingAs($owner)->get('/master/katalog-produk')
            ->assertOk()
            ->assertSee('Kelola Seri')
            ->assertSee('Siap Jual')
            ->assertSee('Perlu Review')
            ->assertSee('Setujui');
    }

    /**
     * Product & rak dengan satu stock lot aktif di dalamnya.
     *
     * @return array{0: Product, 1: Rack}
     */
    private function productWithLot(): array
    {
        $product = Product::create(['name' => 'Dengan Lot', 'default_list_price' => 100]);
        $rack = Rack::create(['code' => 'B-1', 'type' => 'STORAGE', 'is_active' => true]);
        $consignment = Consignment::create(['doc_no' => 'CSG-'.uniqid(), 'consignment_date' => now()->toDateString()]);

        StockLot::create([
            'sku' => 'HW-LOT-'.uniqid(),
            'consignment_id' => $consignment->id,
            'sequence' => 1,
            'owner_code' => 'CN01',
            'product_id' => $product->id,
            'card_condition' => 'MINT',
            'blister_condition' => 'CLEAR',
            'list_price' => 100,
            'qty_on_hand' => 2,
            'rack_id' => $rack->id,
        ]);

        return [$product, $rack];
    }
}
