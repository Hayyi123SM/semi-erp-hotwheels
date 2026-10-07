<?php

namespace Tests\Feature;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\ConsignmentStatus;
use App\Enums\ConsignorStatus;
use App\Enums\ProductStatus;
use App\Enums\SchemeType;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class KatalogProdukTableTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => $name,
            'card_condition' => CardCondition::Mint,
            'blister_condition' => BlisterCondition::Yellowed,
            'status' => ProductStatus::Active,
            'default_list_price' => 150000,
        ], $overrides));
    }

    #[Test]
    public function it_aggregates_lot_totals_without_querying_per_row(): void
    {
        $owner = User::factory()->owner()->create();

        foreach (range(1, 12) as $i) {
            $this->product('Hot Wheels '.$i);
        }

        DB::enableQueryLog();

        $this->actingAs($owner)->get(route('master.katalog-produk'))->assertOk();

        $lotQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains($query, 'from "stock_lots"'))
            ->all();

        $this->assertCount(1, $lotQueries, 'Lot totals must be aggregated once per request');
    }

    #[Test]
    public function it_renders_a_paginated_table_instead_of_loading_every_row(): void
    {
        $owner = User::factory()->owner()->create();

        foreach (range(1, 12) as $i) {
            $this->product('Hot Wheels '.$i, ['default_list_price' => 100000 + $i]);
        }

        $this->actingAs($owner)
            ->get(route('master.katalog-produk'))
            ->assertOk()
            ->assertSee('Hot Wheels 1')
            ->assertSee('Hot Wheels 10')
            ->assertDontSee('Hot Wheels 11')
            ->assertSee('dari 12 data')
            ->assertSee('Menampilkan 1');
    }

    #[Test]
    public function it_sorts_by_price_on_the_server(): void
    {
        $owner = User::factory()->owner()->create();
        $this->product('Murah', ['default_list_price' => 1000]);
        $this->product('Mahal', ['default_list_price' => 999000]);

        $this->actingAs($owner)
            ->get(route('master.katalog-produk', ['sort' => 'default_list_price', 'direction' => 'desc']))
            ->assertOk()
            ->assertSeeInOrder(['Mahal', 'Murah']);
    }

    #[Test]
    public function it_searches_through_series_and_casting_code(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::create(['name' => 'Matchbox', 'code' => 'MB']);
        $this->product('Packed', ['series_id' => $series->id]);
        $this->product('Loose', ['casting_code' => 'HW-8891']);

        $this->actingAs($owner)
            ->get(route('master.katalog-produk', ['q' => 'Matchbox']))
            ->assertOk()
            ->assertSee('Packed')
            ->assertDontSee('Loose');

        $this->actingAs($owner)
            ->get(route('master.katalog-produk', ['q' => 'HW-8891']))
            ->assertOk()
            ->assertSee('Loose')
            ->assertDontSee('Packed');
    }

    #[Test]
    public function it_filters_rows_that_need_review(): void
    {
        $owner = User::factory()->owner()->create();
        $this->product('Sudah Disetujui');
        $this->product('Menunggu', ['needs_review' => true]);

        $this->actingAs($owner)
            ->get(route('master.katalog-produk', ['needs_review' => '1']))
            ->assertOk()
            ->assertSee('Menunggu')
            ->assertDontSee('Sudah Disetujui');
    }

    #[Test]
    public function it_renders_conditions_as_words_instead_of_spaced_letters(): void
    {
        $owner = User::factory()->owner()->create();
        $this->product('Kondisi');

        $this->actingAs($owner)
            ->get(route('master.katalog-produk'))
            ->assertOk()
            ->assertSee('Mint / Yellowed')
            ->assertDontSee('M I N T');
    }

    #[Test]
    public function it_formats_price_and_lot_totals(): void
    {
        $owner = User::factory()->owner()->create();
        $product = $this->product('Berlotos', ['default_list_price' => 275000]);

        StockLot::create([
            'sku' => 'HW-MB-001',
            'sequence' => 1,
            'list_price' => 150000,
            'consignment_id' => Consignment::create([
                'doc_no' => 'CI-20260926-0001',
                'consignor_id' => Consignor::create([
                    'consignor_code' => 'CN01',
                    'name' => 'Budi Santoso',
                    'status' => ConsignorStatus::Active,
                    'scheme_type' => SchemeType::Percentage,
                    'scheme_rate' => 10,
                ])->id,
                'consignment_date' => '2026-09-26',
                'status' => ConsignmentStatus::Committed->value,
            ])->id,
            'owner_type' => 'CONSIGN',
            'owner_code' => 'CN01',
            'product_id' => $product->id,
            'card_condition' => CardCondition::Mint->value,
            'blister_condition' => BlisterCondition::Clear->value,
            'qty_on_hand' => 12,
        ]);

        $this->actingAs($owner)
            ->get(route('master.katalog-produk'))
            ->assertOk()
            ->assertSee('Rp275.000')
            ->assertSee('12 unit');
    }

    #[Test]
    public function it_hides_row_actions_from_staff(): void
    {
        $staff = User::factory()->staff()->create();
        $this->product('Produk Staff');

        $this->actingAs($staff)
            ->get(route('master.katalog-produk'))
            ->assertOk()
            ->assertSee('Lihat saja')
            ->assertDontSee('>Edit<', false)
            ->assertDontSee('>Hapus<', false);
    }

    #[Test]
    public function it_shows_row_actions_to_the_owner(): void
    {
        $owner = User::factory()->owner()->create();
        $this->product('Produk Owner', ['needs_review' => true]);

        $this->actingAs($owner)
            ->get(route('master.katalog-produk'))
            ->assertOk()
            ->assertSee('>Edit<', false)
            ->assertSee('>Hapus<', false)
            ->assertSee('>Setujui<', false)
            ->assertSee('Perlu Review');
    }

    #[Test]
    public function it_ignores_an_unwhitelisted_sort_column(): void
    {
        $owner = User::factory()->owner()->create();
        $this->product('Aman');

        $this->actingAs($owner)
            ->get(route('master.katalog-produk', ['sort' => 'casting_code', 'direction' => 'desc']))
            ->assertOk()
            ->assertSee('Aman');
    }

    #[Test]
    public function it_shows_an_empty_state_when_the_catalog_is_empty(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get(route('master.katalog-produk'))
            ->assertOk()
            ->assertSee('Katalog masih kosong');
    }

    #[Test]
    public function it_keeps_the_series_manager_out_of_the_page_until_it_is_asked_for(): void
    {
        $owner = User::factory()->owner()->create();

        $html = $this->actingAs($owner)
            ->get(route('master.katalog-produk'))
            ->assertOk()
            ->getContent();

        // The panel is a template the shared dialog lifts when the owner asks for
        // it, so it is in the page but inert: nothing on it is booted until the
        // dialog walks it, and nothing it fetches happens until then.
        $this->assertStringContainsString('<template id="seri-panel">', $html);
        $this->assertSame(1, substr_count($html, 'x-data="seriManager()"'));
        $this->assertStringContainsString("notify.templateModal('seri-panel'", $html);
    }

    #[Test]
    public function it_no_longer_carries_its_own_dialog_for_the_series_manager(): void
    {
        $owner = User::factory()->owner()->create();

        $html = $this->actingAs($owner)
            ->get(route('master.katalog-produk'))
            ->assertOk()
            ->getContent();

        // One dialog helper for the whole application. A second, page-owned one
        // would be back to maintaining a modal by hand.
        $this->assertStringNotContainsString('x-ui.modal', $html);
        $this->assertStringNotContainsString('$store.modal', $html);
    }

    #[Test]
    public function it_hides_the_series_manager_and_its_trigger_from_staff(): void
    {
        $staff = User::factory()->staff()->create();

        $html = $this->actingAs($staff)
            ->get(route('master.katalog-produk'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('seri-panel', $html);
        $this->assertStringNotContainsString('Kelola Seri', $html);
    }
}
