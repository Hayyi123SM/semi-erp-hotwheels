<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\QuarantineStatus;
use App\Models\QuarantineCase;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Info status di header (topbar & sidebar) bersumber dari data nyata.
 *
 * Badge karantina memakai kasus terbuka di tabel QuarantineCase (definisi yang
 * sama dengan dashboard), label toko memakai setelan store.name & store.branch,
 * dan angka mock ("Antrean sync: 2", "Karantina aging: 1") tidak boleh muncul.
 */
class HeaderStatusTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_store_identity_falls_back_to_the_seeded_defaults(): void
    {
        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('167 Diecast Shop · Cassiopeia Plaza', $html);
    }

    #[Test]
    public function the_store_identity_comes_from_settings(): void
    {
        Setting::set('store.name', 'Diecast Raya');
        Setting::set('store.branch', 'Central Park');

        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('Diecast Raya · Central Park', $html);
        $this->assertStringNotContainsString('Cassiopeia Plaza', $html);
        $this->assertStringNotContainsString('167 Diecast Shop', $html);
    }

    #[Test]
    public function the_karantina_badge_counts_open_cases_and_ages_the_oldest(): void
    {
        QuarantineCase::factory()->count(2)->create();
        QuarantineCase::factory()->create([
            'status' => QuarantineStatus::Closed,
            'created_at' => now()->subDays(9),
        ]);

        QuarantineCase::query()
            ->where('status', QuarantineStatus::Open)
            ->first()
            ->forceFill(['created_at' => now()->subDays(3)])
            ->save();

        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('Karantina: 2', $html);
        $this->assertStringContainsString('Tertua 3 hari', $html);

        $this->assertStringNotContainsString('Karantina aging: 1', $html);
        $this->assertStringNotContainsString('Karantina: 1', $html);
    }

    #[Test]
    public function the_karantina_badge_hides_when_no_case_is_open(): void
    {
        QuarantineCase::factory()->create(['status' => QuarantineStatus::Closed]);

        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->content();

        $this->assertStringNotContainsString('Karantina:', $html);
    }

    #[Test]
    public function the_sidebar_karantina_badge_uses_the_same_count(): void
    {
        QuarantineCase::factory()->count(2)->create();

        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('inventory.karantina'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('2 barang karantina', $html);
        $this->assertStringNotContainsString('3 barang karantina', $html);
    }

    #[Test]
    public function the_mock_header_numbers_are_gone(): void
    {
        $html = $this->actingAs(User::factory()->owner()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->content();

        $this->assertStringNotContainsString('Antrean sync: 2', $html);
        $this->assertStringNotContainsString('Karantina aging: 1', $html);
    }
}
