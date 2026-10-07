<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LabelReason;
use App\Models\AuditLog;
use App\Models\LabelPrintJob;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Auth\PinService;
use App\Services\Inventory\LabelPrintService;
use App\Services\Inventory\ReprintLimit;
use App\Services\Inventory\ReprintLimitExceeded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Batas jumlah label (FR-IB-22) dan jatah cetak ulang harian.
 *
 * Yang paling dijaga di sini adalah dua hal yang mudah lolos dari penglihatan:
 *
 * 1. **Cetakan yang masih berjalan ikut menghitung.** Batasnya tidak boleh
 *    hanya membandingkan `labels_printed` dengan `qty_received`, karena dua
 *    operator yang menekan tombol nyaris bersamaan akan dua-duanya melihat
 *    angka yang sama dan dua-duanya lolos. Lot dengan 2 barang, 1 label
 *    keluar, dan 1 cetakan masih antre, tidak boleh menerima 1 cetakan lagi.
 *
 * 2. **PIN Owner bersifat kondisional.** Kalau `pin_token` jadi `required`
 *    tanpa syarat, setiap Staff yang mau cetak ulang satu label untuk barang
 *    yang labelnya sobek akan diminta PIN -- yaitu membatalkan seluruh tujuan
 *    cetakan ulang. Batas dan otorisasi harus dihitung di tempat yang sama,
 *    kalau tidak angka di form dan keputusan di server akan berbeda.
 */
class LabelReprintLimitTest extends TestCase
{
    use RefreshDatabase;

    private const CONTEXT = 'inventory.label-overprint';

    private User $staff;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->staff()->create();
        $this->owner = User::factory()->owner()->create(['pin' => bcrypt('123456')]);
    }

    private function lot(array $attributes = [], string $sku = 'CN01-HW-001-U03'): StockLot
    {
        return StockLot::factory()->create(array_merge([
            'sku' => $sku,
            'owner_code' => 'CN01',
            'qty_received' => 12,
            'labels_printed' => 0,
            'reprint_count' => 0,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function reprint(array $lotIds, int $copies = 1, array $extra = [], ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->staff)->post(route('inbound.cetak-label.reprint'), array_merge([
            'lot_ids' => $lotIds,
            'copies' => $copies,
            'reason' => LabelReason::LabelDamaged->value,
        ], $extra));
    }

    private function token(User $actor, string $context = self::CONTEXT): string
    {
        return app(PinService::class)->issue($actor, '123456', $context)->token;
    }

    private function jobsOn(StockLot $lot): int
    {
        return LabelPrintJob::where('lot_id', $lot->id)->count();
    }

    // ------------------------------------------------ batas jumlah label ---

    #[Test]
    public function a_reprint_inside_the_qty_needs_no_pin(): void
    {
        $lot = $this->lot(['labels_printed' => 10]);

        // 10 dari 12 sudah keluar; masih ada sisa untuk 2 label.
        $this->reprint([$lot->id], copies: 2)
            ->assertRedirect(route('inbound.cetak-label'))
            ->assertSessionMissing('errors');

        $this->assertNull(LabelPrintJob::sole()->approved_by);
    }

    #[Test]
    public function a_reprint_beyond_the_qty_is_refused_without_a_pin(): void
    {
        $lot = $this->lot(['labels_printed' => 12]);

        $this->reprint([$lot->id], copies: 1)
            ->assertSessionHasErrors('pin_token');

        $this->assertSame(0, $this->jobsOn($lot));
    }

    /**
     * Lot 2 barang: 1 label sudah keluar, 1 cetakan masih antre di printer.
     * Sisa lotnya nol, jadi satu cetakan lagi harus ditolak.
     */
    #[Test]
    public function a_reprint_still_in_the_queue_counts_toward_the_cap(): void
    {
        $lot = $this->lot(['qty_received' => 2, 'labels_printed' => 1]);
        LabelPrintJob::factory()->for($lot, 'lot')->reprint()->create(['copies' => 1]);

        $this->reprint([$lot->id], copies: 1)
            ->assertSessionHasErrors('pin_token');

        $this->assertSame(1, $this->jobsOn($lot), 'Job yang sudah ada tidak boleh bertambah.');
    }

    #[Test]
    public function a_failed_reprint_does_not_count_toward_the_cap(): void
    {
        // Job FAILED tidak menghasilkan label, jadi tidak memblokir cetakan
        // berikutnya. Kalau ikut menghitung, satu kertas habis membuat lot
        // terkunci sampai ada yang membersihkan job-nya.
        $lot = $this->lot(['qty_received' => 2, 'labels_printed' => 1]);
        LabelPrintJob::factory()->for($lot, 'lot')->reprint()->failed()->create(['copies' => 1]);

        $this->reprint([$lot->id], copies: 1)
            ->assertRedirect(route('inbound.cetak-label'));

        $this->assertSame(2, $this->jobsOn($lot));
    }

    #[Test]
    public function a_confirmed_reprint_still_counts_toward_the_cap(): void
    {
        $lot = $this->lot(['qty_received' => 3, 'labels_printed' => 1]);
        $job = LabelPrintJob::factory()->for($lot, 'lot')->reprint()->confirmed()->create(['copies' => 2]);

        $this->reprint([$lot->id], copies: 1)->assertRedirect(route('inbound.cetak-label'));

        $this->assertSame(2, $this->jobsOn($lot));
        $this->assertSame(1, $lot->fresh()->labels_printed, 'Counter hanya naik lewat konfirmasi.');
    }

    /**
     * "PIN Owner belum dilakukan" tanpa penjelasan akan membuat operator
     * mengira dia salah klik. Kalimat yang menjelaskan lot mana dan angkanya
     * harus ikut naik di field yang sama.
     */
    #[Test]
    public function the_refusal_names_the_lot_and_the_numbers(): void
    {
        $lot = $this->lot(['qty_received' => 4, 'labels_printed' => 4], 'CN01-HW-001-U09');

        $this->reprint([$lot->id], copies: 3)->assertSessionHasErrors('pin_token');

        $message = session('errors')->first('pin_token');

        $this->assertStringContainsString('CN01-HW-001-U09', $message);
        $this->assertStringContainsString('4 dari 4', $message);
        $this->assertStringContainsString('PIN Owner', $message);
    }

    // -------------------------------------------------------- otorisasi ---

    #[Test]
    public function staff_may_exceed_the_qty_with_an_owner_pin(): void
    {
        $lot = $this->lot(['labels_printed' => 12]);

        $this->reprint([$lot->id], copies: 3, extra: ['pin_token' => $this->token($this->staff)])
            ->assertRedirect(route('inbound.cetak-label'));

        $this->assertSame($this->owner->id, LabelPrintJob::sole()->approved_by);
    }

    #[Test]
    public function the_approver_is_audited_with_the_reason_and_the_action(): void
    {
        $lot = $this->lot(['labels_printed' => 12]);

        $this->reprint([$lot->id], copies: 2, extra: [
            'pin_token' => $this->token($this->staff),
            'reason' => LabelReason::AdditionalUnits->value,
        ])->assertRedirect(route('inbound.cetak-label'));

        $log = AuditLog::where('action', 'LABEL_REPRINT_OVER_QTY')->sole();

        $this->assertSame('StockLot', $log->entity);
        $this->assertSame($lot->id, $log->entity_id);
        $this->assertSame(LabelReason::AdditionalUnits->value, $log->reason);
        $this->assertSame($this->owner->id, $log->after['approved_by']);
        $this->assertSame(12, $log->after['labels_printed']);
        $this->assertSame(12, $log->after['qty_received']);
        // `user_id` adalah petugas yang menekan tombol, bukan yang mengizinkan.
        $this->assertSame($this->staff->id, $log->user_id);
    }

    #[Test]
    public function a_token_issued_for_another_action_does_not_unlock_an_overprint(): void
    {
        $lot = $this->lot(['labels_printed' => 12]);

        $this->reprint([$lot->id], copies: 1, extra: [
            'pin_token' => $this->token($this->staff, 'consignment.scheme-override'),
        ])->assertSessionHasErrors('pin_token');

        $this->assertSame(0, $this->jobsOn($lot));
    }

    #[Test]
    public function a_forged_token_does_not_unlock_an_overprint(): void
    {
        $lot = $this->lot(['labels_printed' => 12]);

        $this->reprint([$lot->id], copies: 1, extra: ['pin_token' => 'token-palsu'])
            ->assertSessionHasErrors('pin_token');

        $this->assertSame(0, $this->jobsOn($lot));
    }

    #[Test]
    public function one_staff_cannot_borrow_another_staffs_token(): void
    {
        $lot = $this->lot(['labels_printed' => 12]);
        $colleague = User::factory()->staff()->create();
        $token = $this->token($colleague);

        $this->reprint([$lot->id], copies: 1, extra: ['pin_token' => $token])
            ->assertSessionHasErrors('pin_token');

        $this->assertSame(0, $this->jobsOn($lot));
    }

    #[Test]
    public function the_owner_may_exceed_the_qty_without_a_pin_but_it_is_still_recorded(): void
    {
        $lot = $this->lot(['labels_printed' => 12]);

        $this->reprint([$lot->id], copies: 5, actor: $this->owner)
            ->assertRedirect(route('inbound.cetak-label'))
            ->assertSessionMissing('errors');

        $job = LabelPrintJob::sole();

        $this->assertSame($this->owner->id, $job->approved_by, 'Owner menotorisasi dirinya sendiri.');
        $this->assertTrue(AuditLog::where('action', 'LABEL_REPRINT_OVER_QTY')->exists());
    }

    #[Test]
    public function the_owner_does_not_need_a_pin_to_reprint(): void
    {
        $lot = $this->lot();

        $this->reprint([$lot->id], copies: 1, actor: $this->owner)
            ->assertRedirect(route('inbound.cetak-label'))
            ->assertSessionMissing('errors');

        $this->assertNull(LabelPrintJob::sole()->approved_by);
    }

    // ------------------------------------------------------ jatah harian ---

    #[Test]
    public function the_daily_limit_counts_attempts_not_labels(): void
    {
        // Satu permintaan 4 label tetap satu percobaan. Kalau yang dihitung
        // label, jatah seharian habis dalam satu permintaan dan bedanya
        // dengan batas jumlah label menghilang.
        $lot = $this->lot();
        LabelPrintJob::factory()->count(2)->for($lot, 'lot')->reprint()->create(['copies' => 4]);

        $this->reprint([$lot->id], copies: 4)
            ->assertRedirect(route('inbound.cetak-label'))
            ->assertSessionMissing('errors');
    }

    #[Test]
    public function the_fourth_attempt_of_the_day_needs_a_pin(): void
    {
        $lot = $this->lot();
        LabelPrintJob::factory()->count(3)->for($lot, 'lot')->reprint()->create(['copies' => 1]);

        $this->reprint([$lot->id], copies: 1)
            ->assertSessionHasErrors('pin_token');

        $this->assertSame(3, $this->jobsOn($lot));
    }

    #[Test]
    public function exceeding_the_daily_limit_is_audited_separately(): void
    {
        $lot = $this->lot();
        LabelPrintJob::factory()->count(3)->for($lot, 'lot')->reprint()->create(['copies' => 1]);

        $this->reprint([$lot->id], copies: 1, extra: ['pin_token' => $this->token($this->staff)])
            ->assertRedirect(route('inbound.cetak-label'));

        $this->assertTrue(AuditLog::where('action', 'LABEL_REPRINT_DAILY_LIMIT')->exists());

        $new = LabelPrintJob::where('lot_id', $lot->id)->latest('id')->first();
        $this->assertSame($this->owner->id, $new->approved_by);
    }

    #[Test]
    public function the_first_label_does_not_count_as_a_reprint_attempt(): void
    {
        // Label awal dibuat otomatis saat barang masuk. Kalau ikut menghitung,
        // lot baru langsung kehilangan jatah hari pertamanya.
        $lot = $this->lot();
        LabelPrintJob::factory()->count(3)->for($lot, 'lot')->create(['reason' => LabelReason::Initial]);

        $this->reprint([$lot->id], copies: 1)
            ->assertRedirect(route('inbound.cetak-label'))
            ->assertSessionMissing('errors');
    }

    #[Test]
    public function yesterday_does_not_count_toward_todays_limit(): void
    {
        $lot = $this->lot();
        LabelPrintJob::factory()->count(5)->for($lot, 'lot')->reprint()->create([
            'copies' => 1,
            'created_at' => now()->subDay(),
        ]);

        $this->reprint([$lot->id], copies: 1)
            ->assertRedirect(route('inbound.cetak-label'))
            ->assertSessionMissing('errors');
    }

    #[Test]
    public function the_owner_is_not_subject_to_the_daily_limit(): void
    {
        $lot = $this->lot();
        LabelPrintJob::factory()->count(5)->for($lot, 'lot')->reprint()->create(['copies' => 1]);

        $this->reprint([$lot->id], copies: 1, actor: $this->owner)
            ->assertRedirect(route('inbound.cetak-label'))
            ->assertSessionMissing('errors');
    }

    #[Test]
    public function the_daily_limit_is_per_lot(): void
    {
        // Jatah tiga kali berlaku per SKU, bukan per sesi: dua lot berbeda
        // tidak saling menghabiskan jatah.
        $first = $this->lot(['sku' => 'CN01-HW-001-U03']);
        $second = $this->lot(['sku' => 'CN01-HW-001-U04']);
        LabelPrintJob::factory()->count(3)->for($first, 'lot')->reprint()->create(['copies' => 1]);

        $this->reprint([$second->id], copies: 1)
            ->assertRedirect(route('inbound.cetak-label'))
            ->assertSessionMissing('errors');
    }

    // ----------------------------------------------- gerbang di service ---

    /**
     * Pemeriksaan di request bukan satu-satunya gerbang. Service diperiksa lagi
     * di dalam transaksi, jadi pemanggil yang bukan HTTP -- atau request yang
     * datanya sudah basi -- tetap tertahan.
     */
    #[Test]
    public function the_service_refuses_an_unapproved_overprint(): void
    {
        $lot = $this->lot(['labels_printed' => 12]);

        $this->expectException(ReprintLimitExceeded::class);

        app(LabelPrintService::class)->reprint(
            lotIds: [$lot->id],
            copies: 1,
            reason: LabelReason::LabelDamaged,
            userId: $this->staff->id,
        );
    }

    #[Test]
    public function the_service_lets_an_approved_overprint_through(): void
    {
        $lot = $this->lot(['labels_printed' => 12]);

        $created = app(LabelPrintService::class)->reprint(
            lotIds: [$lot->id],
            copies: 2,
            reason: LabelReason::LabelDamaged,
            userId: $this->staff->id,
            approvedBy: $this->owner->id,
        );

        $this->assertSame(1, $created);
        $this->assertSame($this->owner->id, LabelPrintJob::sole()->approved_by);
    }

    #[Test]
    public function the_service_approves_the_owner_without_being_asked_to(): void
    {
        $lot = $this->lot(['labels_printed' => 12]);

        $created = app(LabelPrintService::class)->reprint(
            lotIds: [$lot->id],
            copies: 2,
            reason: LabelReason::LabelDamaged,
            userId: $this->owner->id,
        );

        $this->assertSame(1, $created);
    }

    // -------------------------------------------------------------hitung ---

    #[Test]
    public function the_verdict_reports_the_headroom_that_is_left(): void
    {
        $lot = $this->lot(['qty_received' => 10, 'labels_printed' => 4]);
        LabelPrintJob::factory()->for($lot, 'lot')->reprint()->create(['copies' => 3]);

        // 4 keluar + 3 antre = 7 terpakai, sisa 3. Meminta 3 masih boleh.
        $inside = app(ReprintLimit::class)
            ->evaluate(StockLot::whereKey($lot->id)->get(), 3, $this->staff);
        $this->assertFalse($inside->isBreached());

        // Meminta 4 sudah melewati sisa 3.
        $outside = app(ReprintLimit::class)
            ->evaluate(StockLot::whereKey($lot->id)->get(), 4, $this->staff);
        $this->assertTrue($outside->isBreached());
        $this->assertTrue($outside->needsOwnerPin());
        $this->assertSame(7, $outside->overQty[0]['used']);
        $this->assertSame(3, $outside->overQty[0]['allowed']);
    }

    #[Test]
    public function a_lot_already_over_its_qty_reports_negative_headroom(): void
    {
        $lot = $this->lot(['qty_received' => 5, 'labels_printed' => 8]);

        $verdict = app(ReprintLimit::class)
            ->evaluate(StockLot::whereKey($lot->id)->get(), 1, $this->staff);

        $this->assertTrue($verdict->isBreached());
        $this->assertSame(-3, $verdict->overQty[0]['allowed']);
    }

    #[Test]
    public function an_empty_selection_is_never_a_breach(): void
    {
        $verdict = app(ReprintLimit::class)->evaluate(
            StockLot::query()->get(),
            999,
            $this->staff,
        );

        $this->assertFalse($verdict->isBreached());
        $this->assertFalse($verdict->needsOwnerPin());
    }

    // -------------------------------------------------------------hitung ---

    #[Test]
    public function a_breach_the_owner_commits_is_recorded_but_needs_no_pin(): void
    {
        $lot = $this->lot(['labels_printed' => 12]);

        $verdict = app(ReprintLimit::class)
            ->evaluate(StockLot::whereKey($lot->id)->get(), 3, $this->owner);

        $this->assertTrue($verdict->isBreached(), 'Owner boleh, tapi tetap dilaporkan.');
        $this->assertFalse($verdict->needsOwnerPin());
        $this->assertContains('LABEL_REPRINT_OVER_QTY', $verdict->breachActions());
    }

    // --------------------------------------------------------------tampilan ---
    //
    // Kolom "sisa" adalah separuh dari kontrol: kalau layar menampilkan lot
    // yang sudah penuh sebagai masih lega, operator akan menekan tombol dan
    // baru tahu jawabannya setelah halaman dimuat ulang.

    private function searchPage(StockLot $lot): string
    {
        $html = $this->actingAs($this->staff)
            ->get(route('inbound.cetak-label', ['q' => $lot->sku]))
            ->assertOk()
            ->getContent();

        return (string) $html;
    }

    #[Test]
    public function the_search_result_shows_the_headroom_that_is_left(): void
    {
        $lot = $this->lot(['qty_received' => 10, 'labels_printed' => 4]);

        $this->assertStringContainsString('sisa 6', $this->searchPage($lot));
    }

    #[Test]
    public function the_search_result_counts_a_reprint_still_in_the_printer(): void
    {
        $lot = $this->lot(['qty_received' => 10, 'labels_printed' => 4]);
        LabelPrintJob::factory()->for($lot, 'lot')->reprint()->create(['copies' => 5]);

        $html = $this->searchPage($lot);

        $this->assertStringContainsString('sisa 1', $html, 'Cetakan yang antre mengurangi sisa.');
        $this->assertStringContainsString('5 masih di printer', $html);
    }

    #[Test]
    public function a_full_lot_is_marked_as_needing_an_owner_pin(): void
    {
        $lot = $this->lot(['qty_received' => 10, 'labels_printed' => 10]);

        $this->assertStringContainsString('butuh PIN', $this->searchPage($lot));
    }

    #[Test]
    public function a_lot_full_only_because_of_a_queued_reprint_is_marked_too(): void
    {
        // Lot ini belum "penuh" secara `labels_printed`, tapi tidak ada lagi
        // ruang untuk cetakan. Kalau hanya `labels_printed` yang dilihat,
        // operator tidak akan diberi tahu sebelum menekan tombol.
        $lot = $this->lot(['qty_received' => 6, 'labels_printed' => 3]);
        LabelPrintJob::factory()->for($lot, 'lot')->reprint()->create(['copies' => 3]);

        $this->assertStringContainsString('butuh PIN', $this->searchPage($lot));
    }

    #[Test]
    public function the_search_result_shows_the_daily_allowance_left(): void
    {
        $lot = $this->lot();
        LabelPrintJob::factory()->count(2)->for($lot, 'lot')->reprint()->create(['copies' => 1]);

        $this->assertStringContainsString('1&times; lagi hari ini', $this->searchPage($lot));
    }

    #[Test]
    public function an_exhausted_allowance_is_marked_on_the_result(): void
    {
        $lot = $this->lot();
        LabelPrintJob::factory()->count(3)->for($lot, 'lot')->reprint()->create(['copies' => 1]);

        $this->assertStringContainsString('jatah hari ini habis', $this->searchPage($lot));
    }

    #[Test]
    public function the_form_names_the_action_its_pin_token_is_scoped_to(): void
    {
        // Token dari aksi lain harus ditolak. Kalau view meng-hardcode
        // konteks yang berbeda dari `ReprintLabelsRequest`, setiap cetakan
        // yang melewati batas akan ditolak dengan alasan "token untuk aksi
        // lain" -- dan tidak ada yang bisa memperbaikinya dari layar.
        $html = $this->searchPage($this->lot());

        $this->assertStringContainsString('inventory.label-overprint', $html);
        $this->assertStringContainsString('name="pin_token"', $html);
    }

    #[Test]
    public function the_form_still_works_without_javascript(): void
    {
        // `@submit` hanya menahan form kalau dialognya ada. Kalau tidak, form
        // dikirim sebagai POST biasa dan penolakan muncul sebagai pesan
        // session seperti form pada umumnya.
        $html = $this->searchPage($this->lot());

        $this->assertStringContainsString('window.pin ? submit($event) : null', $html);
    }
}
