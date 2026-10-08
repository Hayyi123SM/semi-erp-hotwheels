<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LedgerType;
use App\Enums\MovementType;
use App\Enums\SaleStatus;
use App\Models\ConsignorLedger;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Pos\ReceiptSequencer;
use App\Support\DeviceId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penyelesaian pembayaran dari layar kasir: `POST /pos/transaksi`.
 *
 * Tiga hal yang dijanjikan layar -- barang keluar, uang tercatat, hak penitip
 * terakru -- hanya benar kalau ketiganya terjadi bersamaan. Karena itu yang diuji
 * di sini bukan "apakah endpoint-nya mengembalikan 200", melainkan:
 *
 *  1. Angka yang tersimpan datang dari `stock_lots`, bukan dari layar. Keranjang
 *     tidak pernah mengesahkan harga, dan permintaan yang menyelipkan harga
 *     sendiri tidak boleh mengubah apa pun.
 *  2. Penolakan tidak meninggalkan setengah penjualan: stok tidak bergerak,
 *     tidak ada nota, tidak ada uang.
 *  3. Percobaan ulang dengan `client_sale_id` yang sama mengembalikan nota yang
 *     sama. Timeout lalu dikirim ulang adalah kejadian harian di koneksi kasir,
 *     dan dua nota untuk satu keranjang berarti dua kali potong stok.
 *
 * Yang sengaja tidak diuji di sini: penguncian baris (`lockForUpdate`) tidak
 * berarti apa pun pada SQLite yang dipakai suite ini. Pembuktian itu milik
 * MySQL -- lihat catatan pada {@see ReceiptSequencer}.
 */
class PosCheckoutTest extends TestCase
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
     * Satu baris keranjang, dalam bentuk yang dikirim layar kasir.
     *
     * Tidak ada harga di sini karena memang tidak pernah ada: server membaca
     * ulang lot-nya dan memutuskan harganya sendiri.
     *
     * @return array<string, mixed>
     */
    private function line(StockLot $lot, int $qty, string $method = 'SCAN'): array
    {
        return [
            'lot_id' => $lot->getKey(),
            'qty' => $qty,
            'input_method' => $method,
        ];
    }

    #[Test]
    public function it_records_the_note_cuts_the_stock_and_takes_the_money_together(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);

        $lot = StockLot::factory()->qty(3)->create(['list_price' => 45_000]);

        $response = $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-happy-path',
            'items' => [$this->line($lot, 2)],
            'payments' => [['method' => 'TUNAI', 'amount' => 90_000]],
            'tender' => 100_000,
        ]);

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('total', 90_000);

        $sale = Sale::sole();

        $this->assertSame(SaleStatus::Paid, $sale->status);
        $this->assertSame(90_000, $sale->total);
        $this->assertSame(90_000, $sale->subtotal);
        $this->assertSame(0, $sale->discount_total);
        $this->assertNotNull($sale->synced_at, 'Nota dibuat saat online tidak boleh masuk daftar "belum sinkron".');
        $this->assertSame($cashier->id, $sale->user_id);
        $this->assertSame($response->json('sale_id'), $sale->getKey());

        // Jawaban untuk dialog struk: pratinjau server + dua jalur cetak.
        // `struk_html` mengulang nomor nota, dan URL-nya menyatukan `auto=1`
        // dengan kembalian yang baru saja dihitung server.
        $this->assertStringContainsString($sale->receipt_no, (string) $response->json('struk_html'));
        $this->assertStringContainsString('No. nota', (string) $response->json('struk_html'));

        $this->assertSame(
            route('pos.struk.thermal', $sale).'?change=10000',
            $response->json('thermal_url'),
        );

        $this->assertSame(
            route('pos.struk', $sale).'?auto=1&change=10000',
            $response->json('print_url'),
        );

        $this->assertSame('browser', $response->json('print_method'));
        $this->assertSame('80mm', $response->json('paper'));

        // Stok: potongan dan catatan gerakannya lahir dari baris yang sama.
        $this->assertSame(1, $lot->fresh()->qty_on_hand);

        $movement = StockMovement::sole();
        $this->assertSame(MovementType::Sale, $movement->type);
        $this->assertSame(-2, $movement->qty_delta);
        $this->assertSame(Sale::class, $movement->ref_type);
        $this->assertSame($sale->getKey(), $movement->ref_id);
        $this->assertSame(1, $movement->balance_after);

        // Uang: satu baris pembayaran yang berjumlah sama persis dengan total.
        $this->assertSame(90_000, $sale->payments()->sole()->amount);
    }

    /**
     * Format struk `HW-YYYYMMDD-SEQ`, urut per hari.
     *
     * Bukan `POS-{DEVICE}-...`: identitas perangkat hidup di kolom
     * `sales.device_id`, bukan di nomor struk. Nomor harus terbaca dan tidak
     * pernah berubah, sedangkan identitas perangkat masih boleh diperbaiki --
     * detailnya di {@see DeviceId}.
     */
    #[Test]
    public function it_numbers_the_receipts_by_day_and_in_order(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);
        $lot = StockLot::factory()->qty(5)->create();

        $pay = fn () => $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-'.fake()->uuid(),
            'items' => [$this->line($lot, 1)],
            'payments' => [['method' => 'TUNAI', 'amount' => 45_000]],
            'tender' => 50_000,
        ]);

        $first = $pay()->assertOk()->json('receipt_no');
        $second = $pay()->assertOk()->json('receipt_no');

        $day = now()->format('Ymd');

        $this->assertSame("HW-{$day}-0001", $first);
        $this->assertSame("HW-{$day}-0002", $second);
    }

    /**
     * Harga yang dikirim layar tidak dipakai.
     *
     * Kunci `price` dan `sell_price` tidak ada di aturan validasi dan tidak ada
     * di `PricedLine`, jadi keduanya memang tidak akan dipakai -- yang diuji di
     * sini adalah bahwa itu benar-benar terjadi, karena kalau tidak, satu fix di
     * masa depan bisa menghubungkan kembali layar ke harga notanya tanpa ada
     * yang memperhatikan.
     */
    #[Test]
    public function a_price_sent_by_the_screen_is_ignored(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);
        $lot = StockLot::factory()->create(['list_price' => 45_000]);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-cheap-attempt',
            'items' => [[
                'lot_id' => $lot->getKey(),
                'qty' => 1,
                'input_method' => 'SCAN',
                'price' => 1,
                'sell_price' => 1,
            ]],
            'payments' => [['method' => 'TUNAI', 'amount' => 45_000]],
            'tender' => 45_000,
        ])->assertOk();

        $item = SaleItem::sole();

        $this->assertSame(45_000, $item->sell_price);
        $this->assertSame(45_000, Sale::sole()->total);
    }

    /**
     * Skenario paling sering di meja kasir: pembeli membawa dua unit, stoknya
     * tinggal satu.
     *
     * Yang dijaga bukan hanya status 422-nya, tapi bahwa tidak ada satu pun
     * tulisan yang terjadi: nota tanpa barang, atau barang keluar tanpa uang,
     * sama-sama tidak bisa diperbaiki dengan melihat layar lagi.
     */
    #[Test]
    public function it_refuses_a_shortfall_without_writing_anything(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);
        $lot = StockLot::factory()->qty(1)->create(['list_price' => 45_000]);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-short',
            'items' => [$this->line($lot, 2)],
            'payments' => [['method' => 'TUNAI', 'amount' => 90_000]],
            'tender' => 90_000,
        ])->assertStatus(422)->assertJsonValidationErrors(['items']);

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SaleItem::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(1, $lot->fresh()->qty_on_hand);
    }

    /**
     * Uang yang dipegang kurang dari total belanja.
     *
     * `tender` bukan jumlah yang diterima toko (itu `payments`), jadi angkanya
     * tidak ikut dijumlahkan -- yang diminta hanya mencukupi total, supaya
     * kembalian tidak pernah negatif.
     */
    #[Test]
    public function it_refuses_cash_that_cannot_cover_the_total(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);
        $lot = StockLot::factory()->qty(2)->create(['list_price' => 45_000]);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-short-cash',
            'items' => [$this->line($lot, 1)],
            'payments' => [['method' => 'TUNAI', 'amount' => 45_000]],
            'tender' => 40_000,
        ])->assertStatus(422)->assertJsonValidationErrors(['tender']);

        $this->assertSame(0, Sale::count());
        $this->assertSame(2, $lot->fresh()->qty_on_hand);
    }

    #[Test]
    public function it_refuses_payments_that_do_not_add_up_to_the_total(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);
        $lot = StockLot::factory()->qty(2)->create(['list_price' => 45_000]);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-partial',
            'items' => [$this->line($lot, 1)],
            'payments' => [['method' => 'TUNAI', 'amount' => 40_000]],
            'tender' => 40_000,
        ])->assertStatus(422)->assertJsonValidationErrors(['payments']);

        $this->assertSame(0, Sale::count());
    }

    /**
     * `Transfer` ada di enum tapi bukan di `PaymentMethod::pos()`.
     *
     * Transfer membayar penitip, bukan menerima uang di laci kasir. Kalau
     * lolos, rekap shift akan menampilkan baris "Transfer Rp 0" yang mustahil
     * terjadi -- dan baris yang selalu nol itu dibaca kasir sebagai "ada
     * transfer yang belum tercatat".
     */
    #[Test]
    public function it_refuses_a_payment_method_that_never_reaches_the_drawer(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);
        $lot = StockLot::factory()->qty(2)->create(['list_price' => 45_000]);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-transfer',
            'items' => [$this->line($lot, 1)],
            'payments' => [['method' => 'TRANSFER', 'amount' => 45_000]],
            'tender' => 45_000,
        ])->assertStatus(422)->assertJsonValidationErrors(['payments.0.method']);

        $this->assertSame(0, Sale::count());
    }

    /**
     * Percobaan ulang setelah timeout harus mengembalikan nota yang sama.
     *
     * Ini satu-satunya alasan `client_sale_id` ada: koneksi kasir putus di
     * tengah permintaan, layar tidak tahu apakah notanya tercatat, dan satu-satunya
     * cara aman untuk mencoba lagi adalah membawa kunci yang sama. Tanpa ini,
     * mencoba lagi akan memotong stok untuk kedua kalinya.
     */
    #[Test]
    public function retrying_with_the_same_key_returns_the_same_note_and_cuts_stock_once(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);
        $lot = StockLot::factory()->qty(3)->create(['list_price' => 45_000]);

        $payload = [
            'client_sale_id' => 'cs-retry-me',
            'items' => [$this->line($lot, 2)],
            'payments' => [['method' => 'TUNAI', 'amount' => 90_000]],
            'tender' => 90_000,
        ];

        $first = $this->actingAs($cashier)->postJson('/pos/transaksi', $payload)->assertOk();
        $second = $this->actingAs($cashier)->postJson('/pos/transaksi', $payload)->assertOk();

        $this->assertSame($first->json('sale_id'), $second->json('sale_id'));
        $this->assertSame($first->json('receipt_no'), $second->json('receipt_no'));
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(1, $lot->fresh()->qty_on_hand);
    }

    /**
     * Kasir tanpa shift terbuka ditolak.
     *
     * Tanpa shift, penjualan ini tidak masuk ke rekap mana pun -- uangnya akan
     * muncul di laci tanpa ada yang bisa mencocokkannya saat tutup shift. Yang
     * menolak adalah layanan, bukan middleware: shift adalah batasnya, bukan
     * peran, dan kasir memang harus bisa membuka shiftnya sendiri.
     */
    #[Test]
    public function it_refuses_a_cashier_without_an_open_shift(): void
    {
        $cashier = $this->cashier();
        $lot = StockLot::factory()->qty(2)->create(['list_price' => 45_000]);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-no-shift',
            'items' => [$this->line($lot, 1)],
            'payments' => [['method' => 'TUNAI', 'amount' => 45_000]],
            'tender' => 45_000,
        ])->assertStatus(422)->assertJsonValidationErrors(['checkout']);

        $this->assertSame(0, Sale::count());
        $this->assertSame(2, $lot->fresh()->qty_on_hand);
    }

    /**
     * Satu lot tidak boleh muncul dua kali dalam satu nota.
     *
     * Bukan formalitas: `price()` memotong stok dua baris dari penampung yang
     * sama, sehingga dua baris untuk lot yang sama akan menjual unit yang sama
     * dua kali dan menutupi kekurangannya dengan angka yang tidak pernah ada.
     */
    #[Test]
    public function it_refuses_the_same_lot_twice_in_one_note(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);
        $lot = StockLot::factory()->qty(5)->create(['list_price' => 45_000]);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-duplicate-lot',
            'items' => [$this->line($lot, 1), $this->line($lot, 1)],
            'payments' => [['method' => 'TUNAI', 'amount' => 90_000]],
            'tender' => 90_000,
        ])->assertStatus(422)->assertJsonValidationErrors(['items']);

        $this->assertSame(0, Sale::count());
        $this->assertSame(5, $lot->fresh()->qty_on_hand);
    }

    /**
     * Hak penitip terakru, dan hanya untuk barang yang memang titipan.
     *
     * Halaman saldo penitip membaca `consignor_ledger`, bukan `sales`: tanpa
     * entri ini penjualan tidak pernah mengurangi saldo penitip mana pun, dan
     * settlement berikutnya menagih barang yang sudah laku tanpa pernah tahu.
     * Untuk stok milik toko sendiri memang tidak ada hak yang bisa dibagi, jadi
     * barisnya harus tidak ada -- bukan nol.
     */
    #[Test]
    public function it_accrues_the_consignors_right_and_leaves_own_stock_out_of_the_ledger(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);

        $titipan = StockLot::factory()->qty(3)->create(['list_price' => 45_000]);
        $punyaToko = StockLot::factory()->own()->qty(3)->create(['list_price' => 30_000]);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-two-owners',
            'items' => [$this->line($titipan, 1), $this->line($punyaToko, 2)],
            'payments' => [['method' => 'TUNAI', 'amount' => 105_000]],
            'tender' => 110_000,
        ])->assertOk();

        $sale = Sale::sole();

        $this->assertSame(105_000, $sale->total);

        $titipanItem = $sale->items()->where('lot_id', $titipan->id)->sole();
        $ownItem = $sale->items()->where('lot_id', $punyaToko->id)->sole();

        // 20% dari 45.000 = 9.000 fee toko per unit; sisanya hak penitip.
        $this->assertSame(9_000, $titipanItem->fee_toko);
        $this->assertSame(36_000, $titipanItem->hak_penitip);

        // Seluruh harga jual milik toko; tidak ada hak yang bisa dibagi.
        $this->assertSame(60_000, $ownItem->fee_toko);
        $this->assertNull($ownItem->hak_penitip);

        $ledger = ConsignorLedger::sole();
        $this->assertSame(LedgerType::SaleAccrual, $ledger->type);
        $this->assertSame(36_000, $ledger->amount);
        $this->assertSame($sale->getKey(), $ledger->sale_id);
        $this->assertSame($titipanItem->getKey(), $ledger->sale_item_id);
        $this->assertSame($titipan->consignor_id, $ledger->consignor_id);
    }

    /**
     * Status `PAID`, bukan status lain, dan `flags` kosong.
     *
     * Dua status lain di enum berarti nota tercatat tapi belum layak dipercaya;
     * penjualan online yang baru selesai tidak berada di keadaan mana pun dari
     * keduanya, dan menandainya akan memasukkan nota sah ke antrean peninjauan.
     */
    #[Test]
    public function a_note_typed_here_is_settled_and_free_of_flags(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);
        $lot = StockLot::factory()->qty(2)->create(['list_price' => 45_000]);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-settled',
            'items' => [$this->line($lot, 1)],
            'payments' => [['method' => 'TUNAI', 'amount' => 45_000]],
            'tender' => 45_000,
        ])->assertOk();

        $sale = Sale::sole();

        $this->assertSame(SaleStatus::Paid, $sale->status);
        $this->assertSame([], $sale->flags);
        $this->assertNull($sale->voided_at);
        $this->assertSame('cs-settled', $sale->client_sale_id);
    }

    /**
     * Keranjang kosong ditolak sebelum apa pun dibaca.
     *
     * `items.min` ada di aturan, dan pesannya harus bisa ditindaklanjuti --
     * "Keranjang kosong", bukan angka aturan yang bocor ke layar kasir.
     */
    #[Test]
    public function it_refuses_an_empty_cart_with_a_message_a_cashier_can_act_on(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);

        $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-empty',
            'items' => [],
            'payments' => [['method' => 'TUNAI', 'amount' => 1]],
            'tender' => 1,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items'])
            ->assertJsonPath('errors.items.0', 'Keranjang kosong.');
    }

    /**
     * Pembayaran non-tunai tidak punya kembalian, dan struknya harus tahu.
     *
     * Tunai selalu menyebut kembalian (0 pun untuk uang pas); QRIS tidak pernah
     * menyebut "Kembalian" sama sekali, dan URL cetaknya tidak boleh membawa
     * `change` yang tidak ada artinya.
     */
    #[Test]
    public function a_non_cash_checkout_leaves_no_change_in_the_struk_links(): void
    {
        $cashier = $this->cashier();
        $this->openShift($cashier);
        $lot = StockLot::factory()->qty(2)->create(['list_price' => 45_000]);

        $response = $this->actingAs($cashier)->postJson('/pos/transaksi', [
            'client_sale_id' => 'cs-qris',
            'items' => [$this->line($lot, 1)],
            'payments' => [['method' => 'QRIS', 'amount' => 45_000, 'reference' => 'QR-REF-01']],
            'tender' => null,
        ])->assertOk();

        $sale = Sale::sole();

        $this->assertSame(0, $response->json('change'));

        $this->assertSame(
            route('pos.struk.thermal', $sale),
            $response->json('thermal_url'),
        );

        $this->assertSame(
            route('pos.struk', $sale).'?auto=1',
            $response->json('print_url'),
        );

        $html = (string) $response->json('struk_html');
        $this->assertStringContainsString('QRIS', $html);
        $this->assertStringNotContainsString('Kembalian', $html);
    }
}
