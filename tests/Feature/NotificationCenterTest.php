<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LabelStatus;
use App\Enums\QuarantineStatus;
use App\Models\LabelPrintJob;
use App\Models\Opname;
use App\Models\QuarantineCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pusat notifikasi topbar (panel bell) bersumber dari data nyata.
 *
 * Item dihitung live dari tabel yang ada -- opname yang menunggu persetujuan
 * Owner, kasus karantina terbuka, dan label yang belum dikonfirmasi -- supaya
 * badge dan daftar tidak bisa beda dari dashboard. Panel di-render server-side,
 * jadi test ini membaca HTML halaman. Item semuanya mengarah ke modul non-POS
 * yang kini Owner-only, sehingga Staff selalu melihat panel kosong.
 */
class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_panel_is_empty_when_nothing_needs_review(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->get(route('pos.kasir'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('0 menunggu tindakan', $html);
        $this->assertStringContainsString('Semua beres. Tidak ada yang perlu ditinjau.', $html);
        $this->assertStringNotContainsString('Tidak ada notifikasi baru', $html);
        $this->assertStringNotContainsString('bg-error-text text-label-sm text-on-primary', $html);
    }

    #[Test]
    public function the_owner_sees_opnames_waiting_approval_but_staff_do_not(): void
    {
        Opname::factory()->pendingApproval()->create();

        $ownerHtml = $this->actingAs(User::factory()->owner()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('Persetujuan Opname', $ownerHtml);
        $this->assertStringContainsString('1 sesi opname menunggu persetujuan kamu', $ownerHtml);
        $this->assertStringContainsString('/inventory/stok-opname', $ownerHtml);
        $this->assertStringContainsString('1 menunggu tindakan', $ownerHtml);

        $staffHtml = $this->actingAs(User::factory()->create())
            ->get(route('pos.kasir'))
            ->assertOk()
            ->content();

        $this->assertStringNotContainsString('Persetujuan Opname', $staffHtml);
        $this->assertStringNotContainsString('opname menunggu persetujuan', $staffHtml);
        $this->assertStringContainsString('0 menunggu tindakan', $staffHtml);
    }

    #[Test]
    public function karantina_and_label_items_come_from_the_real_counts(): void
    {
        QuarantineCase::factory()->create();
        LabelPrintJob::factory()->sent()->create();

        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('Kasus Karantina', $html);
        $this->assertStringContainsString('1 kasus karantina menunggu verifikasi', $html);
        $this->assertStringContainsString('/inventory/karantina', $html);

        $this->assertStringContainsString('Label Belum Dikonfirmasi', $html);
        $this->assertStringContainsString('1 label menunggu konfirmasi cetak', $html);
        $this->assertStringContainsString('/inbound/cetak-label', $html);

        $this->assertStringContainsString('2 menunggu tindakan', $html);
    }

    #[Test]
    public function items_disappear_once_resolved(): void
    {
        $quarantine = QuarantineCase::factory()->create();
        $label = LabelPrintJob::factory()->sent()->create();

        $quarantine->update(['status' => QuarantineStatus::Closed]);
        $label->refresh();
        $label->update(['status' => LabelStatus::Confirmed, 'confirmed_at' => now()]);

        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->content();

        $this->assertStringNotContainsString('kasus karantina menunggu verifikasi', $html);
        $this->assertStringNotContainsString('label menunggu konfirmasi cetak', $html);
        $this->assertStringContainsString('0 menunggu tindakan', $html);
    }

    #[Test]
    public function the_badge_counts_all_open_items(): void
    {
        Opname::factory()->pendingApproval()->create();
        QuarantineCase::factory()->create();
        LabelPrintJob::factory()->sent()->create();

        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('3 menunggu tindakan', $html);
        $this->assertStringContainsString('bg-error-text text-label-sm text-on-primary', $html);
    }

    #[Test]
    public function the_oldest_case_age_is_appended_to_the_message(): void
    {
        $case = QuarantineCase::factory()->create();
        $case->forceFill(['created_at' => now()->subDays(3)])->save();

        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('1 kasus karantina menunggu verifikasi · tertua 3 hari', $html);
    }
}
