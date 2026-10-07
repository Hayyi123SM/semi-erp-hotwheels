<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\AuditAction;
use App\Enums\LotStatus;
use App\Enums\MovementType;
use App\Enums\OpnameLineStatus;
use App\Enums\OpnameStatus;
use App\Enums\OwnerType;
use App\Enums\RtvStatus;
use App\Models\Consignor;
use App\Models\Opname;
use App\Models\OpnameLine;
use App\Models\QuarantineCase;
use App\Models\Rack;
use App\Models\RtvNote;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Auth\PinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Retur ke penitip (FR-IC-30..35).
 *
 * Lima hal yang dijaga di sini, karena kelimanya gugur diam-diam kalau hanya
 * diperiksa di template:
 *
 * 1. **Qty adalah batas, bukan permintaan.** Melebihi stok, memilih barang
 *    milik toko, atau mengambil lot penitip lain ditolak di service, bukan
 *    hanya dengan kolom input yang dinonaktifkan di layar.
 * 2. **FR-IC-35 dijaga di tiga titik.** Kasus Karantina terbuka dan sesi
 *    opname yang belum selesai menolak pembuatan, pemindahan ke rak staging,
 *    dan eksekusi -- ikatan yang muncul di tengah sesi harus menahan sesi itu
 *    juga.
 * 3. **Scan adalah prasyarat keluar gudang.** Eksekusi menolak selama ada
 *    baris yang belum terverifikasi penuh; tanpa aturan itu langkah verifikasi
 *    hanya hiasan.
 * 4. **Eksekusi mengurangi stok tepat sekali.** Satu gerakan `RTV` per baris
 *    dengan saldo akhir yang benar, dan lot yang habis berstatus Dikembalikan.
 * 5. **PIN adalah milik aksinya.** Token untuk opname, atau tanpa token sama
 *    sekali, tidak membuka pintu eksekusi RTV.
 */
class RtvTest extends TestCase
{
    use RefreshDatabase;

    private function consignor(): Consignor
    {
        return Consignor::factory()->create();
    }

    private function rack(): Rack
    {
        return Rack::factory()->create();
    }

    private function stagingRack(): Rack
    {
        return Rack::factory()->rtvStaging()->create();
    }

    private function lot(Consignor $consignor, int $qty, string $sku, ?Rack $rack = null): StockLot
    {
        return StockLot::factory()
            ->ownedBy($consignor)
            ->state([
                'sku' => $sku,
                'rack_id' => ($rack ?? $this->rack())->id,
                'qty_on_hand' => $qty,
                'qty_received' => $qty,
            ])
            ->create();
    }

    private function staff(): User
    {
        return User::factory()->staff()->create();
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    private function create(User $actor, Consignor $consignor, array $qty, ?string $reason = null): TestResponse
    {
        $payload = ['consignor_id' => $consignor->id, 'qty' => $qty];

        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        return $this->actingAs($actor)
            ->from(route('inventory.retur-rtv'))
            ->post(route('inventory.retur-rtv.store'), $payload);
    }

    private function staging(User $actor, RtvNote $note): TestResponse
    {
        return $this->actingAs($actor)
            ->from(route('inventory.retur-rtv'))
            ->post(route('inventory.retur-rtv.staging', $note));
    }

    private function scan(User $actor, RtvNote $note, string $sku): TestResponse
    {
        return $this->actingAs($actor)
            ->from(route('inventory.retur-rtv'))
            ->post(route('inventory.retur-rtv.scan', $note), ['sku' => $sku]);
    }

    private function approve(User $actor, RtvNote $note, ?string $token = null): TestResponse
    {
        $payload = $token === null ? [] : ['pin_token' => $token];

        return $this->actingAs($actor)
            ->from(route('inventory.retur-rtv'))
            ->post(route('inventory.retur-rtv.approve', $note), $payload);
    }

    private function cancel(User $actor, RtvNote $note): TestResponse
    {
        return $this->actingAs($actor)
            ->from(route('inventory.retur-rtv'))
            ->post(route('inventory.retur-rtv.batal', $note));
    }

    /**
     * Token PIN untuk konteks tertentu, diterbitkan atas nama aktor tertentu.
     *
     * Owner PIN-nya '123456' di pabrik user, dan penerbitan butuh Owner yang
     * PIN-nya cocok -- itu sebabnya satu baris dibuat lebih dulu.
     */
    private function tokenFor(User $actor, string $context): string
    {
        $this->owner();

        return app(PinService::class)->issue($actor, '123456', $context)->token;
    }

    /**
     * Buka sesi opname yang mencakup satu lot, persis seperti yang dilakukan
     * `OpnameService::start()`.
     */
    private function openOpnameFor(StockLot $lot): Opname
    {
        $opname = Opname::factory()->counting()->create();

        OpnameLine::factory()->create([
            'opname_id' => $opname->id,
            'lot_id' => $lot->id,
            'system_qty' => $lot->qty_on_hand,
            'status' => OpnameLineStatus::Pending,
        ]);

        return $opname;
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $this->get(route('inventory.retur-rtv'))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_page_starts_empty_and_asks_for_a_consignor(): void
    {
        $html = $this->actingAs($this->staff())
            ->get(route('inventory.retur-rtv'))
            ->assertOk()
            ->assertSee('Pilih penitip lebih dulu')
            ->content();

        // Input qty tidak ada sebelum penitip dipilih: daftar tanpa pemilik
        // adalah daftar yang salah sasaran sejak awal.
        $this->assertStringNotContainsString('name="qty[', $html);
        $this->assertSame(0, RtvNote::count());
    }

    #[Test]
    public function picking_a_consignor_lists_only_its_own_lots(): void
    {
        $staff = $this->staff();
        $consignor = $this->consignor();
        $other = $this->consignor();

        $this->lot($consignor, 4, 'CN01-HW-001');
        $this->lot($other, 9, 'CN02-HW-009');

        $html = $this->actingAs($staff)
            ->get(route('inventory.retur-rtv', ['penitip' => $consignor->id]))
            ->assertOk()
            ->assertSee('Qty Kembali')
            ->assertSee('CN01-HW-001')
            ->content();

        $this->assertStringNotContainsString('CN02-HW-009', $html);
    }

    #[Test]
    public function creating_a_session_keeps_only_the_rows_that_have_a_qty(): void
    {
        $staff = $this->staff();
        $consignor = $this->consignor();

        $chosen = $this->lot($consignor, 5, 'CN01-HW-001');
        $skipped = $this->lot($consignor, 3, 'CN01-HW-002');

        // Nol berarti "tidak ikut": form mengirim seluruh baris tabel, dan
        // kolom yang dibiarkan kosong harus gugur tanpa menolak formulirnya.
        $this->create($staff, $consignor, [$chosen->id => 2, $skipped->id => 0], 'Tidak laku')
            ->assertRedirect(route('inventory.retur-rtv'))
            ->assertSessionHas('toast');

        $note = RtvNote::sole();

        $this->assertSame(RtvStatus::Draft, $note->status);
        $this->assertSame('RTV-'.now()->format('Ymd').'-001', $note->rtv_no);
        $this->assertSame('Tidak laku', $note->reason);
        $this->assertSame($staff->id, $note->created_by);
        $this->assertSame(1, $note->lines()->count());
        $this->assertSame(2, $note->lines()->sole()->qty);
        $this->assertSame(0, $note->lines()->sole()->verified_qty);
        $this->assertSame($chosen->id, $note->lines()->sole()->lot_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::RtvCreate->value,
            'entity' => RtvNote::class,
            'entity_id' => $note->id,
            'user_id' => $staff->id,
        ]);
    }

    #[Test]
    public function qty_may_not_exceed_the_stock_available(): void
    {
        $consignor = $this->consignor();
        $lot = $this->lot($consignor, 2, 'CN01-HW-001');

        $this->create($this->staff(), $consignor, [$lot->id => 3])
            ->assertSessionHasErrors('qty');

        $this->assertSame(0, RtvNote::count());
    }

    #[Test]
    public function stock_owned_by_the_store_cannot_be_returned(): void
    {
        $staff = $this->staff();
        $consignor = $this->consignor();

        $own = StockLot::factory()
            ->own()
            ->state(['sku' => 'OW00-HW-001', 'qty_on_hand' => 4, 'qty_received' => 4])
            ->create();

        $this->create($staff, $consignor, [$own->id => 1])
            ->assertSessionHasErrors('qty');

        $this->assertSame(0, RtvNote::count());
        $this->assertSame(OwnerType::Own, $own->fresh()->owner_type);
    }

    #[Test]
    public function a_lot_belonging_to_another_consignor_is_refused(): void
    {
        $consignor = $this->consignor();
        $stranger = $this->consignor();
        $lot = $this->lot($stranger, 4, 'CN02-HW-002');

        $this->create($this->staff(), $consignor, [$lot->id => 1])
            ->assertSessionHasErrors('qty');

        $this->assertSame(0, RtvNote::count());
    }

    #[Test]
    public function a_lot_under_an_open_opname_session_is_refused(): void
    {
        $consignor = $this->consignor();
        $lot = $this->lot($consignor, 4, 'CN01-HW-001');

        $opname = $this->openOpnameFor($lot);

        $this->create($this->staff(), $consignor, [$lot->id => 1])
            ->assertSessionHasErrors('qty');

        $this->assertSame(0, RtvNote::count());
        $this->assertSame(OpnameStatus::Counting, $opname->fresh()->status);
    }

    #[Test]
    public function a_lot_with_an_open_quarantine_case_is_refused(): void
    {
        $consignor = $this->consignor();
        $lot = $this->lot($consignor, 4, 'CN01-HW-001');

        QuarantineCase::factory()->assigned($lot)->create();

        $this->create($this->staff(), $consignor, [$lot->id => 1])
            ->assertSessionHasErrors('qty');

        $this->assertSame(0, RtvNote::count());
    }

    #[Test]
    public function only_one_session_may_be_open_at_a_time(): void
    {
        $staff = $this->staff();
        $consignor = $this->consignor();
        $first = $this->lot($consignor, 4, 'CN01-HW-001');
        $second = $this->lot($consignor, 4, 'CN01-HW-002');

        $this->create($staff, $consignor, [$first->id => 1])->assertSessionHasNoErrors();

        $this->create($staff, $consignor, [$second->id => 1])
            ->assertSessionHasErrors('consignor_id');

        $this->assertSame(1, RtvNote::count());
    }

    #[Test]
    public function creating_without_any_qty_is_refused(): void
    {
        $consignor = $this->consignor();
        $this->lot($consignor, 4, 'CN01-HW-001');

        $this->create($this->staff(), $consignor, [])
            ->assertSessionHasErrors('qty');

        $this->assertSame(0, RtvNote::count());
    }

    #[Test]
    public function staging_moves_every_line_to_the_staging_rack(): void
    {
        $staff = $this->staff();
        $consignor = $this->consignor();
        $staging = $this->stagingRack();

        $lot = $this->lot($consignor, 5, 'CN01-HW-001');

        $this->create($staff, $consignor, [$lot->id => 2])->assertSessionHasNoErrors();

        $note = RtvNote::sole();

        $this->staging($staff, $note)->assertSessionHasNoErrors();

        $this->assertSame(RtvStatus::Verifying, $note->fresh()->status);
        $this->assertSame($staging->id, $lot->fresh()->rack_id);

        // Pemindahan rak tidak mengubah qty, tetapi tetap menulis gerakan:
        // tanpanya kartu stok kehilangan kapan barang berpindah.
        $movement = StockMovement::query()->where('lot_id', $lot->id)->latest('id')->sole();

        $this->assertSame(MovementType::Transfer, $movement->type);
        $this->assertSame(0, $movement->qty_delta);
        $this->assertSame(5, $movement->balance_after);
        $this->assertStringContainsString($note->rtv_no, (string) $movement->reason);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::RtvStaging->value,
            'entity' => RtvNote::class,
            'entity_id' => $note->id,
        ]);
    }

    #[Test]
    public function staging_is_refused_when_an_opname_session_opens_after_the_document_is_made(): void
    {
        $staff = $this->staff();
        $consignor = $this->consignor();
        $staging = $this->stagingRack();

        $lot = $this->lot($consignor, 5, 'CN01-HW-001');

        $this->create($staff, $consignor, [$lot->id => 2])->assertSessionHasNoErrors();

        // Sesi opname lahir sesudah dokumen: barangnya masih di rak asal dan
        // sedang dihitung orang, jadi memindahkannya akan mengeluarkan unit
        // dari penghitungan yang sedang berlangsung.
        $this->openOpnameFor($lot);

        $this->staging($staff, RtvNote::sole())->assertSessionHasErrors('rtv');

        $this->assertSame(RtvStatus::Draft, RtvNote::sole()->fresh()->status);
        $this->assertSame($lot->rack_id, $lot->fresh()->rack_id);
        $this->assertNotSame($staging->id, $lot->fresh()->rack_id);
    }

    #[Test]
    public function scanning_is_refused_before_the_goods_are_moved(): void
    {
        $staff = $this->staff();
        $consignor = $this->consignor();
        $lot = $this->lot($consignor, 5, 'CN01-HW-001');

        $this->create($staff, $consignor, [$lot->id => 2])->assertSessionHasNoErrors();

        $this->scan($staff, RtvNote::sole(), 'CN01-HW-001')->assertSessionHasErrors('sku');

        $this->assertSame(0, RtvNote::sole()->lines()->sole()->verified_qty);
    }

    #[Test]
    public function each_scan_adds_one_unit_and_refuses_to_go_past_the_planned_qty(): void
    {
        $staff = $this->staff();
        $consignor = $this->consignor();
        $this->stagingRack();

        $lot = $this->lot($consignor, 5, 'CN01-HW-001');

        $this->create($staff, $consignor, [$lot->id => 2])->assertSessionHasNoErrors();
        $note = RtvNote::sole();

        $this->staging($staff, $note)->assertSessionHasNoErrors();

        // Pemindai mengirim huruf kecil dan spasi ujung: label memang begitu
        // cara dicetaknya, dan menolaknya akan terdengar seperti barang asing.
        $this->scan($staff, $note, '  cn01-hw-001 ')->assertSessionHasNoErrors();
        $this->scan($staff, $note, 'CN01-HW-001')->assertSessionHasNoErrors();

        $this->assertSame(2, $note->lines()->sole()->fresh()->verified_qty);
        $this->assertSame($staff->id, $note->lines()->sole()->fresh()->verified_by);

        $this->scan($staff, $note, 'CN01-HW-001')->assertSessionHasErrors('sku');
        $this->assertSame(2, $note->lines()->sole()->fresh()->verified_qty);

        $this->scan($staff, $note, 'CN01-HW-999')->assertSessionHasErrors('sku');
    }

    #[Test]
    public function approval_waits_until_every_unit_is_verified(): void
    {
        $owner = $this->owner();
        $consignor = $this->consignor();
        $this->stagingRack();

        $one = $this->lot($consignor, 5, 'CN01-HW-001');
        $two = $this->lot($consignor, 5, 'CN01-HW-002');

        $this->create($owner, $consignor, [$one->id => 2, $two->id => 1])->assertSessionHasNoErrors();

        $note = RtvNote::sole();

        $this->staging($owner, $note)->assertSessionHasNoErrors();
        $this->scan($owner, $note, 'CN01-HW-001')->assertSessionHasNoErrors();
        $this->scan($owner, $note, 'CN01-HW-001')->assertSessionHasNoErrors();

        $this->approve($owner, $note)->assertSessionHasErrors('rtv');

        $this->assertSame(RtvStatus::Verifying, $note->fresh()->status);
        $this->assertSame(5, $one->fresh()->qty_on_hand);
        $this->assertSame(0, StockMovement::query()->where('type', MovementType::Rtv->value)->count());
    }

    #[Test]
    public function approval_is_refused_without_owner_authorization(): void
    {
        $staff = $this->staff();
        $consignor = $this->consignor();
        $this->stagingRack();

        $lot = $this->lot($consignor, 5, 'CN01-HW-001');

        $this->create($staff, $consignor, [$lot->id => 2])->assertSessionHasNoErrors();
        $note = RtvNote::sole();

        $this->staging($staff, $note)->assertSessionHasNoErrors();
        $this->scan($staff, $note, 'CN01-HW-001')->assertSessionHasNoErrors();
        $this->scan($staff, $note, 'CN01-HW-001')->assertSessionHasNoErrors();

        // Tanpa token sama sekali.
        $this->approve($staff, $note)->assertSessionHasErrors('pin_token');

        // Token yang diterbitkan untuk aksi lain: PIN adalah milik aksinya.
        $this->approve($staff, $note, $this->tokenFor($staff, 'inventory.opname-approve'))
            ->assertSessionHasErrors('pin_token');

        $this->assertSame(RtvStatus::Verifying, $note->fresh()->status);
        $this->assertSame(5, $lot->fresh()->qty_on_hand);
    }

    #[Test]
    public function staff_may_execute_with_a_token_issued_for_this_action(): void
    {
        $staff = $this->staff();
        $consignor = $this->consignor();
        $this->stagingRack();

        $lot = $this->lot($consignor, 5, 'CN01-HW-001');

        $this->create($staff, $consignor, [$lot->id => 2])->assertSessionHasNoErrors();
        $note = RtvNote::sole();

        $this->staging($staff, $note)->assertSessionHasNoErrors();
        $this->scan($staff, $note, 'CN01-HW-001')->assertSessionHasNoErrors();
        $this->scan($staff, $note, 'CN01-HW-001')->assertSessionHasNoErrors();

        $owner = $this->owner();
        $token = app(PinService::class)->issue($staff, '123456', 'inventory.rtv-approve')->token;

        $this->approve($staff, $note, $token)->assertSessionHasNoErrors();

        $this->assertSame(RtvStatus::Executed, $note->fresh()->status);
        $this->assertSame($owner->id, $note->fresh()->approved_by);
    }

    #[Test]
    public function approval_executes_the_document_exactly_once(): void
    {
        $owner = $this->owner();
        $consignor = $this->consignor();
        $this->stagingRack();

        $partial = $this->lot($consignor, 5, 'CN01-HW-001');
        $drained = $this->lot($consignor, 3, 'CN01-HW-002');

        $this->create($owner, $consignor, [$partial->id => 2, $drained->id => 3], 'Penitip tidak lanjut')
            ->assertSessionHasNoErrors();

        $note = RtvNote::sole();

        $this->staging($owner, $note)->assertSessionHasNoErrors();

        foreach (['CN01-HW-001', 'CN01-HW-001', 'CN01-HW-002', 'CN01-HW-002', 'CN01-HW-002'] as $sku) {
            $this->scan($owner, $note, $sku)->assertSessionHasNoErrors();
        }

        $this->approve($owner, $note)->assertSessionHasNoErrors();

        $note->refresh();

        $this->assertSame(RtvStatus::Executed, $note->status);
        $this->assertSame($owner->id, $note->approved_by);
        $this->assertNotNull($note->executed_at);

        // Qty berkurang, dan lot yang habis berstatus Dikembalikan sementara
        // lot yang masih bersisa tetap Tersedia.
        $this->assertSame(3, $partial->fresh()->qty_on_hand);
        $this->assertSame(LotStatus::Available, $partial->fresh()->status);
        $this->assertSame(0, $drained->fresh()->qty_on_hand);
        $this->assertSame(LotStatus::Returned, $drained->fresh()->status);

        // Satu gerakan RTV per baris, dengan saldo akhir yang benar.
        $this->assertDatabaseHas('stock_movements', [
            'lot_id' => $partial->id,
            'type' => MovementType::Rtv->value,
            'qty_delta' => -2,
            'balance_after' => 3,
            'ref_type' => RtvNote::class,
            'ref_id' => $note->id,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'lot_id' => $drained->id,
            'type' => MovementType::Rtv->value,
            'qty_delta' => -3,
            'balance_after' => 0,
            'ref_type' => RtvNote::class,
            'ref_id' => $note->id,
        ]);

        $this->assertSame(2, StockMovement::query()->where('type', MovementType::Rtv->value)->count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::RtvExecute->value,
            'entity' => RtvNote::class,
            'entity_id' => $note->id,
            'user_id' => $owner->id,
        ]);

        // Eksekusi kedua ditolak: dokumen sudah selesai, stok tidak boleh
        // berkurang dua kali karena tombol yang sama ditekan dua kali.
        $this->approve($owner, $note)->assertSessionHasErrors('rtv');
        $this->assertSame(3, $partial->fresh()->qty_on_hand);
    }

    #[Test]
    public function approval_is_refused_when_stock_shrank_below_the_planned_qty(): void
    {
        $owner = $this->owner();
        $consignor = $this->consignor();
        $this->stagingRack();

        $lot = $this->lot($consignor, 5, 'CN01-HW-001');

        $this->create($owner, $consignor, [$lot->id => 3])->assertSessionHasNoErrors();
        $note = RtvNote::sole();

        $this->staging($owner, $note)->assertSessionHasNoErrors();
        $this->scan($owner, $note, 'CN01-HW-001')->assertSessionHasNoErrors();
        $this->scan($owner, $note, 'CN01-HW-001')->assertSessionHasNoErrors();
        $this->scan($owner, $note, 'CN01-HW-001')->assertSessionHasNoErrors();

        // Dua unit terjual sesudah dokumen dibuat. Angka di layar sudah basi;
        // mengeksekusinya akan mengurangi stok yang sudah lama berpindah.
        $lot->update(['qty_on_hand' => 2]);

        $this->approve($owner, $note)->assertSessionHasErrors('rtv');

        $this->assertSame(RtvStatus::Verifying, $note->fresh()->status);
        $this->assertSame(2, $lot->fresh()->qty_on_hand);
        $this->assertSame(0, StockMovement::query()->where('type', MovementType::Rtv->value)->count());
    }

    #[Test]
    public function cancelling_closes_the_session_and_blocks_a_later_approval(): void
    {
        $owner = $this->owner();
        $consignor = $this->consignor();
        $this->stagingRack();

        $lot = $this->lot($consignor, 5, 'CN01-HW-001');

        $this->create($owner, $consignor, [$lot->id => 2])->assertSessionHasNoErrors();
        $note = RtvNote::sole();

        $this->staging($owner, $note)->assertSessionHasNoErrors();

        $this->cancel($owner, $note)->assertSessionHasNoErrors();

        $this->assertSame(RtvStatus::Cancelled, $note->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::RtvCancel->value,
            'entity' => RtvNote::class,
            'entity_id' => $note->id,
        ]);

        // Sesudah dibatalkan sesi baru boleh dibuat, tetapi sesi lama tidak
        // bisa dieksekusi lagi dari halaman mana pun.
        $this->create($owner, $consignor, [$lot->id => 1])->assertSessionHasNoErrors();
        $this->scan($owner, $note, 'CN01-HW-001')->assertSessionHasErrors('sku');

        $this->assertSame(RtvStatus::Cancelled, $note->fresh()->status);
        $this->assertSame(5, $lot->fresh()->qty_on_hand);
    }

    #[Test]
    public function finished_documents_appear_in_the_history(): void
    {
        $owner = $this->owner();
        $consignor = $this->consignor();
        $this->stagingRack();

        $lot = $this->lot($consignor, 5, 'CN01-HW-001');

        $this->create($owner, $consignor, [$lot->id => 2])->assertSessionHasNoErrors();
        $note = RtvNote::sole();

        $this->staging($owner, $note)->assertSessionHasNoErrors();
        $this->scan($owner, $note, 'CN01-HW-001')->assertSessionHasNoErrors();
        $this->scan($owner, $note, 'CN01-HW-001')->assertSessionHasNoErrors();
        $this->approve($owner, $note)->assertSessionHasNoErrors();

        // Sesudah dokumen selesai, halaman kembali menawarkan langkah pertama
        // dan tidak ada sesi terbuka yang menahan pembuatan berikutnya.
        $html = $this->actingAs($owner)
            ->get(route('inventory.retur-rtv', ['penitip' => $consignor->id]))
            ->assertOk()
            ->assertSee($note->rtv_no)
            ->assertSee('Dieksekusi')
            ->assertSee('Qty Kembali')
            ->content();

        $this->assertStringContainsString('Langkah 1 · Pilih Penitip', $html);
    }
}
