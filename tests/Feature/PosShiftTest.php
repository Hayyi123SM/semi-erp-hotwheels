<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Enums\ShiftStatus;
use App\Models\AuditLog;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\User;
use App\Services\Pos\PosSettings;
use App\Services\Pos\ShiftAlreadyClosedException;
use App\Services\Pos\ShiftAlreadyOpenException;
use App\Services\Pos\ShiftService;
use App\Support\DeviceId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Siklus shift kasir: membuka, menghitung uang, menutup.
 *
 * Yang dijaga di sini ada dua, dan keduanya pernah jadi sumber ketidakcocokan
 * antara angka yang tampil dengan angka yang disimpan.
 *
 * Pertama, rumus kasnya. `seharusnya ada = uang pembuka + tunai terjual`, dan
 * hanya penjualan `PAID` yang dihitung: void mengembalikan uang ke pelanggan dan
 * tidak pernah menyentuh laci, sedangkan konflik sinkron dan istilah harga yang
 * belum terpenuhi belum bisa dipercaya untuk dihitung sebagai kas.
 *
 * Kedua, siapa yang boleh menutup shift dengan selisih. Kasir boleh menutupnya
 * sendiri selama selisihnya di dalam ambang, karena itu pekerjaan kasir. Di luar
 * ambang, hitungan kasir berhenti di `PENDING_APPROVAL` sampai Owner memutuskan:
 * disetujui berarti shift tertutup, ditolak berarti shift kembali berjalan dan
 * harus dihitung ulang. Tidak ada PIN yang diketik siapa pun di alur ini.
 */
class PosShiftTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = 'POS-TEST-01';

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    private function staff(): User
    {
        return User::factory()->staff()->create();
    }

    /**
     * @return array{0: Shift, 1: User}
     */
    private function openShift(?User $actor = null, int $openingCash = 100_000): array
    {
        $actor ??= $this->staff();

        $shift = Shift::factory()->forUser($actor)->openedWith($openingCash, self::DEVICE)->create();

        return [$shift, $actor];
    }

    /**
     * Satu penjualan yang sudah dibayar, dengan pembayaran yang diminta.
     */
    private function paidSale(Shift $shift, int $total, PaymentMethod $method = PaymentMethod::Cash, SaleStatus $status = SaleStatus::Paid): Sale
    {
        $sale = Sale::factory()->create([
            'shift_id' => $shift->getKey(),
            'user_id' => $shift->user_id,
            'total' => $total,
            'status' => $status,
        ]);

        SalePayment::factory()->create([
            'sale_id' => $sale->getKey(),
            'method' => $method->value,
            'amount' => $total,
        ]);

        return $sale;
    }

    // ================= Membuka shift =================

    #[Test]
    public function a_cashier_can_open_a_shift(): void
    {
        $staff = $this->staff();

        $response = $this->actingAs($staff)
            ->withSession([DeviceId::SESSION_KEY => self::DEVICE])
            ->post(route('pos.shift-kasir.open'), ['opening_cash' => '250000']);

        $response->assertRedirect(route('pos.kasir'));

        $shift = Shift::query()->sole();

        $this->assertSame(ShiftStatus::Open, $shift->status);
        $this->assertSame(250_000, $shift->opening_cash);
        $this->assertSame($staff->getKey(), $shift->user_id);
        $this->assertSame(self::DEVICE, $shift->device_id);
    }

    #[Test]
    public function opening_cash_of_zero_is_allowed(): void
    {
        $this->actingAs($this->staff())
            ->withSession([DeviceId::SESSION_KEY => self::DEVICE])
            ->post(route('pos.shift-kasir.open'), ['opening_cash' => '0'])
            ->assertRedirect(route('pos.kasir'));

        $this->assertSame(0, Shift::query()->sole()->opening_cash);
    }

    #[Test]
    public function opening_cash_cannot_be_negative(): void
    {
        $this->actingAs($this->staff())
            ->withSession([DeviceId::SESSION_KEY => self::DEVICE])
            ->from(route('pos.shift-kasir'))
            ->post(route('pos.shift-kasir.open'), ['opening_cash' => '-1'])
            ->assertRedirect(route('pos.shift-kasir'))
            ->assertSessionHasErrors('opening_cash');

        $this->assertSame(0, Shift::query()->count());
    }

    #[Test]
    public function opening_cash_is_required(): void
    {
        $this->actingAs($this->staff())
            ->withSession([DeviceId::SESSION_KEY => self::DEVICE])
            ->post(route('pos.shift-kasir.open'), [])
            ->assertSessionHasErrors('opening_cash');

        $this->assertSame(0, Shift::query()->count());
    }

    #[Test]
    public function a_second_shift_on_the_same_device_is_refused(): void
    {
        $this->openShift($this->staff());
        $other = $this->staff();

        $this->actingAs($other)
            ->withSession([DeviceId::SESSION_KEY => self::DEVICE])
            ->from(route('pos.shift-kasir'))
            ->post(route('pos.shift-kasir.open'), ['opening_cash' => '50000'])
            ->assertRedirect(route('pos.shift-kasir'))
            ->assertSessionHas('toast');

        $this->assertSame(1, Shift::query()->count());
    }

    #[Test]
    public function a_second_shift_for_the_same_cashier_is_refused_even_on_another_device(): void
    {
        $staff = $this->staff();
        $this->openShift($staff);

        $this->actingAs($staff)
            ->withSession([DeviceId::SESSION_KEY => 'POS-LAIN-99'])
            ->from(route('pos.shift-kasir'))
            ->post(route('pos.shift-kasir.open'), ['opening_cash' => '50000'])
            ->assertSessionHas('toast');

        $this->assertSame(1, Shift::query()->count());
    }

    #[Test]
    public function a_closed_shift_does_not_block_the_next_one(): void
    {
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);

        app(ShiftService::class)->requestClose($shift, 100_000, $staff);

        $this->actingAs($staff)
            ->withSession([DeviceId::SESSION_KEY => self::DEVICE])
            ->post(route('pos.shift-kasir.open'), ['opening_cash' => '75000'])
            ->assertRedirect(route('pos.kasir'));

        $this->assertSame(2, Shift::query()->count());
    }

    #[Test]
    public function opening_a_shift_is_written_to_the_audit_log(): void
    {
        $staff = $this->staff();

        $this->actingAs($staff)
            ->withSession([DeviceId::SESSION_KEY => self::DEVICE])
            ->post(route('pos.shift-kasir.open'), ['opening_cash' => '250000']);

        $entry = AuditLog::query()->where('action', 'OPEN_SHIFT')->sole();

        $this->assertSame(Shift::class, $entry->entity);
        $this->assertSame(Shift::query()->sole()->getKey(), $entry->entity_id);
        $this->assertSame(250_000, $entry->after['opening_cash']);
        $this->assertSame($staff->getKey(), $entry->user_id);
    }

    #[Test]
    public function the_service_refuses_a_double_open_directly(): void
    {
        $staff = $this->staff();
        $this->openShift($staff);

        $service = app(ShiftService::class);

        $this->expectException(ShiftAlreadyOpenException::class);

        $service->open($staff, 50_000, self::DEVICE);
    }

    // ================= Menghitung uang =================

    #[Test]
    public function expected_cash_is_opening_cash_plus_cash_payments(): void
    {
        [$shift] = $this->openShift(null, 100_000);

        $this->paidSale($shift, 60_000, PaymentMethod::Cash);
        $this->paidSale($shift, 90_000, PaymentMethod::Qris);

        $service = app(ShiftService::class);

        $this->assertSame(60_000, $service->cashReceived($shift));
        $this->assertSame(160_000, $service->expectedCash($shift));
    }

    #[Test]
    public function voided_sales_are_not_counted_as_cash(): void
    {
        [$shift] = $this->openShift(null, 100_000);

        $this->paidSale($shift, 60_000, PaymentMethod::Cash);
        $this->paidSale($shift, 70_000, PaymentMethod::Cash, SaleStatus::Voided);

        $this->assertSame(160_000, app(ShiftService::class)->expectedCash($shift));
    }

    #[Test]
    public function sales_waiting_on_a_human_decision_are_not_counted_as_cash(): void
    {
        [$shift] = $this->openShift(null, 100_000);

        $this->paidSale($shift, 60_000, PaymentMethod::Cash);
        $this->paidSale($shift, 70_000, PaymentMethod::Cash, SaleStatus::SyncConflict);
        $this->paidSale($shift, 80_000, PaymentMethod::Cash, SaleStatus::TermsStale);

        $this->assertSame(160_000, app(ShiftService::class)->expectedCash($shift));
    }

    #[Test]
    public function sales_from_another_shift_are_not_counted(): void
    {
        [$shift] = $this->openShift(null, 100_000);
        [$other] = $this->openShift($this->staff(), 0);

        $this->paidSale($other, 500_000, PaymentMethod::Cash);

        $this->assertSame(100_000, app(ShiftService::class)->expectedCash($shift));
    }

    #[Test]
    public function the_summary_reports_every_payment_method_even_the_zero_ones(): void
    {
        [$shift] = $this->openShift(null, 100_000);

        $this->paidSale($shift, 60_000, PaymentMethod::Cash);

        $summary = app(ShiftService::class)->summary($shift);

        $this->assertSame(
            ['TUNAI', 'QRIS', 'EDC'],
            array_keys($summary->methods),
        );
        $this->assertSame(60_000, $summary->methods['TUNAI']['total']);
        $this->assertSame(1, $summary->methods['TUNAI']['count']);
        $this->assertSame(0, $summary->methods['QRIS']['total']);
        $this->assertSame(0, $summary->methods['QRIS']['count']);
        // Transfer belongs to paying consignors, not to the drawer. It must never
        // appear in a drawer reconciliation, not even as a zero row.
        $this->assertArrayNotHasKey('TRANSFER', $summary->methods);
        $this->assertSame(1, $summary->saleCount);
        $this->assertSame(60_000, $summary->saleTotal);
    }

    // ================= Menutup shift =================

    #[Test]
    public function a_cashier_can_close_a_shift_within_the_threshold(): void
    {
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($shift, 60_000);

        $this->actingAs($staff)
            ->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '162000'])
            ->assertRedirect(route('pos.shift-kasir'));

        $shift->refresh();

        $this->assertSame(ShiftStatus::Closed, $shift->status);
        $this->assertSame(162_000, $shift->closing_cash);
        $this->assertSame(2_000, $shift->cash_diff);
        $this->assertSame($staff->getKey(), $shift->closed_by);
        $this->assertNull($shift->cash_diff_approved_by);
        $this->assertNotNull($shift->closing_approved_at);
    }

    #[Test]
    public function a_cashier_closing_outside_the_threshold_waits_for_owner_approval(): void
    {
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($shift, 60_000);

        $this->actingAs($staff)
            ->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '180000'])
            ->assertRedirect(route('pos.shift-kasir'))
            ->assertSessionHasNoErrors();

        $shift->refresh();

        // Belum ditutup: lacinya sudah dihitung, tapi keputusan selisihnya
        // belum ada. `cash_diff` sengaja sudah tersimpan supaya Owner melihat
        // angka yang diajukan kasir, bukan angka yang dihitung ulang.
        $this->assertSame(ShiftStatus::PendingApproval, $shift->status);
        $this->assertSame(180_000, $shift->closing_cash);
        $this->assertSame(20_000, $shift->cash_diff);
        $this->assertSame($staff->getKey(), $shift->closed_by);
        $this->assertNull($shift->closed_at);
        $this->assertNull($shift->cash_diff_approved_by);
        $this->assertNull($shift->closing_approved_at);
        $this->assertNotNull($shift->closing_requested_at);
    }

    #[Test]
    public function the_pending_submission_keeps_the_cashiers_notes(): void
    {
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($shift, 60_000);

        $this->actingAs($staff)
            ->post(route('pos.shift-kasir.close', $shift), [
                'closing_cash' => '180000',
                'notes' => 'Kembalian Rp20.000 belum diambil dari laci.',
            ]);

        $this->assertSame(
            'Kembalian Rp20.000 belum diambil dari laci.',
            $shift->refresh()->closing_notes,
        );
    }

    #[Test]
    public function a_difference_at_the_threshold_needs_no_approval(): void
    {
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);

        // Tepat di ambang: masih pekerjaan kasir.
        $this->actingAs($staff)
            ->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '105000'])
            ->assertSessionHasNoErrors();

        $shift->refresh();

        $this->assertSame(ShiftStatus::Closed, $shift->status);
        $this->assertSame(5_000, $shift->cash_diff);
    }

    #[Test]
    public function a_missing_difference_also_waits_for_owner_approval(): void
    {
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);

        $this->actingAs($staff)
            ->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '90000']);

        $shift->refresh();

        $this->assertSame(ShiftStatus::PendingApproval, $shift->status);
        $this->assertSame(-10_000, $shift->cash_diff);
    }

    #[Test]
    public function an_owner_closes_an_over_threshold_shift_directly_and_records_themselves(): void
    {
        $owner = $this->owner();
        [$shift] = $this->openShift($owner, 100_000);

        $this->actingAs($owner)
            ->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '180000'])
            ->assertRedirect(route('pos.shift-kasir'))
            ->assertSessionHasNoErrors();

        $shift->refresh();

        $this->assertSame(ShiftStatus::Closed, $shift->status);
        $this->assertSame(80_000, $shift->cash_diff);
        $this->assertSame($owner->getKey(), $shift->closed_by);
        $this->assertSame($owner->getKey(), $shift->cash_diff_approved_by);
    }

    #[Test]
    public function only_an_owner_can_approve_a_pending_shift(): void
    {
        [$shift] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($shift, 60_000);

        $this->actingAs($shift->user)
            ->post(route('pos.persetujuan-shift.approve', $shift))
            ->assertForbidden();

        $this->assertSame(ShiftStatus::Open, $shift->refresh()->status);
    }

    #[Test]
    public function an_owner_approval_closes_the_shift_and_names_the_approver(): void
    {
        $owner = $this->owner();
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($shift, 60_000);

        $this->actingAs($staff)->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '180000']);

        $this->actingAs($owner)
            ->post(route('pos.persetujuan-shift.approve', $shift))
            ->assertRedirect(route('pos.persetujuan-shift'));

        $shift->refresh();

        $this->assertSame(ShiftStatus::Closed, $shift->status);
        $this->assertSame($staff->getKey(), $shift->closed_by);
        $this->assertSame($owner->getKey(), $shift->cash_diff_approved_by);
        $this->assertNotNull($shift->closed_at);
        $this->assertNotNull($shift->closing_approved_at);
    }

    #[Test]
    public function an_owner_rejection_returns_the_shift_to_the_cashier_with_the_reason(): void
    {
        $owner = $this->owner();
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($shift, 60_000);

        $this->actingAs($staff)->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '180000']);

        $this->actingAs($owner)
            ->post(route('pos.persetujuan-shift.reject', $shift), [
                'reason' => 'Uang Rp20.000 masih di dompet, bukan di laci.',
            ])
            ->assertRedirect(route('pos.persetujuan-shift'));

        $shift->refresh();

        // Angka yang ditolak dibuang, bukan disimpan sebagai "hitungan gagal":
        // kasir harus mulai dari laci yang sebenarnya, bukan dari angka yang
        // sudah terbukti salah.
        $this->assertSame(ShiftStatus::Open, $shift->status);
        $this->assertNull($shift->closing_cash);
        $this->assertNull($shift->cash_diff);
        $this->assertNull($shift->closed_by);
        $this->assertNull($shift->closing_requested_at);
        $this->assertSame(
            'Uang Rp20.000 masih di dompet, bukan di laci.',
            $shift->closing_rejection_reason,
        );
    }

    #[Test]
    public function a_rejection_must_carry_a_reason(): void
    {
        $owner = $this->owner();
        [$shift] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($shift, 60_000);

        $this->actingAs($shift->user)->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '180000']);

        $this->actingAs($owner)
            ->post(route('pos.persetujuan-shift.reject', $shift), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(ShiftStatus::PendingApproval, $shift->refresh()->status);
    }

    #[Test]
    public function a_rejected_shift_can_be_recounted_and_closed_again(): void
    {
        $owner = $this->owner();
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($shift, 60_000);

        $this->actingAs($staff)->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '180000']);
        $this->actingAs($owner)->post(route('pos.persetujuan-shift.reject', $shift), ['reason' => 'Hitung ulang.']);

        $this->actingAs($staff)
            ->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '160000'])
            ->assertSessionHasNoErrors();

        $shift->refresh();

        $this->assertSame(ShiftStatus::Closed, $shift->status);
        $this->assertNull($shift->closing_rejection_reason);
    }

    #[Test]
    public function a_pending_shift_refuses_a_second_count_and_tells_the_cashier_why(): void
    {
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($shift, 60_000);

        $this->actingAs($staff)->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '180000']);

        $this->actingAs($staff)
            ->from(route('pos.shift-kasir'))
            ->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '160000'])
            ->assertRedirect(route('pos.shift-kasir'))
            ->assertSessionHas('toast');

        $this->assertSame(180_000, $shift->refresh()->closing_cash);
    }

    #[Test]
    public function approving_a_shift_that_is_not_pending_is_refused(): void
    {
        $owner = $this->owner();
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);

        $this->actingAs($owner)
            ->post(route('pos.persetujuan-shift.approve', $shift))
            ->assertRedirect(route('pos.persetujuan-shift'))
            ->assertSessionHas('toast');

        $this->assertSame(ShiftStatus::Open, $shift->refresh()->status);
    }

    #[Test]
    public function submitting_a_closing_is_written_to_the_audit_log(): void
    {
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($shift, 60_000);

        $this->actingAs($staff)->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '180000']);

        $entry = AuditLog::query()->where('action', 'SUBMIT_SHIFT_CLOSING')->sole();

        $this->assertSame(ShiftStatus::Open->value, $entry->before['status']);
        $this->assertSame(ShiftStatus::PendingApproval->value, $entry->after['status']);
        $this->assertSame(20_000, $entry->after['cash_diff']);
        $this->assertSame(160_000, $entry->after['expected_cash']);
    }

    #[Test]
    public function approving_and_rejecting_are_written_to_the_audit_log(): void
    {
        $owner = $this->owner();

        [$pending] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($pending, 60_000);
        app(ShiftService::class)->requestClose($pending, 180_000, $pending->user);

        app(ShiftService::class)->approveClose($pending, $owner);

        $approved = AuditLog::query()->where('action', 'APPROVE_SHIFT_CLOSING')->sole();
        $this->assertSame(ShiftStatus::Closed->value, $approved->after['status']);
        $this->assertSame($owner->getKey(), $approved->after['cash_diff_approved_by']);

        [$rejected] = $this->openShift($this->staff(), 100_000);
        app(ShiftService::class)->requestClose($rejected, 180_000, $rejected->user);

        app(ShiftService::class)->rejectClose($rejected, $owner, 'Hitung ulang.');

        $refused = AuditLog::query()->where('action', 'REJECT_SHIFT_CLOSING')->sole();
        $this->assertSame(ShiftStatus::Open->value, $refused->after['status']);
        $this->assertSame('Hitung ulang.', $refused->after['closing_rejection_reason']);
    }

    #[Test]
    public function a_saved_threshold_is_the_one_that_decides_whether_approval_is_needed(): void
    {
        Setting::set(PosSettings::CASH_DIFFERENCE_THRESHOLD_KEY, 30_000);

        [$shift, $staff] = $this->openShift($this->staff(), 100_000);

        $this->actingAs($staff)
            ->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '120000'])
            ->assertSessionHasNoErrors();

        $shift->refresh();

        $this->assertSame(ShiftStatus::Closed, $shift->status);
        $this->assertSame(20_000, $shift->cash_diff);
    }

    #[Test]
    public function a_closed_shift_cannot_be_closed_again(): void
    {
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);
        app(ShiftService::class)->requestClose($shift, 100_000, $staff);

        $this->actingAs($staff)
            ->from(route('pos.shift-kasir'))
            ->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '150000'])
            ->assertRedirect(route('pos.shift-kasir'))
            ->assertSessionHas('toast');

        $this->assertSame(100_000, $shift->refresh()->closing_cash);
    }

    #[Test]
    public function the_service_refuses_a_double_close_directly(): void
    {
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);
        app(ShiftService::class)->requestClose($shift, 100_000, $staff);

        $this->expectException(ShiftAlreadyClosedException::class);

        app(ShiftService::class)->requestClose($shift, 200_000, $staff);
    }

    #[Test]
    public function closing_a_shift_is_written_to_the_audit_log_with_both_amounts(): void
    {
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($shift, 60_000);

        app(ShiftService::class)->requestClose($shift, 162_000, $staff);

        $entry = AuditLog::query()->where('action', 'CLOSE_SHIFT')->sole();

        $this->assertSame($shift->getKey(), $entry->entity_id);
        $this->assertSame(162_000, $entry->after['closing_cash']);
        $this->assertSame(160_000, $entry->after['expected_cash']);
        $this->assertSame(60_000, $entry->after['cash_received']);
        $this->assertSame(2_000, $entry->after['cash_diff']);
        $this->assertSame(ShiftStatus::Closed->value, $entry->after['status']);
    }

    #[Test]
    public function the_audit_before_shows_the_shift_as_it_was_not_as_it_became(): void
    {
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($shift, 60_000);

        app(ShiftService::class)->requestClose($shift, 162_000, $staff);

        $entry = AuditLog::query()->where('action', 'CLOSE_SHIFT')->sole();

        // `before` yang diambil setelah `save()` akan persis sama dengan
        // `after`, jadi satu-satunya bukti bahwa selisih Rp2.000 benar-benar
        // terjadi adalah baris ini.
        $this->assertSame(ShiftStatus::Open->value, $entry->before['status']);
        $this->assertSame(ShiftStatus::Closed->value, $entry->after['status']);
        $this->assertNull($entry->before['closing_cash']);
        $this->assertNull($entry->before['cash_diff']);
        $this->assertNull($entry->before['closed_at']);
        $this->assertNull($entry->before['closed_by']);

        // Bentuk ratanya harus sama persis, kalau tidak laporan audit tidak bisa
        // menampilkannya berdampingan tanpa logika per-field.
        $this->assertSame(array_keys($entry->before), array_keys($entry->after));
    }

    #[Test]
    public function the_audit_before_and_after_carry_the_figures_that_explain_the_difference(): void
    {
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($shift, 60_000);

        app(ShiftService::class)->requestClose($shift, 162_000, $staff);

        $entry = AuditLog::query()->where('action', 'CLOSE_SHIFT')->sole();

        foreach (['before', 'after'] as $side) {
            $this->assertSame(100_000, $entry->{$side}['opening_cash'], $side);
            $this->assertSame(160_000, $entry->{$side}['expected_cash'], $side);
            $this->assertSame(60_000, $entry->{$side}['cash_received'], $side);
            $this->assertSame(1, $entry->{$side}['sale_count'], $side);
        }
    }

    #[Test]
    public function opening_a_shift_writes_the_same_state_the_closing_one_starts_from(): void
    {
        $staff = $this->staff();
        $service = app(ShiftService::class);

        $shift = $service->open($staff, 100_000, self::DEVICE);

        $opened = AuditLog::query()->where('action', 'OPEN_SHIFT')->sole()->after;

        $service->requestClose($shift, 100_000, $staff);

        $closing = AuditLog::query()->where('action', 'CLOSE_SHIFT')->sole()->before;

        // Bentuk yang sama persis: laporan audit menampilkan `OPEN_SHIFT` dan
        // `CLOSE_SHIFT` berdampingan sebagai satu shift, bukan dua jenis yang
        // perlu dibaca dengan cara berbeda.
        $this->assertSame(array_keys($opened), array_keys($closing));

        // Dan isi `before` saat penutupan benar-benar keadaan setelah dibuka:
        // masih terbuka, belum ada uang penutup, belum ada yang menutup.
        $this->assertSame($opened['status'], $closing['status']);
        $this->assertSame($opened['opening_cash'], $closing['opening_cash']);
        $this->assertNull($closing['closing_cash']);
        $this->assertNull($closing['closed_at']);
    }

    // ================= Yang boleh menutup =================

    #[Test]
    public function a_cashier_cannot_close_another_cashiers_shift(): void
    {
        [$shift] = $this->openShift($this->staff(), 100_000);
        $other = $this->staff();

        $this->actingAs($other)
            ->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '100000'])
            ->assertForbidden();

        $this->assertSame(ShiftStatus::Open, $shift->refresh()->status);
        $this->assertNull($shift->closing_cash);
    }

    #[Test]
    public function an_owner_can_close_a_cashiers_shift_that_the_cashier_never_closed(): void
    {
        $owner = $this->owner();
        [$shift] = $this->openShift($this->staff(), 100_000);

        // Kasus nyata: kasirnya pulang tanpa menutup shift, dan uang di laci
        // masih ada. Menutupnya adalah pekerjaan Owner, bukan sesuatu yang harus
        // ditunggu sampai kasir itu kembali.
        $this->actingAs($owner)
            ->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '100000'])
            ->assertRedirect(route('pos.shift-kasir'));

        $shift->refresh();

        $this->assertSame(ShiftStatus::Closed, $shift->status);
        $this->assertSame($owner->getKey(), $shift->closed_by);
        $this->assertSame($shift->user_id, $shift->user_id);
        $this->assertNotSame($shift->closed_by, $shift->user_id);
    }

    // ================= Halaman =================

    #[Test]
    public function the_shift_page_offers_the_open_form_when_no_shift_is_running(): void
    {
        $this->actingAs($this->staff())
            ->get(route('pos.shift-kasir'))
            ->assertOk()
            ->assertSee('Buka Shift')
            ->assertSee(route('pos.shift-kasir.open'), false);
    }

    #[Test]
    public function the_shift_page_shows_the_running_shift_and_its_totals(): void
    {
        [$shift] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($shift, 60_000);

        $this->actingAs($shift->user)
            ->get(route('pos.shift-kasir'))
            ->assertOk()
            ->assertSee('Tutup Shift')
            ->assertSee('Rp160.000')
            ->assertSee('Rp60.000');
    }

    #[Test]
    public function the_shift_page_does_not_leak_another_cashiers_cash_to_staff(): void
    {
        $owner = $this->owner();
        [$shift] = $this->openShift($owner, 750_000);

        $this->actingAs($this->staff())
            ->get(route('pos.shift-kasir'))
            ->assertOk()
            ->assertDontSee('Rp750.000');
    }

    #[Test]
    public function the_owner_sees_every_shifts_cash(): void
    {
        $owner = $this->owner();
        [$shift] = $this->openShift($owner, 750_000);

        $this->actingAs($owner)
            ->get(route('pos.shift-kasir'))
            ->assertOk()
            ->assertSee('Rp750.000');
    }

    #[Test]
    public function a_pending_shift_page_hides_the_close_form_and_explains_the_wait(): void
    {
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($shift, 60_000);

        $this->actingAs($staff)->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '180000']);

        $this->actingAs($staff)
            ->get(route('pos.shift-kasir'))
            ->assertOk()
            ->assertSee('Menunggu Persetujuan Owner')
            ->assertSee('Rp180.000')
            ->assertDontSee(route('pos.shift-kasir.close', $shift), false);
    }

    #[Test]
    public function a_rejected_shift_page_shows_the_owner_reason(): void
    {
        $owner = $this->owner();
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($shift, 60_000);

        $this->actingAs($staff)->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '180000']);
        $this->actingAs($owner)->post(route('pos.persetujuan-shift.reject', $shift), [
            'reason' => 'Uang Rp20.000 masih di dompet, bukan di laci.',
        ]);

        $this->actingAs($staff)
            ->get(route('pos.shift-kasir'))
            ->assertOk()
            ->assertSee('Uang Rp20.000 masih di dompet, bukan di laci.')
            ->assertSee('Tutup Shift');
    }

    #[Test]
    public function the_owner_sees_pending_closings_with_their_figures(): void
    {
        $owner = $this->owner();
        [$shift, $staff] = $this->openShift($this->staff(), 100_000);
        $this->paidSale($shift, 60_000);

        $this->actingAs($staff)->post(route('pos.shift-kasir.close', $shift), ['closing_cash' => '180000']);

        $this->actingAs($owner)
            ->get(route('pos.persetujuan-shift'))
            ->assertOk()
            ->assertSee($staff->name)
            ->assertSee('Rp180.000')
            ->assertSee('Rp20.000')
            ->assertSee(route('pos.persetujuan-shift.approve', $shift), false)
            ->assertSee(route('pos.persetujuan-shift.reject', $shift), false);
    }

    #[Test]
    public function staff_cannot_open_the_shift_approval_page(): void
    {
        $this->actingAs($this->staff())
            ->get(route('pos.persetujuan-shift'))
            ->assertForbidden();
    }

    #[Test]
    public function the_approval_page_says_so_when_nothing_is_waiting(): void
    {
        $this->actingAs($this->owner())
            ->get(route('pos.persetujuan-shift'))
            ->assertOk()
            ->assertSee('Tidak Ada yang Menunggu');
    }
}
