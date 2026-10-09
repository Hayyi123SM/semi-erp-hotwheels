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
use App\Models\Opname;
use App\Models\OpnameLine;
use App\Models\Rack;
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
 * 2. **Submit itu sesi, bukan baris.** Semua baris wajib terhitung, dan angka
 *    yang sudah basi karena stok bergerak tidak dipakai diam-diam.
 * 3. **Keputusan mengubah stok sekali.** Menerima menulis gerakan penyesuaian
 *    dan mengubah qty; menolak tidak menyentuh apa pun.
 * 4. **Modul ini Owner-only.** Staff hanya memegang POS, jadi seluruh route
 *    stok-opname -- termasuk halaman dan keputusan review -- menolak Staff
 *    dengan 403 lewat grup `['auth', 'owner']`.
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
    public function submitting_is_refused_when_the_stock_moved_after_the_count(): void
    {
        $actor = $this->owner();
        $lot = $this->lot($this->rack(), 5, 'CN01-HW-001');

        $this->start($actor);
        $opname = Opname::sole();
        $line = $opname->lines()->sole();

        $this->countLine($actor, $opname, $line, 5);

        // Sebuah penjualan di antara hitung dan ajukan: angka lima sudah tidak
        // menggambarkan isi rak, jadi sesi yang memakainya akan menutup selisih
        // yang palsu.
        StockMovement::factory()->create([
            'lot_id' => $lot->id,
            'type' => MovementType::Sale,
            'qty_delta' => -1,
            'balance_after' => 4,
        ]);
        $lot->update(['qty_on_hand' => 4]);

        $this->submit($actor, $opname)->assertSessionHasErrors('opname');
        $this->assertSame(OpnameStatus::Counting, $opname->refresh()->status);
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
}
