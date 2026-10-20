<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\StockInPribadiDraft;
use App\Models\User;
use App\Support\DeviceId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Daftar produk Stock In Pribadi yang bertahan di server.
 *
 * Yang dijaga di sini bukan "apakah JSON-nya tersimpan" -- itu urusan database
 * -- melainkan janji yang dibuat halamannya ke pengisinya: produk yang sudah
 * dipilih tidak hilang saat halaman dimuat ulang, dan daftar yang sudah jadi
 * stok tidak muncul kembali sebagai pekerjaan yang belum selesai. Kedua janji
 * itu punya batas yang jelas lewat kepemilikan, perangkat, dan umur draft, jadi
 * batas-batasnya ikut diuji di sini, bukan hanya jalur bahagiananya.
 *
 * Polanya sengaja sama dengan `Tests\Feature\Pos\CartDraftTest`: halaman yang
 * berbeda, janji yang sama.
 */
class InboundStockInPribadiDraftTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'SIP-TEST-01';

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    /**
     * Satu baris daftar, apa adanya seperti yang dikirim Alpine.
     *
     * @return array<string, mixed>
     */
    private function row(int $productId = 7, int $qty = 2): array
    {
        return [
            'id' => '3f6a1f0e-9a6e-4a5e-8f4f-1c1f4c6d1e11',
            'product_id' => $productId,
            'casting_code' => 'HWX-42',
            'name' => 'Nissan Skyline GT-R R34',
            'series_code' => 'HW',
            'qty' => $qty,
            'cost_price' => '45000',
            'card_condition' => 'MINT',
            'blister_condition' => 'CLEAR',
            'rack_id' => '',
        ];
    }

    private function save(User $actor, array $items, string $device = self::DEVICE)
    {
        return $this->actingAs($actor)
            ->withSession([DeviceId::SESSION_KEY => $device])
            ->postJson(route('inbound.stock-in-pribadi.draft.save'), ['items' => $items]);
    }

    private function load(User $actor, string $device = self::DEVICE)
    {
        return $this->actingAs($actor)
            ->withSession([DeviceId::SESSION_KEY => $device])
            ->getJson(route('inbound.stock-in-pribadi.draft.load'));
    }

    /**
     * @return array{0: User, 1: Product}
     */
    private function ownerWithProduct(): array
    {
        $series = ProductSeries::factory()->create(['code' => 'HW']);
        $product = Product::factory()->create(['series_id' => $series->id]);

        return [$this->owner(), $product];
    }

    #[Test]
    public function saving_then_loading_returns_the_same_items(): void
    {
        [$actor, $product] = $this->ownerWithProduct();

        $this->save($actor, [$this->row($product->id)])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('draft.items.0.product_id', $product->id);

        $this->load($actor)
            ->assertOk()
            ->assertJsonPath('has_draft', true)
            ->assertJsonPath('draft.items.0.product_id', $product->id)
            // `qty` kembali sebagai string karena penyimpanan draft
            // menormalisasinya lewat `Numbers::integer` -- sama seperti request
            // commit. Perhitungan di halaman membacanya dengan `Number()`,
            // jadi angkanya, bukan tipenya, yang menjadi kontraknya.
            ->assertJsonPath('draft.items.0.qty', '2')
            ->assertJsonPath('draft.items.0.cost_price', '45000')
            ->assertJsonPath('draft.items.0.card_condition', 'MINT');
    }

    /**
     * HPP ditulis `x-money-model` sebagai string digit; angka berspasi ribuan
     * yang dikirim harus diterima sebagai angka yang sama, seperti saat commit.
     */
    #[Test]
    public function a_grouped_cost_price_is_stored_as_the_plain_digits(): void
    {
        [$actor, $product] = $this->ownerWithProduct();

        $row = $this->row($product->id);
        $row['cost_price'] = '45.000';

        $this->save($actor, [$row])
            ->assertOk()
            ->assertJsonPath('draft.items.0.cost_price', '45000');
    }

    #[Test]
    public function an_empty_list_is_saved_as_a_cancellation_not_an_empty_draft(): void
    {
        [$actor, $product] = $this->ownerWithProduct();
        $this->save($actor, [$this->row($product->id)]);

        $this->save($actor, [])
            ->assertOk()
            ->assertJsonPath('draft_id', null);

        $this->load($actor)->assertJsonPath('has_draft', false);
        $this->assertSame('CANCELLED', StockInPribadiDraft::query()->sole()->status);
    }

    /**
     * Menyimpan daftar kosong saat tidak ada draft sama sekali tidak boleh
     * meninggalkan baris apa pun: baris kosong tidak pernah bisa dipulihkan,
     * jadi baris itu hanya akan menumpuk sebagai entri yang tidak berarti.
     */
    #[Test]
    public function an_empty_save_creates_no_draft_at_all(): void
    {
        [$actor] = $this->ownerWithProduct();

        $this->save($actor, [])->assertOk()->assertJsonPath('draft_id', null);

        $this->assertDatabaseCount('stock_in_pribadi_drafts', 0);
    }

    #[Test]
    public function cancelling_takes_the_draft_out_of_the_offer(): void
    {
        [$actor, $product] = $this->ownerWithProduct();
        $this->save($actor, [$this->row($product->id)]);

        $this->actingAs($actor)
            ->withSession([DeviceId::SESSION_KEY => self::DEVICE])
            ->deleteJson(route('inbound.stock-in-pribadi.draft.cancel'))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->load($actor)->assertJsonPath('has_draft', false);
        $this->assertSame('CANCELLED', StockInPribadiDraft::query()->sole()->status);
    }

    #[Test]
    public function saving_again_updates_the_same_draft(): void
    {
        [$actor, $product] = $this->ownerWithProduct();

        $this->save($actor, [$this->row($product->id, 1)]);
        $this->save($actor, [$this->row($product->id, 5)]);

        $this->assertSame(1, StockInPribadiDraft::query()->where('status', 'ACTIVE')->count());
        $this->load($actor)->assertJsonPath('draft.items.0.qty', '5');
    }

    #[Test]
    public function a_draft_belongs_to_the_device_that_made_it(): void
    {
        [$actor, $product] = $this->ownerWithProduct();
        $this->save($actor, [$this->row($product->id)]);

        $this->load($actor, 'SIP-OTHER-02')->assertJsonPath('has_draft', false);
    }

    #[Test]
    public function a_draft_belongs_to_the_user_that_made_it(): void
    {
        [$actor, $product] = $this->ownerWithProduct();
        $this->save($actor, [$this->row($product->id)]);

        $other = $this->owner();

        $this->load($other)->assertJsonPath('has_draft', false);
        $this->assertSame(1, StockInPribadiDraft::query()->count());
    }

    #[Test]
    public function an_expired_draft_is_not_offered(): void
    {
        [$actor, $product] = $this->ownerWithProduct();
        $this->save($actor, [$this->row($product->id)]);

        StockInPribadiDraft::query()->sole()->forceFill([
            'expired_at' => now()->subMinute(),
        ])->save();

        $this->load($actor)->assertJsonPath('has_draft', false);
        $this->assertSame('EXPIRED', StockInPribadiDraft::query()->sole()->status);
    }

    /**
     * Commit menghapus daftarnya: kalau tidak, halaman berikutnya akan
     * menawarkan kembali daftar yang sudah berubah jadi stok diterima.
     */
    #[Test]
    public function committing_the_form_cancels_the_draft(): void
    {
        [$actor, $product] = $this->ownerWithProduct();
        $this->save($actor, [$this->row($product->id)]);

        $this->actingAs($actor)
            ->withSession([DeviceId::SESSION_KEY => self::DEVICE])
            ->post('/inbound/stock-in-pribadi', [
                'source' => 'INV-2026-0917',
                'verified' => '1',
                'items' => [
                    ['product_id' => $product->id, 'qty' => '2', 'cost_price' => '45.000'],
                ],
            ])
            ->assertRedirect(route('inbound.stock-in-pribadi'));

        $this->assertSame('CANCELLED', StockInPribadiDraft::query()->sole()->status);
        $this->load($actor)->assertJsonPath('has_draft', false);
    }

    #[Test]
    public function a_row_without_a_product_is_rejected(): void
    {
        [$actor] = $this->ownerWithProduct();

        $this->save($actor, [['product_id' => 999999, 'qty' => 1]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.product_id');
    }

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $this->get(route('inbound.stock-in-pribadi.draft.load'))
            ->assertRedirect(route('login'));

        $this->post(route('inbound.stock-in-pribadi.draft.save'), ['items' => []])
            ->assertRedirect(route('login'));

        $this->delete(route('inbound.stock-in-pribadi.draft.cancel'))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function staff_cannot_read_or_write_the_draft(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get(route('inbound.stock-in-pribadi.draft.load'))
            ->assertForbidden();

        $this->actingAs($staff)->postJson(route('inbound.stock-in-pribadi.draft.save'), ['items' => []])
            ->assertForbidden();

        $this->actingAs($staff)->deleteJson(route('inbound.stock-in-pribadi.draft.cancel'))
            ->assertForbidden();
    }
}
