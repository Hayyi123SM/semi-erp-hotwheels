<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\ShiftStatus;
use App\Models\PosCartDraft;
use App\Models\Shift;
use App\Models\User;
use App\Services\Pos\CartDraftService;
use App\Support\DeviceId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Keranjang kasir yang bertahan di server.
 *
 * Yang dijaga di sini bukan "apakah JSON-nya tersimpan" -- itu urusan
 * database -- melainkan janji yang dibuat layar kasir ke kasirnya: barang yang
 * sudah dipindai tidak hilang saat halaman dimuat ulang, dan keranjang yang
 * sudah selesai atau sengaja dikosongkan tidak muncul kembali. Dua janji itu
 * punya batas yang jelas lewat shift dan umur draft, jadi batas-batasnya ikut
 * diuji di sini, bukan hanya jalur bahagianya.
 *
 * Regresi yang paling penting ada di `a_draft_with_an_open_shift_is_offered`:
 * layanan ini pernah menyebut model `PosShift` yang tidak ada, dan hanya draft
 * tanpa shift yang selamat -- artinya setiap keranjang nyata gagal dimuat.
 */
class CartDraftTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'POS-TEST-01';

    private function staff(): User
    {
        return User::factory()->staff()->create();
    }

    /**
     * Satu kasir dengan shift terbuka di perangkat ini.
     *
     * @return array{0: User, 1: Shift}
     */
    private function openShift(?User $actor = null, ?string $device = self::DEVICE): array
    {
        $actor ??= $this->staff();

        $shift = Shift::factory()
            ->forUser($actor)
            ->openedWith(100_000, $device)
            ->create();

        return [$actor, $shift];
    }

    /**
     * Satu baris keranjang, apa adanya seperti yang dikirim layar.
     *
     * @return array<string, mixed>
     */
    private function line(string $sku = 'HW-001', int $qty = 1): array
    {
        return [
            'lotId' => 7,
            'sku' => $sku,
            'name' => 'Hot Wheels '.$sku,
            'price' => 25_000,
            'ownership' => 'PRIBADI',
            'consignor' => null,
            'qty' => $qty,
            'stock' => 5,
            'discountType' => 'percent',
            'discountValue' => 10,
        ];
    }

    /**
     * Minta daftar draft sebagai perangkat ini.
     */
    private function load(User $actor, string $device = self::DEVICE)
    {
        return $this->actingAs($actor)
            ->withSession([DeviceId::SESSION_KEY => $device])
            ->getJson(route('pos.draft.load'));
    }

    #[Test]
    public function saving_then_loading_returns_the_same_cart(): void
    {
        [$actor] = $this->openShift();

        $this->actingAs($actor)
            ->withSession([DeviceId::SESSION_KEY => self::DEVICE])
            ->postJson(route('pos.draft.save'), ['items' => [$this->line()]])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->load($actor)
            ->assertOk()
            ->assertJsonPath('has_draft', true)
            ->assertJsonPath('draft.items.0.sku', 'HW-001')
            ->assertJsonPath('draft.items.0.qty', 1)
            ->assertJsonPath('draft.items.0.discountType', 'percent')
            ->assertJsonPath('draft.items.0.discountValue', 10);
    }

    #[Test]
    public function an_empty_cart_is_saved_as_a_cancellation_not_an_empty_draft(): void
    {
        [$actor] = $this->openShift();

        $this->actingAs($actor)
            ->withSession([DeviceId::SESSION_KEY => self::DEVICE])
            ->postJson(route('pos.draft.save'), ['items' => []])
            ->assertOk()
            ->assertJsonPath('draft_id', null);

        $this->load($actor)->assertJsonPath('has_draft', false);
        $this->assertSame(0, PosCartDraft::query()->count());
    }

    #[Test]
    public function cancelling_takes_the_draft_out_of_the_offer(): void
    {
        [$actor] = $this->openShift();

        $this->actingAs($actor)
            ->withSession([DeviceId::SESSION_KEY => self::DEVICE])
            ->postJson(route('pos.draft.save'), ['items' => [$this->line()]]);

        $this->actingAs($actor)
            ->withSession([DeviceId::SESSION_KEY => self::DEVICE])
            ->deleteJson(route('pos.draft.cancel'))
            ->assertOk();

        $this->load($actor)->assertJsonPath('has_draft', false);
        $this->assertSame('CANCELLED', PosCartDraft::query()->sole()->status);
    }

    #[Test]
    public function saving_again_updates_the_same_draft(): void
    {
        [$actor] = $this->openShift();

        foreach ([[$this->line('HW-001', 1)], [$this->line('HW-002', 3)]] as $items) {
            $this->actingAs($actor)
                ->withSession([DeviceId::SESSION_KEY => self::DEVICE])
                ->postJson(route('pos.draft.save'), ['items' => $items]);
        }

        $this->assertSame(1, PosCartDraft::query()->where('status', 'ACTIVE')->count());
        $this->load($actor)->assertJsonPath('draft.items.0.sku', 'HW-002');
    }

    #[Test]
    public function a_draft_with_an_open_shift_is_offered(): void
    {
        [$actor, $shift] = $this->openShift();

        PosCartDraft::create([
            'shift_id' => $shift->id,
            'user_id' => $actor->id,
            'device_id' => self::DEVICE,
            'status' => 'ACTIVE',
            'items' => [$this->line()],
            'expired_at' => now()->addHour(),
        ]);

        // Ini regresi langsung untuk `Class "App\Models\PosShift" not found`:
        // draft yang punya shift harus bisa dibaca, bukan meledak.
        $this->load($actor)
            ->assertOk()
            ->assertJsonPath('has_draft', true)
            ->assertJsonPath('draft.items.0.sku', 'HW-001');
    }

    #[Test]
    public function a_draft_from_a_closed_shift_is_not_offered(): void
    {
        [$actor, $shift] = $this->openShift();

        PosCartDraft::create([
            'shift_id' => $shift->id,
            'user_id' => $actor->id,
            'device_id' => self::DEVICE,
            'status' => 'ACTIVE',
            'items' => [$this->line()],
            'expired_at' => now()->addHour(),
        ]);

        $shift->forceFill(['status' => ShiftStatus::Closed, 'closed_at' => now()])->save();

        // Shift yang tertutup tidak lagi jadi shift "saat ini", jadi draftnya
        // tidak pernah bisa ditemukan -- menawarkannya berarti menawarkan
        // barang yang tidak bisa dibayar lagi.
        $this->load($actor)->assertJsonPath('has_draft', false);
    }

    #[Test]
    public function expiring_sweeps_drafts_whose_shift_has_closed(): void
    {
        [$actor, $shift] = $this->openShift();

        PosCartDraft::create([
            'shift_id' => $shift->id,
            'user_id' => $actor->id,
            'device_id' => self::DEVICE,
            'status' => 'ACTIVE',
            'items' => [$this->line()],
            'expired_at' => now()->addHour(),
        ]);

        $shift->forceFill(['status' => ShiftStatus::Closed, 'closed_at' => now()])->save();

        $this->assertSame(1, app(CartDraftService::class)->expireOld());
        $this->assertSame('EXPIRED', PosCartDraft::query()->sole()->status);
    }

    #[Test]
    public function an_expired_draft_is_not_offered(): void
    {
        [$actor, $shift] = $this->openShift();

        PosCartDraft::create([
            'shift_id' => $shift->id,
            'user_id' => $actor->id,
            'device_id' => self::DEVICE,
            'status' => 'ACTIVE',
            'items' => [$this->line()],
            'expired_at' => now()->subMinute(),
        ]);

        $this->load($actor)->assertJsonPath('has_draft', false);
        $this->assertSame('EXPIRED', PosCartDraft::query()->sole()->status);
    }

    #[Test]
    public function a_draft_belongs_to_the_device_that_made_it(): void
    {
        [$actor] = $this->openShift();

        $this->actingAs($actor)
            ->withSession([DeviceId::SESSION_KEY => self::DEVICE])
            ->postJson(route('pos.draft.save'), ['items' => [$this->line()]]);

        $this->load($actor, 'POS-OTHER-02')->assertJsonPath('has_draft', false);
    }
}
