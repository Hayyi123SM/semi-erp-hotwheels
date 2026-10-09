<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\PackagingType;
use App\Models\LabelPrintJob;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Detail yang muncul di kolom Produk halaman cetak label.
 *
 * Harga dan kondisi harus datang dari LOT, bukan dari nilai default produk:
 * lot-lah snapshot yang benar-benar dicetak di label, dan satu produk bisa
 * punya banyak lot dengan harga dan kondisi berbeda.
 */
class LabelQueueProductDetailTest extends TestCase
{
    use RefreshDatabase;

    private function jobQueuedForLot(StockLot $lot): LabelPrintJob
    {
        return LabelPrintJob::factory()->create(['lot_id' => $lot->id]);
    }

    #[Test]
    public function the_queue_renders_product_details_with_lot_price_and_conditions(): void
    {
        $owner = User::factory()->owner()->create();
        $series = ProductSeries::factory()->create(['name' => 'Racing Heroes']);
        $product = Product::factory()->create([
            'series_id' => $series->id,
            'name' => 'Mazda RX-7',
            'year' => 2024,
            'color' => 'Burnt Orange',
            'packaging_type' => PackagingType::Carded,
            // Nilai default katalog sengaja dibuat berbeda dari lot: kalau
            // halaman memakai nilai produk, harga dan kondisi di bawah tidak
            // akan cocok.
            'default_list_price' => 45_000,
            'card_condition' => CardCondition::Mint,
            'blister_condition' => BlisterCondition::Clear,
            'needs_review' => true,
        ]);
        $lot = StockLot::factory()->create([
            'product_id' => $product->id,
            'list_price' => 65_000,
            'card_condition' => CardCondition::NearMint,
            'blister_condition' => BlisterCondition::Scuffed,
        ]);
        $this->jobQueuedForLot($lot);

        $this->actingAs($owner)
            ->get(route('inbound.cetak-label'))
            ->assertOk()
            ->assertSee('Mazda RX-7')
            ->assertSee('Racing Heroes')
            ->assertSee('2024')
            ->assertSee('Burnt Orange')
            ->assertSee('Carded')
            ->assertSee('Near Mint / Scuffed')
            ->assertSee('Rp65.000')
            ->assertSee('Perlu Review')
            ->assertDontSee('Rp45.000');
    }

    #[Test]
    public function the_reprint_match_renders_color_conditions_and_lot_price(): void
    {
        $owner = User::factory()->owner()->create();
        $product = Product::factory()->create([
            'name' => 'Ford GT40',
            'color' => 'Gulf Blue',
        ]);
        StockLot::factory()->create([
            'product_id' => $product->id,
            'list_price' => 88_000,
            'card_condition' => CardCondition::Damaged,
            'blister_condition' => BlisterCondition::Cracked,
        ]);

        $this->actingAs($owner)
            ->get(route('inbound.cetak-label', ['q' => 'GT40']))
            ->assertOk()
            ->assertSee('Ford GT40')
            ->assertSee('Gulf Blue')
            ->assertSee('Damaged / Cracked')
            ->assertSee('Rp88.000');
    }
}
