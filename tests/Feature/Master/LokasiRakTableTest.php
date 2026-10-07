<?php

namespace Tests\Feature\Master;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\LotStatus;
use App\Enums\RackType;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\Rack;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LokasiRakTableTest extends TestCase
{
    use RefreshDatabase;

    private function rack(string $code, array $overrides = []): Rack
    {
        return Rack::create(array_merge([
            'code' => $code,
            'zone' => 'A',
            'type' => RackType::Storage,
            'capacity' => 100,
        ], $overrides));
    }

    private function lotInRack(Rack $rack, int $qty = 10): StockLot
    {
        $consignor = Consignor::create([
            'consignor_code' => 'CN-'.$rack->id,
            'name' => 'Penitip '.$rack->id,
        ]);

        $consignment = Consignment::create([
            'doc_no' => 'CS-'.$rack->id,
            'consignor_id' => $consignor->id,
            'consignment_date' => now()->toDateString(),
        ]);

        return StockLot::create([
            'sku' => 'HW-'.$rack->id,
            'sequence' => 1,
            'consignment_id' => $consignment->id,
            'owner_type' => 'CONSIGN',
            'owner_code' => $consignor->consignor_code,
            'product_id' => Product::create([
                'name' => 'Produk '.$rack->id,
                'card_condition' => CardCondition::Mint,
                'blister_condition' => BlisterCondition::Yellowed,
                'default_list_price' => 150000,
            ])->id,
            'card_condition' => CardCondition::Mint,
            'blister_condition' => BlisterCondition::Clear,
            'list_price' => 150000,
            'qty_received' => $qty,
            'qty_on_hand' => $qty,
            'rack_id' => $rack->id,
            'status' => LotStatus::Available,
        ]);
    }

    #[Test]
    public function it_renders_a_paginated_table_instead_of_loading_every_row(): void
    {
        $owner = User::factory()->owner()->create();

        foreach (range(1, 12) as $i) {
            $this->rack(sprintf('A-01-%02d', $i));
        }

        $this->actingAs($owner)
            ->get(route('master.lokasi-rak'))
            ->assertOk()
            ->assertSee('A-01-01')
            ->assertSee('A-01-10')
            ->assertDontSee('A-01-11')
            ->assertSee('dari 12 data');
    }

    #[Test]
    public function it_sorts_on_the_server(): void
    {
        $owner = User::factory()->owner()->create();
        $this->rack('B-01-01');
        $this->rack('A-01-01');

        $html = $this->actingAs($owner)
            ->get(route('master.lokasi-rak', ['sort' => 'code', 'direction' => 'asc']))
            ->assertOk()
            ->getContent();

        $this->assertLessThan(strpos($html, 'B-01-01'), strpos($html, 'A-01-01'));
    }

    #[Test]
    public function it_searches_through_code_and_zone(): void
    {
        $owner = User::factory()->owner()->create();
        $this->rack('A-01-01', ['zone' => 'A']);
        $this->rack('QRT-01', ['zone' => 'Q']);

        $this->actingAs($owner)
            ->get(route('master.lokasi-rak', ['q' => 'QRT']))
            ->assertOk()
            ->assertSee('QRT-01')
            ->assertDontSee('A-01-01');
    }

    #[Test]
    public function it_filters_by_type_and_active_state(): void
    {
        $owner = User::factory()->owner()->create();
        $this->rack('QRT-01', ['type' => RackType::Quarantine]);
        $this->rack('D-01-01', ['type' => RackType::Display]);
        $this->rack('S-01-01', ['is_active' => false]);

        $this->actingAs($owner)
            ->get(route('master.lokasi-rak', ['type' => RackType::Quarantine->value]))
            ->assertOk()
            ->assertSee('QRT-01')
            ->assertDontSee('D-01-01');

        $this->actingAs($owner)
            ->get(route('master.lokasi-rak', ['active' => '1']))
            ->assertOk()
            ->assertSee('D-01-01')
            ->assertDontSee('S-01-01');
    }

    #[Test]
    public function it_renders_rack_types_as_words_instead_of_spaced_letters(): void
    {
        $owner = User::factory()->owner()->create();
        $this->rack('QRT-01', ['type' => RackType::Quarantine]);
        $this->rack('RTV-01', ['type' => RackType::RtvStaging]);

        $this->actingAs($owner)
            ->get(route('master.lokasi-rak'))
            ->assertOk()
            ->assertSee('Quarantine')
            ->assertSee('Rtv Staging')
            ->assertDontSee('Q U A R A N T I N E');
    }

    #[Test]
    public function it_shows_lot_counts_and_the_capacity_bar(): void
    {
        $owner = User::factory()->owner()->create();
        $rack = $this->rack('A-01-01', ['capacity' => 40]);
        $this->lotInRack($rack, 10);

        $this->actingAs($owner)
            ->get(route('master.lokasi-rak'))
            ->assertOk()
            ->assertSee('10 unit')
            ->assertSee('25.0%');
    }

    #[Test]
    public function it_marks_racks_without_capacity(): void
    {
        $owner = User::factory()->owner()->create();
        $this->rack('A-01-01', ['capacity' => null]);

        $this->actingAs($owner)
            ->get(route('master.lokasi-rak'))
            ->assertOk()
            ->assertSee('tanpa kapasitas');
    }

    #[Test]
    public function it_aggregates_rack_usage_in_a_single_query(): void
    {
        $owner = User::factory()->owner()->create();

        foreach (range(1, 12) as $i) {
            $this->lotInRack($this->rack(sprintf('A-01-%02d', $i)), $i);
        }

        DB::enableQueryLog();

        $this->actingAs($owner)->get(route('master.lokasi-rak'))->assertOk();

        $usageQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains($query, 'from "stock_lots"'))
            ->all();

        $this->assertCount(1, $usageQueries, 'Rack usage must be aggregated once per request');
    }

    #[Test]
    public function it_switches_between_deactivate_and_activate_for_owners(): void
    {
        $owner = User::factory()->owner()->create();
        $this->rack('A-01-01');
        $this->rack('A-01-02', ['is_active' => false]);

        $this->actingAs($owner)
            ->get(route('master.lokasi-rak'))
            ->assertOk()
            ->assertSee('Nonaktif')
            ->assertSee('Aktifkan');
    }

    #[Test]
    public function it_hides_management_actions_and_the_create_button_from_staff(): void
    {
        $staff = User::factory()->staff()->create();
        $this->rack('A-01-01');

        $html = $this->actingAs($staff)
            ->get(route('master.lokasi-rak'))
            ->assertOk()
            ->assertDontSee('Tambah Rak')
            ->assertDontSee('Hapus')
            ->getContent();

        $this->assertStringNotContainsString('Nonaktifkan', $html);
    }

    #[Test]
    public function it_ignores_an_unwhitelisted_sort_column(): void
    {
        $owner = User::factory()->owner()->create();
        $this->rack('A-01-01');

        $this->actingAs($owner)
            ->get(route('master.lokasi-rak', ['sort' => 'id', 'direction' => 'desc']))
            ->assertOk()
            ->assertSee('A-01-01');
    }

    #[Test]
    public function it_shows_an_empty_state_when_no_rack_exists(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get(route('master.lokasi-rak'))
            ->assertOk()
            ->assertSee('Belum ada rak')
            ->assertSee('Tambahkan rak pertama');
    }

    #[Test]
    public function it_switches_the_empty_state_when_a_filter_is_active(): void
    {
        $owner = User::factory()->owner()->create();
        $this->rack('A-01-01');

        $this->actingAs($owner)
            ->get(route('master.lokasi-rak', ['q' => 'tidak-ada']))
            ->assertOk()
            ->assertSee('Rak tidak ditemukan');
    }
}
