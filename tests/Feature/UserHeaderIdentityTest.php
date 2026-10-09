<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Identitas di header dan footer sidebar berasal dari user yang login.
 *
 * Nama, role, inisial avatar, dan shift diambil dari data nyata, bukan teks
 * mock. Nilainya dihitung sekali per request di `layouts/app.blade.php`, jadi
 * sidebar dan topbar selalu menampilkan orang yang sama pada request yang sama.
 */
class UserHeaderIdentityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_identity_strings_follow_the_logged_in_staff(): void
    {
        $html = $this->actingAs(User::factory()->create(['name' => 'Dewi Lestari']))
            ->get(route('pos.kasir'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('">Dewi Lestari</p>', $html);
        $this->assertStringContainsString('">Staff</p>', $html);
        $this->assertStringContainsString('aria-label="Menu profil Dewi Lestari">DL</button>', $html);
        $this->assertStringContainsString('Belum buka shift · Dewi Lestari', $html);
        $this->assertStringContainsString('title="Tersinkron · Belum buka shift"', $html);

        $this->assertStringNotContainsString('Ahmad Fauzi', $html);
        $this->assertStringNotContainsString('Kasir · Reguler', $html);
        $this->assertStringNotContainsString('Shift Reguler 1', $html);
    }

    #[Test]
    public function the_owner_sees_the_owner_role_label(): void
    {
        $html = $this->actingAs(User::factory()->owner()->create(['name' => 'Budi Santoso']))
            ->get(route('dashboard'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('">Budi Santoso</p>', $html);
        $this->assertStringContainsString('">Owner</p>', $html);
        $this->assertStringContainsString('aria-label="Menu profil Budi Santoso">BS</button>', $html);
    }

    #[Test]
    public function the_sidebar_shows_the_shift_open_for_the_user(): void
    {
        $user = User::factory()->create(['name' => 'Dewi Lestari']);

        Shift::factory()->forUser($user)->forDevice('WMS-01')->create();

        $html = $this->actingAs($user)
            ->get(route('pos.kasir'))
            ->assertOk()
            ->content();

        $this->assertStringContainsString('WMS-01 · Dewi Lestari', $html);
        $this->assertStringContainsString('title="Tersinkron · WMS-01"', $html);
        $this->assertStringNotContainsString('Belum buka shift', $html);
    }
}
