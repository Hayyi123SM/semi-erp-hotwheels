<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DiscountPolicy;
use App\Enums\SchemeType;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Auth\PinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Syarat skema per baris di Consignment In.
 *
 * Dua hal yang diuji di sini saling melengkapi dan tidak bisa dipisah: nilai yang
 * sampai ke `stock_lots`, dan siapa yang berwenang mengubahnya. Test yang hanya
 * mengecek kolom lot akan lolos untuk kode yang menyalin skema tetap dari profil
 * penitip, dan test yang hanya mengecek PIN akan lolos untuk kode yang menolak semua
 * override. Yang berguna adalah keduanya benar untuk alasan yang sama.
 */
class ConsignmentTermsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->withPin('123456')->create();
        $this->product = Product::factory()->create(['default_list_price' => 40_000]);
    }

    #[Test]
    public function a_line_without_terms_inherits_the_consignor_contract(): void
    {
        $consignor = Consignor::factory()->percentage(20)->create();

        $this->commit($this->owner, $consignor, [['product_id' => $this->product->id, 'qty' => '1']]);

        $lot = StockLot::sole();

        // Tidak mengirim apa pun berarti memercayai kontrak penitip, dan baris yang
        // seperti itu tidak boleh Needs Owner: tidak ada yang berubah.
        $this->assertSame(40_000, $lot->list_price);
        $this->assertSame(SchemeType::Percentage, $lot->scheme_type);
        $this->assertEqualsWithDelta(20.0, (float) $lot->scheme_rate, 0.001);
        $this->assertNull($lot->scheme_amount);
        $this->assertSame(DiscountPolicy::StoreBears, $lot->discount_policy);
        $this->assertSame(1, $lot->terms_version);
    }

    #[Test]
    public function staff_may_commit_the_consignor_default_without_a_pin(): void
    {
        $staff = User::factory()->staff()->create();
        $consignor = Consignor::factory()->percentage(20)->create();

        $this->commitAndRedirectToReceipt($staff, $consignor, [['product_id' => $this->product->id, 'qty' => '1']]);

        $this->assertDatabaseCount('stock_lots', 1);
    }

    #[Test]
    public function staff_restating_the_default_verbatim_is_not_treated_as_an_override(): void
    {
        // Bentuk form yang wajar: setelah memilih penitip, Staff mengetik angka
        // yang memang sudah tertulis di kontrak. Perlakukan ini sebagai "memakai
        // default", bukan "mengubah default", atau PIN Owner akan diminta untuk
        // setiap commit yang tidak erfolgt.
        $staff = User::factory()->staff()->create();
        $consignor = Consignor::factory()->percentage(20)->create();

        $this->commitAndRedirectToReceipt($staff, $consignor, [[
            'product_id' => $this->product->id,
            'qty' => '1',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => '20',
        ]]);

        $this->assertDatabaseCount('stock_lots', 1);
    }

    #[Test]
    public function staff_overriding_the_scheme_is_refused_without_a_token(): void
    {
        $staff = User::factory()->staff()->create();
        $consignor = Consignor::factory()->percentage(20)->create();

        $this->commit($staff, $consignor, [[
            'product_id' => $this->product->id,
            'qty' => '1',
            'scheme_type' => 'FLAT',
            'scheme_amount' => '8000',
        ]])
            ->assertSessionHasErrors('pin_token');

        $this->assertDatabaseCount('stock_lots', 0);
    }

    #[Test]
    public function staff_overriding_the_rate_is_refused_without_a_token(): void
    {
        // Skema sama, angkanya beda. Menghapus `scheme_type` dari perbandingan akan
        // membiarkan perubahan ini lewat, padahal fee penitip berubah 15 poin.
        $staff = User::factory()->staff()->create();
        $consignor = Consignor::factory()->percentage(20)->create();

        $this->commit($staff, $consignor, [[
            'product_id' => $this->product->id,
            'qty' => '1',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => '5',
        ]])->assertSessionHasErrors('pin_token');

        $this->assertDatabaseCount('stock_lots', 0);
    }

    #[Test]
    public function staff_overriding_the_price_is_refused_without_a_token(): void
    {
        $staff = User::factory()->staff()->create();
        $consignor = Consignor::factory()->percentage(20)->create();

        $this->commit($staff, $consignor, [[
            'product_id' => $this->product->id,
            'qty' => '1',
            'list_price' => '35000',
        ]])->assertSessionHasErrors('pin_token');

        $this->assertDatabaseCount('stock_lots', 0);
    }

    #[Test]
    public function a_valid_owner_token_lets_the_override_through(): void
    {
        $staff = User::factory()->staff()->create();
        $consignor = Consignor::factory()->percentage(20)->create();

        $token = app(PinService::class)->issue($staff, '123456', 'consignment.scheme-override')->token;

        $this->commitAndRedirectToReceipt($staff, $consignor, [[
            'product_id' => $this->product->id,
            'qty' => '1',
            'list_price' => '35.000',
            'scheme_type' => 'FLAT',
            'scheme_amount' => '8.000',
            'discount_policy' => 'SHARED',
        ]], ['pin_token' => $token]);

        $lot = StockLot::sole();

        $this->assertSame(35_000, $lot->list_price);
        $this->assertSame(SchemeType::Flat, $lot->scheme_type);
        $this->assertNull($lot->scheme_rate);
        $this->assertSame(8_000, $lot->scheme_amount);
        $this->assertSame(DiscountPolicy::Shared, $lot->discount_policy);
    }

    #[Test]
    public function a_token_minted_for_another_action_does_not_authorise_this_one(): void
    {
        // Dialog PIN global/issues token per konteks. Kalau konteksnya tidak ikut
        // dibandingkan, satu token yang bocor untuk "tambah stok sendiri" juga
        // akan berlaku untuk "ubah skema penitip".
        $staff = User::factory()->staff()->create();
        $consignor = Consignor::factory()->percentage(20)->create();

        $token = app(PinService::class)->issue($staff, '123456', 'own-stock.create')->token;

        $this->commit($staff, $consignor, [[
            'product_id' => $this->product->id,
            'qty' => '1',
            'scheme_type' => 'FLAT',
            'scheme_amount' => '8000',
        ]], ['pin_token' => $token])->assertSessionHasErrors('pin_token');

        $this->assertDatabaseCount('stock_lots', 0);
    }

    #[Test]
    public function the_owner_may_override_without_typing_a_pin(): void
    {
        $consignor = Consignor::factory()->percentage(20)->create();

        $this->commitAndRedirectToReceipt($this->owner, $consignor, [[
            'product_id' => $this->product->id,
            'qty' => '1',
            'scheme_type' => 'NETT',
            'scheme_amount' => '38.000',
        ]]);

        $lot = StockLot::sole();

        $this->assertSame(SchemeType::Nett, $lot->scheme_type);
        $this->assertSame(38_000, $lot->scheme_amount);
    }

    #[Test]
    public function one_token_covers_every_overriding_line_in_one_commit(): void
    {
        // Dialog PIN dipanggil sekali saat commit. Kalau tiap baris menagih
        // tokennya sendiri, Staff yang mengubah lima baris akan diminta PIN
        // lima kali untuk satu dokumen.
        $staff = User::factory()->staff()->create();
        $other = Product::factory()->create(['default_list_price' => 25_000]);
        $consignor = Consignor::factory()->percentage(20)->create();

        $token = app(PinService::class)->issue($staff, '123456', 'consignment.scheme-override')->token;

        $this->commitAndRedirectToReceipt($staff, $consignor, [
            ['product_id' => $this->product->id, 'qty' => '1', 'scheme_type' => 'FLAT', 'scheme_amount' => '8000'],
            ['product_id' => $other->id, 'qty' => '1', 'list_price' => '22000'],
        ], ['pin_token' => $token]);

        $this->assertDatabaseCount('stock_lots', 2);
    }

    #[Test]
    public function two_lines_of_one_product_may_carry_different_terms(): void
    {
        // Karena satu lot adalah satu kombinasi harga+skema, memaksa dua baris
        // produk yang sama untuk selalu sama akan kehilangan override yang
        // justru boleh terjadi dalam satu dokumen.
        $consignor = Consignor::factory()->percentage(20)->create();

        $this->commitAndRedirectToReceipt($this->owner, $consignor, [
            ['product_id' => $this->product->id, 'qty' => '1', 'scheme_type' => 'PERCENTAGE', 'scheme_rate' => '20'],
            ['product_id' => $this->product->id, 'qty' => '1', 'scheme_type' => 'FLAT', 'scheme_amount' => '9000'],
        ]);

        $this->assertDatabaseCount('stock_lots', 2);
        $this->assertEqualsWithDelta(20.0, (float) StockLot::where('scheme_type', SchemeType::Percentage->value)->sole()->scheme_rate, 0.001);
        $this->assertSame(9_000, StockLot::where('scheme_type', SchemeType::Flat->value)->sole()->scheme_amount);
    }

    #[Test]
    public function a_scheme_without_its_parameter_is_rejected(): void
    {
        // Tanpa ini, baris PERCENTAGE tanpa rate akan ditolak jauh di bawah,
        // setelah `stock_lots` sudah ditulis di dalam transaksi, dengan pesan
        // yang tidak menunjuk ke kolom yang salah.
        $this->commit($this->owner, Consignor::factory()->percentage(20)->create(), [[
            'product_id' => $this->product->id,
            'qty' => '1',
            'scheme_type' => 'PERCENTAGE',
        ]])->assertSessionHasErrors('items.0.scheme_rate');

        $this->assertDatabaseCount('stock_lots', 0);
    }

    #[Test]
    public function staff_cannot_open_a_negative_margin_line(): void
    {
        // BR-06: margin negatif diblokir, dan pemblokirannya tidak bisa dibuka
        // hanya dengan token yang kebetulan sah untuk penyimpangan skema.
        $staff = User::factory()->staff()->create();
        $consignor = Consignor::factory()->percentage(20)->create();

        $token = app(PinService::class)->issue($staff, '123456', 'consignment.scheme-override')->token;

        $this->commit($staff, $consignor, [[
            'product_id' => $this->product->id,
            'qty' => '1',
            'list_price' => '40.000',
            'scheme_type' => 'NETT',
            'scheme_amount' => '40.000',
        ]], ['pin_token' => $token])->assertSessionHasErrors('items.0.scheme_amount');

        $this->assertDatabaseCount('stock_lots', 0);
    }

    #[Test]
    public function the_owner_may_open_a_negative_margin_line_and_it_is_flagged(): void
    {
        // Lot dengan margin negatif harus bisa ada setelah Owner mengambil
        // keputusan itu, dan harus ditandai supaya bisa ditinjau lagi. Lot yang
        // negatif tapi tidak ditandai akan hilang tanpa jejak.
        $consignor = Consignor::factory()->percentage(20)->create();

        $this->commitAndRedirectToReceipt($this->owner, $consignor, [[
            'product_id' => $this->product->id,
            'qty' => '1',
            'list_price' => '40.000',
            'scheme_type' => 'NETT',
            'scheme_amount' => '45.000',
        ]]);

        $lot = StockLot::sole();

        $this->assertTrue($lot->negative_margin_flag);
    }

    #[Test]
    public function an_ordinary_line_is_not_flagged(): void
    {
        $this->commitAndRedirectToReceipt($this->owner, Consignor::factory()->percentage(20)->create(), [[
            'product_id' => $this->product->id,
            'qty' => '1',
        ]]);

        $this->assertFalse(StockLot::sole()->negative_margin_flag);
    }

    #[Test]
    public function a_percentage_above_one_hundred_is_rejected(): void
    {
        $this->commit($this->owner, Consignor::factory()->percentage(20)->create(), [[
            'product_id' => $this->product->id,
            'qty' => '1',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => '150',
        ]])->assertSessionHasErrors('items.0.scheme_rate');

        $this->assertDatabaseCount('stock_lots', 0);
    }

    #[Test]
    public function a_parameter_left_over_from_another_scheme_is_ignored(): void
    {
        // Mengganti skema di baris yang sama tidak selalu menghapus kolom yang
        // tidak lagi dipakai. Menolak form karena field sisa akan membuat Staff
        // mengosongkan kolom yang tidak terlihat, bukan karena isinya salah.
        $consignor = Consignor::factory()->percentage(20)->create();

        $this->commitAndRedirectToReceipt($this->owner, $consignor, [[
            'product_id' => $this->product->id,
            'qty' => '1',
            'scheme_type' => 'FLAT',
            'scheme_rate' => '999',
            'scheme_amount' => '9000',
        ]]);

        $lot = StockLot::sole();
        $this->assertSame(SchemeType::Flat, $lot->scheme_type);
        $this->assertNull($lot->scheme_rate);
    }

    #[Test]
    public function a_line_with_a_negative_price_is_rejected(): void
    {
        $this->commit($this->owner, Consignor::factory()->percentage(20)->create(), [[
            'product_id' => $this->product->id,
            'qty' => '1',
            'list_price' => '-1',
        ]])->assertSessionHasErrors('items.0.list_price');

        $this->assertDatabaseCount('stock_lots', 0);
    }

    #[Test]
    public function a_rejected_commit_hands_the_token_back_so_it_is_not_asked_for_twice(): void
    {
        // Server menolak commit ini karena satu sel, bukan karena tokennya. Halaman
        // dimuat ulang, jadi kalau token tidak ikut dikembalikan, kasir mengetik
        // PIN kedua untuk dokumen yang sama -- padahal yang pertama masih sah
        // selama lima menit.
        $staff = User::factory()->staff()->create();
        $consignor = Consignor::factory()->percentage(20)->create();

        $token = app(PinService::class)->issue($staff, '123456', 'consignment.scheme-override')->token;

        $response = $this->commit($staff, $consignor, [[
            'product_id' => $this->product->id,
            'qty' => '1',
            'scheme_type' => 'PERCENTAGE',
            'scheme_rate' => '150',
        ]], ['pin_token' => $token]);

        $response->assertRedirect(route('inbound.consignment-in'));

        $page = $this->actingAs($staff)->get(route('inbound.consignment-in'));

        // Error selnya harus ikut muncul di halaman yang dikembalikan, kalau tidak
        // kondisi berikutnya -- token dikembalikan, dialog tidak dibuka lagi --
        // diuji dengan asumsi yang belum dipastikan benar.
        $page->assertOk();

        // Kunci errornya, bukan pesannya: selnya dirender Alpine, jadi teksnya baru
        // muncul di DOM setelah JS jalan dan tidak ada di HTML yang dikirim server.
        // Yang perlu dipastikan di sini adalah errornya sampai ke grid -- kalau
        // tidak, kondisi di bawahnya (token dikembalikan, dialog tidak dibuka
        // lagi) diuji dengan asumsi yang belum dipastikan benar.
        $page->assertSee('items.0.scheme_rate', escape: false);

        $page->assertSee($token, escape: false);
        $this->assertDatabaseCount('stock_lots', 0);
    }

    #[Test]
    public function a_refused_token_is_not_handed_back(): void
    {
        // Token yang ditolak harus dibuang, bukan disimpan. Memakainya lagi akan
        // membuat commit berikutnya ditolak dengan alasan yang sama, tanpa dialog
        // pernah terbuka, dan formnya tidak akan bisa dikirim sama sekali.
        $staff = User::factory()->staff()->create();
        $consignor = Consignor::factory()->percentage(20)->create();

        $this->commit($staff, $consignor, [[
            'product_id' => $this->product->id,
            'qty' => '1',
            'scheme_type' => 'FLAT',
            'scheme_amount' => '8000',
        ]], ['pin_token' => 'token-palsu'])->assertRedirect(route('inbound.consignment-in'));

        $page = $this->actingAs($staff)->get(route('inbound.consignment-in'));

        // Sama seperti di atas: bukti bahwa error PIN benar-benar sampai ke
        // halaman, sebelum menyimpulkan tokennya dibuang.
        $page->assertOk();
        $page->assertSee('Token PIN Owner tidak terbaca.');

        $this->assertStringNotContainsString('token-palsu', $page->getContent());
    }

    /**
     * Commit yang berhasil, sekaligus memastikan tujuannya bukti terima.
     *
     * Dipisah dari `commit()` karena keduanya tidak selalu berakhir sama:
     * PIN yang ditolak dan skema yang tidak valid harus kembali ke form, dan
     * pemanggilnya memakai `commit()` biasa lalu memeriksa errornya sendiri.
     *
     * Yang dibedakan bukan bentuk redirect-nya -- keduanya sama-sama 302 --
     * tapi tujuan akhirnya. Assertion lama `assertRedirect(route('inbound.consignment-in'))`
     * tidak bisa membedakan keduanya, dan gagal membedakan skenario berhasil
     * dari skenario yang ditolak.
     */
    private function commitAndRedirectToReceipt(User $actor, Consignor $consignor, array $items, array $extra = [])
    {
        $response = $this->commit($actor, $consignor, $items, $extra);

        $consignment = Consignment::query()->latest('id')->firstOrFail();

        $response->assertRedirect(route('inbound.consignment-in.bukti-terima', [
            'consignment' => $consignment,
            'auto' => 1,
        ]));

        return $response;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    private function commit(User $actor, Consignor $consignor, array $items, array $extra = [])
    {
        return $this->actingAs($actor)->from('/inbound/consignment-in')->post('/inbound/consignment-in', array_merge([
            'consignor_id' => $consignor->id,
            'consignment_date' => now()->toDateString(),
            'verified' => '1',
            'items' => $items,
        ], $extra));
    }
}
