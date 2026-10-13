<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\QuarantineStatus;
use App\Models\QuarantineCase;
use App\Models\Setting;
use App\Models\User;
use App\Services\Inventory\QuarantineSla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Halaman Karantina membaca kasus terbuka dari tabel QuarantineCase.
 *
 * Antrean, kartu KPI, dan badge SLA semuanya dihitung dari data nyata plus
 * ambang `QuarantineSla` (baca Setting, jatuh ke bawaan 3/7 hari).
 */
class KarantinaPageTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    private function aged(int $days, array $extra = []): QuarantineCase
    {
        return QuarantineCase::factory()->create($extra + [
            'created_at' => now()->subDays($days),
        ]);
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $this->get(route('inventory.karantina'))->assertRedirect(route('login'));
    }

    #[Test]
    public function only_open_cases_appear_in_the_queue(): void
    {
        $open = $this->aged(1, ['case_no' => 'QC-OPEN-001']);
        $this->aged(1, ['case_no' => 'QC-CLOSED-001', 'status' => QuarantineStatus::Closed]);

        $html = $this->actingAs($this->owner())
            ->get(route('inventory.karantina'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString($open->case_no, $html);
        $this->assertStringNotContainsString('QC-CLOSED-001', $html);
    }

    #[Test]
    public function the_queue_shows_real_attributes_of_each_case(): void
    {
        $this->aged(1, [
            'attributes' => [
                'model' => 'Ford Mustang GT',
                'series' => 'American Scene',
                'color' => 'Oxford White',
                'condition' => 'Card Mint',
                'reason' => 'BARCODE TIDAK TERBACA',
            ],
            'qty' => 2,
        ]);

        $html = $this->actingAs($this->owner())
            ->get(route('inventory.karantina'))
            ->assertOk()
            ->content();

        // Kunci yang dulu di-hardcode di MockData kini berasal dari kolom
        // attributes, dan panel detail membaca model/seri dari kasus yang
        // dipilih.
        $this->assertStringContainsString('Ford Mustang GT', $html);
        $this->assertStringContainsString('American Scene', $html);
        $this->assertStringContainsString('BARCODE TIDAK TERBACA', $html);
    }

    #[Test]
    public function a_case_without_attributes_falls_back_to_a_readable_label(): void
    {
        $this->aged(1, ['attributes' => []]);

        $html = $this->actingAs($this->owner())
            ->get(route('inventory.karantina'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('Belum teridentifikasi', $html);
    }

    #[Test]
    public function the_kpi_counts_and_unit_total_come_from_the_database(): void
    {
        // 9 hari = lewat eskalasi; 5 hari = lewat review; 2 hari = masih segar.
        $this->aged(9, ['qty' => 2]);
        $this->aged(5, ['qty' => 1, 'status' => QuarantineStatus::Escalated]);
        $this->aged(2, ['qty' => 3]);
        $this->aged(4, ['qty' => 9, 'status' => QuarantineStatus::Closed]);

        $html = $this->actingAs($this->owner())
            ->get(route('inventory.karantina'))
            ->assertOk()
            ->content();

        // Kasus terbuka = 3 (closed tidak dihitung); unit = 2+1+3 = 6.
        $this->assertStringContainsString('Kasus Terbuka', $html);
        $this->assertStringContainsString('Unit KARANTINA', $html);
        $this->assertMatchesRegularExpression('/Kasus Terbuka.*?>\s*3\s*</s', $html);
        $this->assertMatchesRegularExpression('/Unit KARANTINA.*?>\s*6\s*</s', $html);
        $this->assertMatchesRegularExpression('/Aging &gt; 3 Hari.*?>\s*2\s*</s', $html);
        $this->assertMatchesRegularExpression('/Aging &gt; 7 Hari.*?>\s*1\s*</s', $html);
    }

    #[Test]
    public function the_sla_thresholds_come_from_settings_and_fall_back_to_defaults(): void
    {
        $this->aged(9);

        $default = $this->actingAs($this->owner())
            ->get(route('inventory.karantina'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString(
            'SLA: assign dalam '.QuarantineSla::DEFAULT_REVIEW_DAYS.' hari, eskalasi '.QuarantineSla::DEFAULT_ESCALATE_DAYS.' hari',
            $default,
        );

        Setting::set(QuarantineSla::REVIEW_SETTING_KEY, 5);
        Setting::set(QuarantineSla::ESCALATE_SETTING_KEY, 10);

        $overridden = $this->actingAs($this->owner())
            ->get(route('inventory.karantina'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('SLA: assign dalam 5 hari, eskalasi 10 hari', $overridden);
    }

    #[Test]
    public function the_page_renders_with_no_cases_at_all(): void
    {
        $html = $this->actingAs($this->owner())
            ->get(route('inventory.karantina'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('Tidak ada kasus terbuka', $html);
    }
}
