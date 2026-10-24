<?php

namespace Tests\Feature\Pos;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_admin_link_in_pos_shell(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner]);

        $this->actingAs($owner)
            ->get('/pos/kasir')
            ->assertOk()
            ->assertSee('Admin');
    }

    public function test_staff_does_not_see_admin_link_in_pos_shell(): void
    {
        $staff = User::factory()->create(['role' => Role::Staff]);

        $this->actingAs($staff)
            ->get('/pos/kasir')
            ->assertOk()
            ->assertDontSee('Admin');
    }

    public function test_pos_pages_do_not_render_admin_sidebar(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner]);

        $this->actingAs($owner)
            ->get('/pos/kasir')
            ->assertOk()
            ->assertDontSee('id="app-sidebar"');
    }

    public function test_pos_shell_has_an_alpine_root_so_bare_directives_run(): void
    {
        $owner = User::factory()->create(['role' => Role::Owner]);

        // `x-money` hanya diinisialisasi di dalam root Alpine. Tanpa root di
        // layout POS, input uang pembuka dirender tanpa pemisah ribuan meski
        // atributnya ada.
        $this->actingAs($owner)
            ->get('/pos/shift-kasir')
            ->assertOk()
            ->assertSee('class="flex min-h-full flex-col" x-data', false);
    }

    public function test_riwayat_and_shift_and_nota_use_pos_shell_for_staff(): void
    {
        $staff = User::factory()->create(['role' => Role::Staff]);

        $this->actingAs($staff)
            ->get('/pos/riwayat-transaksi')
            ->assertOk()
            ->assertDontSee('id="app-sidebar"');

        $this->actingAs($staff)
            ->get('/pos/shift-kasir')
            ->assertOk()
            ->assertDontSee('id="app-sidebar"');
    }
}
