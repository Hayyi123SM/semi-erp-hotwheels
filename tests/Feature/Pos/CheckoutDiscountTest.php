<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\SchemeType;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Diskon per-item di meja kasir.
 *
 * Yang dijaga di sini tiga hal, dan ketiganya pernah salah:
 *
 *  1. Peran kasir. Perbandingan peran pernah memakai nilai enum huruf besar
 *     melawan string huruf kecil, sehingga setiap diskon ditolak 403 -- diskon
 *     sama sekali tidak bisa dipakai.
 *  2. Angka yang dibayar. `subtotal` harus harga sebelum diskon dan `total`
 *     sesudahnya, kalau tidak baris "Diskon" di nota tidak pernah terlihat
 *     sebagai pengurang.
 *  3. Bagi hasil titipan. Diskon tidak boleh membuat `fee_toko + hak_penitip`
 *     berbeda dari yang dibayar pelanggan (BR-05), dan tidak boleh dipotong dua
 *     kali.
 */
class CheckoutDiscountTest extends TestCase
{
    use RefreshDatabase;

    private function cashier(): User
    {
        return User::factory()->staff()->create();
    }

    private function openShift(User $cashier): Shift
    {
        return Shift::factory()->forUser($cashier)->create();
    }

    /**
     * @param  array<string, mixed>  $discount
     * @return array<string, mixed>
     */
    private function line(StockLot $lot, int $qty, array $discount = []): array
    {
        return [
            'lot_id' => $lot->getKey(),
            'qty' => $qty,
            'input_method' => 'SCAN',
        ] + $discount;
    }

    #[Test]
    public function a_percentage_discount_splits_subtotal_and_total(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);

        $lot = StockLot::factory()->own()->qty(3)->create(['list_price' => 45_000]);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-discount-percent',
            'items' => [$this->line($lot, 2, [
                'discount_type' => 'percent',
                'discount_value' => 10,
            ])],
            'payments' => [['method' => 'TUNAI', 'amount' => 81_000]],
            'tender' => 100_000,
        ])->assertOk()->assertJsonPath('total', 81_000);

        $sale = Sale::sole();

        $this->assertSame(90_000, $sale->subtotal, 'Subtotal adalah harga sebelum diskon.');
        $this->assertSame(9_000, $sale->discount_total);
        $this->assertSame(81_000, $sale->total);

        $item = SaleItem::sole();

        $this->assertSame(9_000, $item->discount);
        $this->assertSame('percent', $item->discount_type);
        $this->assertSame(10, $item->discount_value);
        $this->assertSame(81_000, $item->sell_price);
    }

    #[Test]
    public function an_amount_discount_is_taken_off_the_line(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);

        $lot = StockLot::factory()->own()->qty(3)->create(['list_price' => 45_000]);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-discount-amount',
            'items' => [$this->line($lot, 2, [
                'discount_type' => 'amount',
                'discount_value' => 5_000,
            ])],
            'payments' => [['method' => 'TUNAI', 'amount' => 85_000]],
            'tender' => 85_000,
        ])->assertOk();

        $this->assertSame(90_000, Sale::sole()->subtotal);
        $this->assertSame(85_000, Sale::sole()->total);
    }

    #[Test]
    public function a_discount_beyond_the_line_is_refused_without_writing(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);

        $lot = StockLot::factory()->own()->qty(2)->create(['list_price' => 45_000]);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-discount-too-big',
            'items' => [$this->line($lot, 1, [
                'discount_type' => 'amount',
                'discount_value' => 50_000,
            ])],
            'payments' => [['method' => 'TUNAI', 'amount' => 45_000]],
            'tender' => 45_000,
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.discount_value']);

        $this->assertSame(0, Sale::count());
        $this->assertSame(2, $lot->fresh()->qty_on_hand);
    }

    #[Test]
    public function a_percentage_above_one_hundred_is_refused(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);

        $lot = StockLot::factory()->own()->qty(2)->create(['list_price' => 45_000]);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-discount-over-100',
            'items' => [$this->line($lot, 1, [
                'discount_type' => 'percent',
                'discount_value' => 120,
            ])],
            'payments' => [['method' => 'TUNAI', 'amount' => 45_000]],
            'tender' => 45_000,
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.discount_value']);
    }

    #[Test]
    public function an_order_percentage_discount_comes_off_after_the_line_discount(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);

        $lotA = StockLot::factory()->own()->qty(3)->create(['list_price' => 45_000]);
        $lotB = StockLot::factory()->own()->qty(3)->create(['list_price' => 45_000]);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-order-discount-percent',
            'items' => [
                $this->line($lotA, 1),
                $this->line($lotB, 1, ['discount_type' => 'amount', 'discount_value' => 5_000]),
            ],
            'order_discount_type' => 'percent',
            'order_discount_value' => 10,
            'payments' => [['method' => 'TUNAI', 'amount' => 76_500]],
            'tender' => 100_000,
        ])->assertOk()->assertJsonPath('total', 76_500);

        $sale = Sale::sole();

        $this->assertSame(90_000, $sale->subtotal, 'Subtotal adalah harga kotor.');
        $this->assertSame(5_000, $sale->discount_total, 'Diskon item tetap terpisah.');
        $this->assertSame(8_500, $sale->order_discount_total);
        $this->assertSame('percent', $sale->order_discount_type);
        $this->assertSame(10, $sale->order_discount_value);
        $this->assertSame(76_500, $sale->total);
    }

    #[Test]
    public function an_order_amount_is_split_across_lines_without_losing_a_rupiah(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);

        $lotA = StockLot::factory()->own()->qty(3)->create(['list_price' => 45_000]);
        $lotB = StockLot::factory()->own()->qty(3)->create(['list_price' => 30_000]);

        // Total 75.000, diskon total 1.000 yang tidak habis dibagi dua baris:
        // pembagian proporsional harus tetap berjumlah persis 1.000.
        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-order-discount-split',
            'items' => [$this->line($lotA, 1), $this->line($lotB, 1)],
            'order_discount_type' => 'amount',
            'order_discount_value' => 1_000,
            'payments' => [['method' => 'TUNAI', 'amount' => 74_000]],
            'tender' => 74_000,
        ])->assertOk();

        $sale = Sale::sole();

        $this->assertSame(1_000, $sale->order_discount_total);
        $this->assertSame(74_000, $sale->total);

        $items = $sale->items()->orderBy('id')->get();

        $this->assertSame(1_000, $items->sum(fn (SaleItem $item): int => $item->order_discount));
        // Baris bernilai lebih besar menanggung bagian lebih besar.
        $this->assertSame(600, $items[0]->order_discount);
        $this->assertSame(400, $items[1]->order_discount);
    }

    #[Test]
    public function an_order_discount_on_consignment_lowers_only_the_store_fee(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);

        $lot = StockLot::factory()->qty(3)->create([
            'list_price' => 45_000,
            'scheme_type' => SchemeType::Percentage,
            'scheme_rate' => 20,
        ]);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-order-discount-consign',
            'items' => [$this->line($lot, 2)],
            'order_discount_type' => 'amount',
            'order_discount_value' => 10_000,
            'payments' => [['method' => 'TUNAI', 'amount' => 80_000]],
            'tender' => 80_000,
        ])->assertOk();

        $item = SaleItem::sole();

        $this->assertSame(10_000, $item->order_discount);
        $this->assertSame(8_000, $item->fee_toko, 'Fee toko yang menanggung diskon total.');
        $this->assertSame(72_000, $item->hak_penitip, 'Hak penitip tidak boleh berkurang.');
        $this->assertSame(
            80_000,
            $item->fee_toko + $item->hak_penitip,
            'BR-05: fee toko + hak penitip harus sama dengan yang dibayar pelanggan.',
        );
    }

    #[Test]
    public function an_order_discount_that_leaves_nothing_is_refused_without_writing(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);

        $lot = StockLot::factory()->own()->qty(2)->create(['list_price' => 45_000]);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-order-discount-too-big',
            'items' => [$this->line($lot, 1)],
            'order_discount_type' => 'amount',
            'order_discount_value' => 45_000,
            'payments' => [['method' => 'TUNAI', 'amount' => 1]],
            'tender' => 45_000,
        ])->assertStatus(422)->assertJsonValidationErrors(['order_discount_value']);

        $this->assertSame(0, Sale::count());
        $this->assertSame(2, $lot->fresh()->qty_on_hand);
    }

    #[Test]
    public function a_consignment_discount_keeps_the_split_in_balance(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);

        // Skema 20% untuk toko, sisanya hak penitip, toko yang menanggung diskon.
        $lot = StockLot::factory()->qty(3)->create([
            'list_price' => 45_000,
            'scheme_type' => SchemeType::Percentage,
            'scheme_rate' => 20,
        ]);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-discount-consign',
            'items' => [$this->line($lot, 2, [
                'discount_type' => 'percent',
                'discount_value' => 10,
            ])],
            'payments' => [['method' => 'TUNAI', 'amount' => 81_000]],
            'tender' => 81_000,
        ])->assertOk();

        $item = SaleItem::sole();

        $this->assertSame(81_000, $item->sell_price);
        $this->assertSame(
            81_000,
            $item->fee_toko + $item->hak_penitip,
            'Hak penitip dan fee toko harus berjumlah sama dengan yang dibayar pelanggan.',
        );
        // Diskon ditanggung toko: fee toko turun, hak penitip tetap utuh.
        $this->assertSame(9_000, $item->fee_toko);
        $this->assertSame(72_000, $item->hak_penitip);
    }
}
