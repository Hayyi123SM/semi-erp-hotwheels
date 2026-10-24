<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\AdjustmentReason;
use App\Enums\AuditAction;
use App\Enums\LotStatus;
use App\Enums\MovementType;
use App\Enums\OpnameLineStatus;
use App\Enums\OpnameScope;
use App\Enums\OpnameStatus;
use App\Enums\ShiftStatus;
use App\Models\Opname;
use App\Models\OpnameLine;
use App\Models\Rack;
use App\Models\Shift;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\OpnameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sesi Stok Opname: mulai, hitung, ajukan, putuskan (FR-IC-20..23).
 *
 * Empat hal yang dijaga di sini, karena keempatnya gugur diam-diam kalau hanya
 * diperiksa di template:
 *
 * 1. **Blind benar-benar blind.** Angka sistem tidak boleh ada di HTML sesi
 *    yang sedang menghitung -- bukan disembunyikan dengan CSS, tidak dikirim
 *    sama sekali. Ujiannya bukan "kolomnya tidak terlihat" melainkan "angkanya
 *    tidak pernah muncul sebagai teks mana pun".
 * 2. **Submit itu sesi, bukan baris.** Semua baris wajib terhitung; baris yang
 *    stoknya bergerak setelah dihitung tetap boleh diajukan, karena
 *    selisihnya dihitung ulang saat keputusan -- tidak pernah dipakai
 *    angka yang sudah usang.
 * 3. **Keputusan mengubah stok sekali.** Menerima menulis gerakan penyesuaian
 *    dan mengubah qty; menolak tidak menyentuh apa pun. Bila stok sudah
 *    bergerak mengikuti hitungan di antara hitung dan putus, tidak ada
 *    selisih yang tersisa dan baris tuntas sebagai `Ok`.
 * 4. **Modul ini Owner-only.** Staff hanya memegang POS, jadi seluruh route
 *    stok-opname -- termasuk halaman dan keputusan review -- menolak Staff
 *    dengan 403 lewat grup `['auth', 'owner']`.
 * 5. **Gerakan di antara hitung dan putus diserap, bukan diulang.** Sesi
 *    tidak macet karena barang laku setelah dihitung; dan sesi yang diajukan
 *    bisa dibuka ulang untuk mengoreksi hitungan selama belum ada satu baris
 *    pun yang diputuskan.
 */
class StokOpnameTest extends TestCase
{
    use RefreshDatabase;

    private function rack(): Rack
    {
        return Rack::factory()->create();
    }

    private function lot(Rack $rack, int $qty, string $sku): StockLot
    {
        return StockLot::factory()
            ->state([
                'sku' => $sku,
                'rack_id' => $rack->id,
                'qty_on_hand' => $qty,
                'qty_received' => $qty,
            ])
            ->create();
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    private function start(User $actor, array $payload = []): TestResponse
    {
        return $this->actingAs($actor)
            ->from(route('inventory.stok-opname'))
            ->post(
                route('inventory.stok-opname.store'),
                array_merge(['scope' => OpnameScope::All->value], $payload),
            );
    }

    private function countLine(User $actor, Opname $opname, OpnameLine $line, int $qty): TestResponse
    {
        return $this->actingAs($actor)
            ->from(route('inventory.stok-opname'))
            ->post(route('inventory.stok-opname.hitung', [$opname, $line]), [
                'counted_qty' => $qty,
            ]);
    }

    private function submit(User $actor, Opname $opname): TestResponse
    {
        return $this->actingAs($actor)
            ->from(route('inventory.stok-opname'))
            ->post(route('inventory.stok-opname.ajukan', $opname));
    }

    private function reopen(User $actor, Opname $opname): TestResponse
    {
        return $this->actingAs($actor)
            ->from(route('inventory.stok-opname'))
            ->post(route('inventory.stok-opname.buka-ulang', $opname));
    }

    private function review(
        User $actor,
        Opname $opname,
        OpnameLine $line,
        string $decision,
        ?AdjustmentReason $reason = null,
    ): TestResponse {
        return $this->actingAs($actor)
            ->from(route('inventory.stok-opname'))
            ->post(route('inventory.stok-opname.review', [$opname, $line]), array_filter([
                'decision' => $decision,
                'reason' => $reason?->value,
            ], fn ($value) => $value !== null));
    }

    /** Pindai satu SKU lewat fetch, seperti bar-scan di halaman (JSON). */
    private function scanLine(User $actor, Opname $opname, OpnameLine $line): TestResponse
    {
        return $this->actingAs($actor)
            ->postJson(route('inventory.stok-opname.tambah', [$opname, $line]));
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $this->get(route('inventory.stok-opname'))->assertRedirect(route('login'));
    }

    #[Test]
    public function starting_a_session_snapshots_every_lot_and_keeps_the_numbers_off_the_page(): void
    {
        $actor = $this->owner();
        $rack = $this->rack();

        // 41 dipilih supaya angkanya mudah dicari di HTML: ia tidak mungkin
        // menjadi id baris atau kode rak dalam tes ini.
        $this->lot($rack, 41, 'CN01-HW-001');
        $this->lot($rack, 7, 'CN01-HW-002');

        $this->start($actor)->assertRedirect(route('inventory.stok-opname'))->assertSessionHas('toast');

        $opname = Opname::sole();
        $this->assertSame(OpnameStatus::Counting, $opname->status);
        $this->assertSame(OpnameScope::All, $opname->scope);

        $lines = $opname->lines()->orderBy('lot_id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame([41, 7], $lines->pluck('system_qty')->all());
        $this->assertSame(OpnameLineStatus::Pending, $lines->first()->status);

        $html = $this->actingAs($actor)->get(route('inventory.stok-opname'))->assertOk()->content();

        $this->assertStringContainsString('CN01-HW-001', $html);
        $this->assertStringContainsString('CN01-HW-002', $html);

        // Blind: kolomnya tidak ada, dan angka sistemnya tidak pernah jadi
        // teks di halaman mana pun -- bukan sekadar disembunyikan.
        $this->assertStringNotContainsString('Qty Sistem', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*41\s*</', $html);

        // Sekarang sesi diajukan: angka yang sama muncul, bukti bahwa
        // ketidakhadirannya di atas memang karena blind, bukan karena
        // kolomnya tidak pernah ada.
        $lines->each(fn (OpnameLine $line) => $this->countLine($actor, $opname, $line, (int) $line->system_qty));
        $this->submit($actor, $opname)->assertSessionMissing('errors');

        $html = $this->actingAs($actor)->get(route('inventory.stok-opname'))->assertOk()->content();
        $this->assertMatchesRegularExpression('/>\s*41\s*</', $html);
    }

    #[Test]
    public function a_second_session_is_refused_while_one_is_still_open(): void
    {
        $actor = $this->owner();
        $this->lot($this->rack(), 3, 'CN01-HW-001');

        $this->start($actor)->assertSessionMissing('errors');
        $this->start($actor)->assertSessionHasErrors('scope');

        $this->assertSame(1, Opname::query()->count());
    }

    #[Test]
    public function a_session_for_a_rack_or_a_sku_only_counts_what_is_in_scope(): void
    {
        $actor = $this->owner();
        $rackA = $this->rack();
        $rackB = $this->rack();
        $inA = $this->lot($rackA, 4, 'CN01-HW-001');
        $inB = $this->lot($rackB, 5, 'CN01-HW-002');

        $this->start($actor, ['scope' => OpnameScope::Rack->value, 'rack_id' => $rackA->id])
            ->assertSessionMissing('errors');

        $opname = Opname::sole();
        $this->assertSame($rackA->id, $opname->rack_id);
        $this->assertSame([$inA->id], $opname->lines()->pluck('lot_id')->all());

        // Sesi kedua harus kalah; cakupan SKU diuji lewat sesi yang sudah
        // ditutup, supaya tesnya menguji cakupan, bukan penjaga sesi ganda.
        $this->actingAs($actor)
            ->post(route('inventory.stok-opname.batal', $opname))
            ->assertSessionHas('toast');

        $this->start($actor, ['scope' => OpnameScope::Sku->value, 'sku' => 'cn01-hw-002'])
            ->assertSessionMissing('errors');

        $opname = Opname::query()->latest('id')->first();
        $this->assertSame('CN01-HW-002', $opname->scope_value);
        $this->assertSame([$inB->id], $opname->lines()->pluck('lot_id')->all());
    }

    #[Test]
    public function a_count_is_recorded_and_can_be_replaced_by_a_recount(): void
    {
        $actor = $this->owner();
        $rack = $this->rack();
        $this->lot($rack, 5, 'CN01-HW-001');

        $this->start($actor)->assertSessionMissing('errors');
        $opname = Opname::sole();
        $line = $opname->lines()->sole();

        $this->countLine($actor, $opname, $line, 4)->assertSessionHas('toast');

        $line->refresh();
        $this->assertSame(4, $line->counted_qty);
        $this->assertSame(-1, $line->diff_qty);
        $this->assertSame(OpnameLineStatus::Counted, $line->status);
        $this->assertSame($actor->id, $line->counted_by);
        $this->assertNotNull($line->counted_at);
        $this->assertNotNull($line->counted_movement_id);

        // Menghitung ulang menggantikan angka lama, bukan menumpuknya: diff
        // dihitung ulang terhadap snapshot, dan keputusan lama ikut hilang.
        $this->countLine($actor, $opname, $line, 5)->assertSessionHas('toast');

        $line->refresh();
        $this->assertSame(5, $line->counted_qty);
        $this->assertSame(0, $line->diff_qty);
        $this->assertSame(OpnameLineStatus::Ok, $line->status);
        $this->assertNull($line->reason);
    }

    #[Test]
    public function a_count_zero_means_the_lot_is_empty_not_the_input_was_ignored(): void
    {
        $actor = $this->owner();
        $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($actor);
        $opname = Opname::sole();

        $this->countLine($actor, $opname, $opname->lines()->sole(), 0)->assertSessionHas('toast');

        $line = $opname->lines()->sole();
        $this->assertSame(0, $line->counted_qty);
        $this->assertSame(-5, $line->diff_qty);
        $this->assertSame(OpnameLineStatus::Counted, $line->status);
    }

    #[Test]
    public function a_negative_or_absurd_count_is_refused_by_the_form(): void
    {
        $actor = $this->owner();
        $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($actor);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();

        $this->countLine($actor, $opname, $line, -1)->assertSessionHasErrors('counted_qty');
        $this->countLine($actor, $opname, $line, 1_000_000)->assertSessionHasErrors('counted_qty');

        $this->assertSame(OpnameLineStatus::Pending, $line->refresh()->status);
    }

    #[Test]
    public function a_scan_increment_adds_one_to_the_recorded_count(): void
    {
        $actor = $this->owner();
        $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($actor);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();

        // Balasan JSON hanya memuat yang boleh keluar selama blind count:
        // qty terhitung dan status baris, tanpa angka sistem maupun selisih.
        $this->scanLine($actor, $opname, $line)
            ->assertOk()
            ->assertJson([
                'line_id' => $line->id,
                'counted_qty' => 1,
                'status' => OpnameLineStatus::Counted->value,
                'status_label' => OpnameLineStatus::Counted->label(),
                'status_type' => 'warning',
            ]);

        $line->refresh();
        $this->assertSame(1, $line->counted_qty);
        $this->assertSame(-4, $line->diff_qty);
        $this->assertSame(OpnameLineStatus::Counted, $line->status);
        $this->assertSame($actor->id, $line->counted_by);
        $this->assertNotNull($line->counted_at);
        $this->assertNotNull($line->counted_movement_id);

        // Dua pindai cepat untuk lot yang sama = dua kenaikan, bukan satu
        // angka yang ditimpa angka kedaluwarsa.
        $this->scanLine($actor, $opname, $opname->lines()->sole())
            ->assertOk()
            ->assertJson(['counted_qty' => 2]);

        $this->assertSame(2, $opname->lines()->sole()->refresh()->counted_qty);
    }

    #[Test]
    public function a_scan_increment_is_refused_once_the_session_leaves_counting(): void
    {
        $actor = $this->owner();
        $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($actor);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();

        $this->countLine($actor, $opname, $line, 5);
        $this->submit($actor, $opname);

        $this->scanLine($actor, $opname, $line)
            ->assertStatus(422)
            ->assertJsonValidationErrors('counted_qty');

        $this->assertSame(5, $line->refresh()->counted_qty);
    }

    #[Test]
    public function a_scan_increment_is_refused_beyond_the_cap(): void
    {
        $actor = $this->owner();
        $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($actor);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();
        $this->countLine($actor, $opname, $line, OpnameService::MAX_COUNTED_QTY);

        $this->scanLine($actor, $opname, $line)
            ->assertStatus(422)
            ->assertJsonValidationErrors('counted_qty');

        $this->assertSame(OpnameService::MAX_COUNTED_QTY, $line->refresh()->counted_qty);
    }

    #[Test]
    public function a_scan_increment_for_a_line_of_another_session_is_not_found(): void
    {
        $actor = $this->owner();
        $rack = $this->rack();
        $this->lot($rack, 5, 'CN01-HW-001');
        $this->lot($rack, 3, 'CN01-HW-002');

        $this->start($actor);
        $first = Opname::sole();
        $foreignLine = $first->lines()->orderBy('lot_id')->first();

        $this->actingAs($actor)->post(route('inventory.stok-opname.batal', $first));
        $this->start($actor);
        $second = Opname::query()->latest('id')->first();

        // Pengikatan baris terhadap sesinya juga berlaku untuk pindai: baris
        // sesi lama tidak bisa ditambah lewat URL sesi yang sedang berjalan.
        $this->actingAs($actor)
            ->postJson(route('inventory.stok-opname.tambah', [$second, $foreignLine]))
            ->assertNotFound();
    }

    #[Test]
    public function submitting_is_refused_until_every_line_is_counted(): void
    {
        $actor = $this->owner();
        $rack = $this->rack();
        $this->lot($rack, 5, 'CN01-HW-001');
        $this->lot($rack, 3, 'CN01-HW-002');

        $this->start($actor);
        $opname = Opname::sole();

        $first = $opname->lines()->orderBy('lot_id')->first();
        $this->countLine($actor, $opname, $first, 5);

        $this->submit($actor, $opname)->assertSessionHasErrors('opname');

        $this->assertSame(OpnameStatus::Counting, $opname->refresh()->status);
        $this->assertNull($opname->submitted_at);
    }

    #[Test]
    public function submitting_after_a_movement_is_allowed_and_the_approval_absorbs_it(): void
    {
        $actor = $this->owner();
        $lot = $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($actor);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();

        $this->countLine($actor, $opname, $line, 3);

        // Sebuah penjualan di antara hitung dan ajukan. Sesi dulu menolaknya
        // dan menyuruh menghitung ulang; sekarang penjualan ini tinggal masuk
        // hitungan pada keputusan: ekspektasi terkini (5 - 1 = 4) menghasilkan
        // selisih 3 - 4 = -1, bukan nilai usang -2 yang dihitung saat baris
        // dipindai.
        StockMovement::factory()->create([
            'lot_id' => $lot->id,
            'type' => MovementType::Sale,
            'qty_delta' => -1,
            'balance_after' => 4,
        ]);
        $lot->update(['qty_on_hand' => 4]);

        $this->submit($actor, $opname)->assertSessionHas('toast');
        $this->assertSame(OpnameStatus::PendingApproval, $opname->refresh()->status);

        $this->review($actor, $opname, $line, 'APPROVE', AdjustmentReason::Lost)
            ->assertSessionMissing('errors');

        // Stok menyesuaikan ke angka hitungan fisik: 3 unit.
        $this->assertSame(3, $lot->refresh()->qty_on_hand);

        $movement = StockMovement::query()
            ->where('lot_id', $lot->id)
            ->where('type', MovementType::AdjMinus)
            ->sole();
        $this->assertSame(-1, $movement->qty_delta);
        $this->assertSame(AdjustmentReason::Lost->value, $movement->reason);

        $this->assertSame(OpnameLineStatus::Approved, $line->refresh()->status);
        $this->assertSame(OpnameStatus::Approved, $opname->refresh()->status);
    }

    #[Test]
    public function a_movement_that_matches_the_count_leaves_nothing_to_decide(): void
    {
        $actor = $this->owner();
        $lot = $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($actor);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();

        $this->countLine($actor, $opname, $line, 4);
        $this->submit($actor, $opname);

        // Satu unit laku setelah baris dihitung: ekspektasi terkini turun ke 4
        // dan langsung sama dengan hitungan fisik. Tidak ada selisih yang perlu
        // disetujui atau ditolak -- baris tuntas apa adanya.
        StockMovement::factory()->create([
            'lot_id' => $lot->id,
            'type' => MovementType::Sale,
            'qty_delta' => -1,
            'balance_after' => 4,
        ]);
        $lot->update(['qty_on_hand' => 4]);

        $this->review($actor, $opname, $line, 'APPROVE', AdjustmentReason::Lost)
            ->assertSessionMissing('errors');

        $this->assertSame(4, $lot->refresh()->qty_on_hand);
        $this->assertSame(OpnameLineStatus::Ok, $line->refresh()->status);
        $this->assertSame(0, StockMovement::query()->where('type', 'like', 'ADJ_%')->count());
        $this->assertSame(OpnameStatus::Approved, $opname->refresh()->status);
    }

    #[Test]
    public function a_count_is_refused_when_the_ledger_and_the_stock_disagree(): void
    {
        $actor = $this->owner();
        $lot = $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($actor);
        $opname = Opname::sole();

        // Gerakan tanpa perubahan qty: ekspektasi baris naik, stok tidak.
        // Hitung di atas angka seperti ini akan menumpuk selisih yang salah
        // sasangan, jadi barisnya ditolak lebih dulu.
        StockMovement::factory()->create([
            'lot_id' => $lot->id,
            'type' => MovementType::Transfer,
            'qty_delta' => 1,
            'balance_after' => 5,
        ]);

        $this->countLine($actor, $opname, $opname->lines()->sole(), 5)
            ->assertSessionHasErrors('counted_qty');

        $this->assertSame(OpnameLineStatus::Pending, $opname->lines()->sole()->status);
    }

    #[Test]
    public function submitting_puts_the_session_up_for_review_and_then_shows_the_diff(): void
    {
        $actor = $this->owner();
        $rack = $this->rack();
        $this->lot($rack, 5, 'CN01-HW-001');
        $this->lot($rack, 3, 'CN01-HW-002');

        $this->start($actor);
        $opname = Opname::sole();

        foreach ($opname->lines()->orderBy('lot_id')->get() as $line) {
            $this->countLine($actor, $opname, $line, (int) $line->system_qty - 1);
        }

        $this->submit($actor, $opname)->assertSessionHas('toast');

        $opname->refresh();
        $this->assertSame(OpnameStatus::PendingApproval, $opname->status);
        $this->assertNotNull($opname->submitted_at);

        $html = $this->actingAs($actor)->get(route('inventory.stok-opname'))->assertOk()->content();

        // Sekarang selisih memang layak ditampilkan: sesi sudah diajukan,
        // penghitung tidak lagi menghitung angka yang bisa ia ubah.
        // Jendela blind tertutup begitu sesi diajukan: kolom sistem dan
        // selisihnya kini tampil, karena angka itu sudah tidak bisa lagi
        // memengaruhi hitungan yang sedang berjalan.
        $this->assertStringContainsString('Menunggu Persetujuan', $html);
        $this->assertStringContainsString('Qty Sistem', $html);
        $this->assertMatchesRegularExpression('/>\s*-1\s*</', $html);

        $html = $this->actingAs($actor)->get(route('inventory.stok-opname'))->assertOk()->content();
        $this->assertMatchesRegularExpression('/>\s*4\s*</', $html);
        $this->assertMatchesRegularExpression('/>\s*-1\s*</', $html);
    }

    #[Test]
    public function approving_a_shortage_changes_the_stock_and_writes_the_adjustment(): void
    {
        $owner = $this->owner();
        $lot = $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($owner);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();
        $this->countLine($owner, $opname, $line, 4);
        $this->submit($owner, $opname);

        $this->review($owner, $opname, $line, 'APPROVE', AdjustmentReason::Lost)
            ->assertSessionHas('toast');

        $lot->refresh();
        $this->assertSame(4, $lot->qty_on_hand);

        $movement = StockMovement::query()->where('lot_id', $lot->id)->where('type', MovementType::AdjMinus)->sole();
        $this->assertSame(-1, $movement->qty_delta);
        $this->assertSame(4, $movement->balance_after);
        $this->assertSame(AdjustmentReason::Lost->value, $movement->reason);
        $this->assertSame(Opname::class, $movement->ref_type);
        $this->assertSame($opname->id, $movement->ref_id);
        $this->assertSame($owner->id, $movement->actor_id);

        $line->refresh();
        $this->assertSame(OpnameLineStatus::Approved, $line->status);
        $this->assertSame($owner->id, $line->approved_by);

        // Satu baris, satu keputusan: sesi ikut tertutup supaya tidak ada
        // pintu review yang terbuka setelah semua keputusan selesai.
        $this->assertSame(OpnameStatus::Approved, $opname->refresh()->status);
        $this->assertNotNull($opname->closed_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::OpnameApprove->value,
            'entity' => OpnameLine::class,
            'entity_id' => $line->id,
            'user_id' => $owner->id,
        ]);
    }

    #[Test]
    public function approving_a_surplus_never_applies_a_reason_meant_for_a_shortage(): void
    {
        $owner = $this->owner();
        $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($owner);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();
        $this->countLine($owner, $opname, $line, 6);
        $this->submit($owner, $opname);

        // "Hilang" untuk stok yang bertambah membuat catatan yang menyalah
        // sendiri, dan catatan itu akan dibaca orang selamanya.
        $this->review($owner, $opname, $line, 'APPROVE', AdjustmentReason::Lost)
            ->assertSessionHasErrors('reason');

        $this->assertSame(5, StockLot::sole()->qty_on_hand);
        $this->assertSame(OpnameLineStatus::Counted, $line->refresh()->status);

        $this->review($owner, $opname, $line, 'APPROVE', AdjustmentReason::Found)
            ->assertSessionMissing('errors');

        $this->assertSame(6, StockLot::sole()->qty_on_hand);
        $this->assertDatabaseHas('stock_movements', [
            'lot_id' => StockLot::sole()->id,
            'type' => MovementType::AdjPlus,
            'qty_delta' => 1,
            'reason' => AdjustmentReason::Found->value,
        ]);
    }

    #[Test]
    public function rejecting_a_diff_leaves_the_stock_exactly_as_it_was(): void
    {
        $owner = $this->owner();
        $lot = $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($owner);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();
        $this->countLine($owner, $opname, $line, 4);
        $this->submit($owner, $opname);

        $this->review($owner, $opname, $line, 'REJECT')->assertSessionHas('toast');

        $line->refresh();
        $this->assertSame(OpnameLineStatus::Rejected, $line->status);
        $this->assertSame($owner->id, $line->approved_by);
        $this->assertNull($line->reason);

        $this->assertSame(5, $lot->refresh()->qty_on_hand);
        $this->assertSame(0, StockMovement::query()->where('lot_id', $lot->id)->where('type', 'like', 'ADJ_%')->count());

        // Menolak menutup baris; dengan satu baris, sesinya ikut selesai.
        $this->assertSame(OpnameStatus::Approved, $opname->refresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::OpnameReject->value,
            'entity' => OpnameLine::class,
            'entity_id' => $line->id,
        ]);
    }

    #[Test]
    public function the_review_dialog_reads_its_targets_from_the_server(): void
    {
        $owner = $this->owner();
        $this->lot($this->rack(), 5, 'CN01-HW-001');
        $this->lot($this->rack(), 3, 'CN01-HW-002');

        $this->start($owner);
        $opname = Opname::sole();

        // Hitungan harus berbeda dari angka sistem; baris tanpa selisih memang
        // tidak pernah menunggu keputusan.
        foreach ($opname->lines()->get() as $line) {
            $this->countLine($owner, $opname, $line, (int) $line->system_qty - 1);
        }

        $this->submit($owner, $opname);

        // `@js` menggandakan escape (JSON di dalam `JSON.parse('...')`), jadi
        // backslash dibuang dulu supaya URL bisa dibandingkan apa adanya.
        $html = str_replace('\\', '', $this->actingAs($owner)->get(route('inventory.stok-opname'))->assertOk()->content());

        $this->assertStringContainsString('reviewTargets', $html);

        // Tombol Review tidak merakit alamatnya di peramban: tiap baris yang
        // menunggu keputusan punya entri di peta server, dan itulah satu-satunya
        // sumber URL dialog.
        foreach ($opname->lines()->get() as $line) {
            $this->assertStringContainsString(
                route('inventory.stok-opname.review', [$opname, $line]),
                $html,
            );
        }
    }

    #[Test]
    public function a_review_that_fails_validation_reopens_its_line(): void
    {
        $owner = $this->owner();
        $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($owner);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();
        $this->countLine($owner, $opname, $line, 4);
        $this->submit($owner, $opname);

        // Menyetujui tanpa alasan ditolak; baris yang gagal harus dibuka kembali,
        // bukan sekadar menutup dialog dan menaruh pesan di puncak halaman.
        $this->actingAs($owner)
            ->from(route('inventory.stok-opname'))
            ->post(route('inventory.stok-opname.review', [$opname, $line]), [
                'decision' => 'APPROVE',
                'review_line' => $line->getKey(),
            ])
            ->assertSessionHasErrors('reason');

        $html = str_replace('\\', '', $this->actingAs($owner)->get(route('inventory.stok-opname'))->assertOk()->content());

        $this->assertStringContainsString('failedReviewId', $html);
        $this->assertStringContainsString(
            route('inventory.stok-opname.review', [$opname, $line]),
            $html,
        );
    }

    #[Test]
    public function staff_cannot_decide_a_line(): void
    {
        $actor = $this->owner();
        $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($actor);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();
        $this->countLine($actor, $opname, $line, 4);
        $this->submit($actor, $opname);

        // Keputusan selisih mengubah stok lewat satu penilai yang sah, dan
        // Staff memang di luar batas modul ini: POST langsung pun berakhir
        // dengan 403 sebelum request sempat menjalankan apa pun.
        $this->review(User::factory()->staff()->create(), $opname, $line, 'APPROVE', AdjustmentReason::Lost)
            ->assertForbidden();

        $this->assertSame(5, StockLot::sole()->qty_on_hand);
        $this->assertSame(OpnameLineStatus::Counted, $line->refresh()->status);
    }

    #[Test]
    public function a_line_cannot_be_decided_while_the_session_is_still_counting(): void
    {
        $owner = $this->owner();
        $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($owner);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();
        $this->countLine($owner, $opname, $line, 4);

        // Belum diajukan: keputusan dini akan mengubah stok di luar urutan yang
        // bisa dipertanggungjawabkan (belum semua baris diperiksa).
        $this->review($owner, $opname, $line, 'APPROVE', AdjustmentReason::Lost)
            ->assertSessionHasErrors('opname');

        $this->assertSame(5, StockLot::sole()->qty_on_hand);
        $this->assertSame(OpnameLineStatus::Counted, $line->refresh()->status);
    }

    #[Test]
    public function counting_and_cancelling_follow_the_session_state(): void
    {
        $actor = $this->owner();
        $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($actor);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();
        $this->countLine($actor, $opname, $line, 4);

        // Selama menghitung, pembatalan menutup sesi tanpa menyentuh baris
        // maupun angkanya: riwayatnya boleh hilang dari layar, tetapi jejak
        // siapa menghitung apa tidak dihapus -- belum ada keputusan yang bisa
        // dibatalkan, dan tidak ada alasan untuk menghapus data.
        $this->actingAs($actor)
            ->post(route('inventory.stok-opname.batal', $opname))
            ->assertSessionHas('toast');

        $opname->refresh();
        $this->assertSame(OpnameStatus::Cancelled, $opname->status);
        $this->assertSame(1, $opname->lines()->count());
        $this->assertSame(OpnameLineStatus::Counted, $opname->lines()->sole()->status);

        // Sesi yang sudah ditutup tidak bisa diajukan lagi: tombol ajukan
        // memang tidak pernah ditawarkan untuk status ini.
        $this->submit($actor, $opname)->assertSessionHasErrors('opname');
        $this->assertNull($opname->refresh()->submitted_at);
    }

    #[Test]
    public function a_line_belonging_to_another_session_is_not_found(): void
    {
        $actor = $this->owner();
        $rack = $this->rack();
        $this->lot($rack, 5, 'CN01-HW-001');
        $this->lot($rack, 3, 'CN01-HW-002');

        $this->start($actor);
        $first = Opname::sole();
        $foreignLine = $first->lines()->orderBy('lot_id')->first();

        $this->actingAs($actor)->post(route('inventory.stok-opname.batal', $first));
        $this->start($actor);
        $second = Opname::query()->latest('id')->first();

        // Pengikatan baris terhadap sesinya: baris sesi lama tidak bisa
        // dihitung lewat URL sesi yang sedang berjalan, apa pun urutan angkanya.
        $this->actingAs($actor)
            ->post(route('inventory.stok-opname.hitung', [$second, $foreignLine]), ['counted_qty' => 5])
            ->assertNotFound();
    }

    #[Test]
    public function a_counted_line_survives_a_page_reload_without_leaking_the_system_number(): void
    {
        $actor = $this->owner();
        $this->lot($this->rack(), 41, 'CN01-HW-001');

        $this->start($actor);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();
        $this->countLine($actor, $opname, $line, 40);

        $html = $this->actingAs($actor)->get(route('inventory.stok-opname'))->assertOk()->content();

        // Angka yang diketik orang tetap terlihat miliknya -- kini lewat
        // state pindai yang turut tercetak di markup (nilai `value` pada
        // input); angka sistem tetap tidak ada, termasuk setelah barisnya
        // berstatus terhitung.
        $this->assertStringContainsString('value="40"', $html);
        $this->assertStringNotContainsString('Qty Sistem', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*41\s*</', $html);

        $this->assertSame(LotStatus::Available, StockLot::sole()->status);
        $this->assertSame(0, StockMovement::query()->where('lot_id', StockLot::sole()->id)->count());
    }

    #[Test]
    public function a_pending_session_can_be_reopened_to_change_counts_before_any_decision(): void
    {
        $owner = $this->owner();
        $lot = $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($owner);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();
        $this->countLine($owner, $opname, $line, 4);
        $this->submit($owner, $opname);
        $this->assertSame(OpnameStatus::PendingApproval, $opname->refresh()->status);

        // Buka ulang masih tersedia sebagai pintu mengoreksi hitungan selama
        // belum ada satu baris pun yang diputuskan.
        $this->reopen($owner, $opname)->assertSessionHas('toast');

        $opname->refresh();
        $this->assertSame(OpnameStatus::Counting, $opname->status);
        $this->assertNull($opname->submitted_at);

        // Hitungan lama tidak dibuang: baris kembali dengan angka yang sama.
        $line->refresh();
        $this->assertSame(4, $line->counted_qty);

        $this->countLine($owner, $opname, $line, 2)->assertSessionHas('toast');
        $this->submit($owner, $opname)->assertSessionHas('toast');

        $this->review($owner, $opname, $line, 'APPROVE', AdjustmentReason::Lost)
            ->assertSessionMissing('errors');

        $this->assertSame(2, $lot->refresh()->qty_on_hand);
        $this->assertSame(OpnameLineStatus::Approved, $line->refresh()->status);
        $this->assertSame(OpnameStatus::Approved, $opname->refresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::OpnameReopen->value,
            'entity' => Opname::class,
            'entity_id' => $opname->id,
            'user_id' => $owner->id,
        ]);
    }

    #[Test]
    public function rejecting_after_a_movement_leaves_stock_untouched_and_records_the_current_diff(): void
    {
        $owner = $this->owner();
        $lot = $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($owner);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();
        $this->countLine($owner, $opname, $line, 3);
        $this->submit($owner, $opname);

        StockMovement::factory()->create([
            'lot_id' => $lot->id,
            'type' => MovementType::Sale,
            'qty_delta' => -1,
            'balance_after' => 4,
        ]);
        $lot->update(['qty_on_hand' => 4]);

        // Menolak tetap tidak menyentuh stok, tetapi catatan selisihnya dihitung
        // ulang terhadap keadaan sekarang (3 - 4 = -1), bukan nilai saat baris
        // dipindai (-2).
        $this->review($owner, $opname, $line, 'REJECT')->assertSessionHas('toast');

        $this->assertSame(4, $lot->refresh()->qty_on_hand);
        $this->assertSame(OpnameLineStatus::Rejected, $line->refresh()->status);
        $this->assertSame(-1, (int) $line->refresh()->diff_qty);
        $this->assertSame(0, StockMovement::query()->where('type', 'like', 'ADJ_%')->count());

        $this->assertSame(1, StockMovement::query()->where('type', MovementType::Sale)->count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditAction::OpnameReject->value,
            'entity' => OpnameLine::class,
            'entity_id' => $line->id,
        ]);
    }

    #[Test]
    public function a_decision_is_refused_when_the_ledger_and_the_stock_disagree(): void
    {
        $owner = $this->owner();
        $lot = $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($owner);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();
        $this->countLine($owner, $opname, $line, 4);
        $this->submit($owner, $opname);

        // Gerakan tanpa perubahan qty: ekspektasi naik (5 + 1 = 6), stok tidak
        // (tetap 5). Angka yang dipakai POS tidak lagi sepakat dengan ledger,
        // jadi selisih tidak boleh dihitung di atasnya.
        StockMovement::factory()->create([
            'lot_id' => $lot->id,
            'type' => MovementType::Transfer,
            'qty_delta' => 1,
            'balance_after' => 5,
        ]);

        $this->review($owner, $opname, $line, 'APPROVE', AdjustmentReason::Lost)
            ->assertSessionHasErrors('opname');

        $this->assertSame(5, $lot->refresh()->qty_on_hand);
        $this->assertSame(OpnameLineStatus::Counted, $line->refresh()->status);
        $this->assertSame(OpnameStatus::PendingApproval, $opname->refresh()->status);
    }

    #[Test]
    public function opening_a_session_warns_when_a_cashier_shift_is_still_open(): void
    {
        $owner = $this->owner();
        $cashier = User::factory()->staff()->create();

        $html = $this->actingAs($owner)->get(route('inventory.stok-opname'))->assertOk()->content();
        $this->assertStringNotContainsString('Shift kasir masih terbuka', $html);

        Shift::create([
            'device_id' => 'WMS-01',
            'user_id' => $cashier->id,
            'opened_at' => now(),
            'opening_cash' => 500000,
            'status' => ShiftStatus::Open,
        ]);

        $html = $this->actingAs($owner)->get(route('inventory.stok-opname'))->assertOk()->content();
        $this->assertStringContainsString('Shift kasir masih terbuka', $html);
        $this->assertStringContainsString('return confirm(', $html);
    }

    #[Test]
    public function reopening_is_refused_after_a_decision_has_been_applied(): void
    {
        $owner = $this->owner();
        $rack = $this->rack();
        $this->lot($rack, 5, 'CN01-HW-001');
        $this->lot($rack, 3, 'CN01-HW-002');

        $this->start($owner);
        $opname = Opname::sole();

        foreach ($opname->lines()->get() as $line) {
            $this->countLine($owner, $opname, $line, (int) $line->system_qty - 1);
        }
        $this->submit($owner, $opname);

        $first = $opname->lines()->orderBy('lot_id')->first();
        $this->review($owner, $opname, $first, 'APPROVE', AdjustmentReason::Lost)->assertSessionHas('toast');

        // Keputusan sudah menulis gerakan penyesuaian ke stok; menghitung
        // ulang baris itu lalu menyetujuinya lagi akan menerapkan selisihnya
        // dua kali, jadi pintunya tetap tertutup.
        $this->reopen($owner, $opname)->assertSessionHasErrors('opname');

        $opname->refresh();
        $this->assertSame(OpnameStatus::PendingApproval, $opname->status);
        $this->assertNotNull($opname->submitted_at);
    }

    #[Test]
    public function reopening_is_refused_for_a_session_that_is_still_counting(): void
    {
        $owner = $this->owner();
        $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($owner);
        $opname = Opname::sole();
        $this->countLine($owner, $opname, $opname->lines()->sole(), 4);

        // Belum diajukan: membuka ulang hanya berarti sesuatu setelah sesi
        // benar-benar terkunci, bukan sebelum pintunya pernah tertutup.
        $this->reopen($owner, $opname)->assertSessionHasErrors('opname');

        $this->assertSame(OpnameStatus::Counting, $opname->refresh()->status);
    }

    #[Test]
    public function staff_cannot_reopen_a_session(): void
    {
        $owner = $this->owner();
        $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($owner);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();
        $this->countLine($owner, $opname, $line, 4);
        $this->submit($owner, $opname);

        // Sama seperti keputusan, membuka ulang adalah urusan Owner: Staff
        // dihentikan grup middleware sebelum menyentuh service.
        $this->reopen(User::factory()->staff()->create(), $opname)->assertForbidden();

        $this->assertSame(OpnameStatus::PendingApproval, $opname->refresh()->status);
    }

    /** Sesi penuh yang sudah diputuskan: satu lot, satu selisih disetujui. */
    private function finishedSession(User $owner, int $systemQty, int $countedQty): Opname
    {
        $this->lot($this->rack(), $systemQty, 'CN01-HW-001');

        $this->start($owner);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();

        $this->countLine($owner, $opname, $line, $countedQty);
        $this->submit($owner, $opname);
        $this->review($owner, $opname, $line, 'APPROVE', AdjustmentReason::Lost);

        return $opname->refresh();
    }

    #[Test]
    public function the_index_shows_exactly_one_start_button_and_no_stale_copy(): void
    {
        $html = $this->actingAs($this->owner())->get(route('inventory.stok-opname'))->assertOk()->content();

        // Opsi A: hanya CTA di header. Kartu empty-state menjelaskan, tidak
        // menawarkan tombol kedua yang melakukan hal sama.
        $this->assertSame(1, substr_count($html, 'Mulai Sesi Baru'));

        // Semantik absorb: stok yang bergerak tidak lagi membuat baris "basi".
        $this->assertStringNotContainsString('jadi basi', $html);
        $this->assertStringNotContainsString('harus dihitung ulang sebelum diputuskan', $html);
    }

    #[Test]
    public function a_running_session_is_not_offered_a_detail_link(): void
    {
        $owner = $this->owner();
        $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($owner);
        $opname = Opname::sole();

        $html = $this->actingAs($owner)->get(route('inventory.stok-opname'))->assertOk()->content();

        // Sesi terbuka hidup di kartu kerja sendiri, bukan di riwayat: tidak
        // ada tautan detail untuknya, dan tombol Mulai pun tidak ditawarkan.
        // Ditulis sebagai atribut utuh karena URL detail hanyalah awalan dari
        // URL `.../baris/{line}/hitung` yang memang ada di kartu kerja.
        $this->assertStringNotContainsString('data-row-url="'.route('inventory.stok-opname.detail', $opname).'"', $html);
        $this->assertStringNotContainsString('href="'.route('inventory.stok-opname.detail', $opname).'"', $html);
        $this->assertSame(0, substr_count($html, 'Mulai Sesi Baru'));
    }

    #[Test]
    public function the_history_links_each_finished_session_to_its_detail(): void
    {
        $owner = $this->owner();
        $opname = $this->finishedSession($owner, 5, 4);

        $html = $this->actingAs($owner)->get(route('inventory.stok-opname'))->assertOk()->content();

        $this->assertStringContainsString($opname->opname_no, $html);
        $this->assertStringContainsString('data-row-url="'.route('inventory.stok-opname.detail', $opname).'"', $html);
        $this->assertStringContainsString('href="'.route('inventory.stok-opname.detail', $opname).'"', $html);
    }

    #[Test]
    public function the_detail_page_shows_facts_lines_and_the_audit_timeline(): void
    {
        $owner = $this->owner();
        $opname = $this->finishedSession($owner, 5, 4);

        $html = $this->actingAs($owner)
            ->get(route('inventory.stok-opname.detail', $opname))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('Detail Sesi', $html);
        $this->assertStringContainsString($opname->opname_no, $html);
        $this->assertStringContainsString('Seluruh gudang', $html);

        // Baris sesi memuat angka sistem begitu tidak lagi blind.
        $this->assertStringContainsString('CN01-HW-001', $html);
        $this->assertStringContainsString('Qty Sistem', $html);

        // Jejak audit dibangun dari Opname dan OpnameLine sekaligus.
        $this->assertStringContainsString('Sesi Opname Dimulai', $html);
        $this->assertStringContainsString('Sesi Opname Diajukan', $html);
        $this->assertStringContainsString('Selisih Opname Disetujui', $html);
    }

    #[Test]
    public function the_detail_page_keeps_a_counting_session_blind(): void
    {
        $owner = $this->owner();
        $this->lot($this->rack(), 41, 'CN01-HW-001');

        $this->start($owner);
        $opname = Opname::sole();

        $html = $this->actingAs($owner)
            ->get(route('inventory.stok-opname.detail', $opname))
            ->assertOk()
            ->content();

        // Sesi menghitung bisa dibuka lewat URL langsung; angka sistemnya tetap
        // tidak pernah dikirim, sama seperti halaman berjalan.
        $this->assertStringContainsString('Sesi masih menghitung', $html);
        $this->assertStringNotContainsString('Qty Sistem', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*41\s*</', $html);
    }

    #[Test]
    public function a_guest_is_redirected_from_the_detail_page(): void
    {
        $opname = Opname::factory()->create();

        $this->get(route('inventory.stok-opname.detail', $opname))->assertRedirect(route('login'));
    }

    #[Test]
    public function staff_cannot_open_a_session_detail(): void
    {
        $owner = $this->owner();
        $opname = $this->finishedSession($owner, 5, 4);

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('inventory.stok-opname.detail', $opname))
            ->assertForbidden();
    }

    #[Test]
    public function approving_a_surplus_that_does_not_fit_the_rack_is_rejected(): void
    {
        $owner = $this->owner();
        $rack = Rack::factory()->create(['code' => 'A-01-03', 'capacity' => 5]);
        $lot = $this->lot($rack, 5, 'CN01-HW-001');

        $this->start($owner);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();
        $this->countLine($owner, $opname, $line, 6);
        $this->submit($owner, $opname);

        $this->review($owner, $opname, $line, 'APPROVE', AdjustmentReason::Found)
            ->assertSessionHasErrors([
                'opname' => 'Rak A-01-03 tidak cukup: kapasitas 5 unit, terpakai 5, diminta 1 (sisa 0).',
            ]);

        $this->assertSame(5, $lot->refresh()->qty_on_hand);
        $this->assertSame(0, StockMovement::query()->where('type', MovementType::AdjPlus)->count());
        $this->assertSame(OpnameLineStatus::Counted, $line->refresh()->status);
    }

    #[Test]
    public function approving_a_shortage_is_allowed_even_when_the_rack_is_full(): void
    {
        $owner = $this->owner();
        $rack = Rack::factory()->create(['capacity' => 5]);
        $lot = $this->lot($rack, 5, 'CN01-HW-001');

        $this->start($owner);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();
        $this->countLine($owner, $opname, $line, 4);
        $this->submit($owner, $opname);

        $this->review($owner, $opname, $line, 'APPROVE', AdjustmentReason::Lost)
            ->assertSessionHas('toast');

        $this->assertSame(4, $lot->refresh()->qty_on_hand);
    }
}
