<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PenggunaTableTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_a_paginated_table_instead_of_loading_every_row(): void
    {
        $owner = User::factory()->owner()->create();

        foreach (range(1, 12) as $i) {
            User::factory()->staff()->create(['name' => 'Staf '.$i]);
        }

        $this->actingAs($owner)
            ->get(route('setting.pengguna'))
            ->assertOk()
            ->assertSee('Staf 1')
            ->assertSee('Staf 9')
            ->assertDontSee('Staf 10')
            ->assertSee('dari 13 data');
    }

    #[Test]
    public function it_sorts_on_the_server(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Zeta Owner']);
        User::factory()->staff()->create(['name' => 'Alpha Staf']);

        $html = $this->actingAs($owner)
            ->get(route('setting.pengguna', ['sort' => 'name', 'direction' => 'asc']))
            ->assertOk()
            ->getContent();

        $this->assertLessThan(strpos($html, 'Zeta Owner'), strpos($html, 'Alpha Staf'));
    }

    #[Test]
    public function it_searches_through_name_username_and_email(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Ahmad Fauzi']);
        User::factory()->staff()->create([
            'name' => 'Budi Santoso',
            'username' => 'budisan',
            'email' => 'budi@example.test',
        ]);
        User::factory()->staff()->create([
            'name' => 'Siti Aminah',
            'username' => 'siti',
            'email' => 'siti@example.test',
        ]);

        $this->actingAs($owner)
            ->get(route('setting.pengguna', ['q' => 'budisan']))
            ->assertOk()
            ->assertSee('Budi Santoso')
            ->assertDontSee('Siti Aminah')
            ->assertSee('dari 1 data');
    }

    #[Test]
    public function it_filters_by_role(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Owner Satu']);
        User::factory()->staff()->create(['name' => 'Staf Satu']);

        $this->actingAs($owner)
            ->get(route('setting.pengguna', ['role' => Role::Staff->value]))
            ->assertOk()
            ->assertSee('Staf Satu')
            ->assertDontSee('Owner Satu');
    }

    #[Test]
    public function it_marks_the_current_user(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Ahmad Fauzi']);
        User::factory()->staff()->create(['name' => 'Budi Santoso']);

        $this->actingAs($owner)
            ->get(route('setting.pengguna'))
            ->assertOk()
            ->assertSee('Anda');
    }

    #[Test]
    public function it_renders_role_and_status_badges(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Ahmad Fauzi']);
        User::factory()->staff()->create(['name' => 'Budi Santoso', 'is_active' => false]);

        $this->actingAs($owner)
            ->get(route('setting.pengguna'))
            ->assertOk()
            ->assertSee('Owner')
            ->assertSee('Staff')
            ->assertSee('Non-aktif');
    }

    #[Test]
    public function it_switches_between_deactivate_and_activate(): void
    {
        $owner = User::factory()->owner()->create();
        User::factory()->staff()->create(['name' => 'Aktif', 'is_active' => true]);
        User::factory()->staff()->create(['name' => 'Nonaktif', 'is_active' => false]);

        $this->actingAs($owner)
            ->get(route('setting.pengguna'))
            ->assertOk()
            ->assertSee('Nonaktif')
            ->assertSee('Aktifkan');
    }

    #[Test]
    public function staff_cannot_open_the_user_list_at_all(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create(['name' => 'Budi Santoso']);

        // Halaman ini tidak hanya menyembunyikan tombolnya dari Staff -- ia
        // menutup pintunya. Dulu `index()` tidak punya gate, jadi Staff bisa
        // membuka URL langsung dan membaca nama, username, email, dan role
        // setiap pengguna walaupun menunya disembunyikan dari sidebar.
        $this->actingAs($staff)
            ->get(route('setting.pengguna'))
            ->assertForbidden();

        $this->assertTrue($owner->fresh()->is_active);
    }

    #[Test]
    public function staff_cannot_reach_any_user_management_route(): void
    {
        $owner = User::factory()->owner()->create();
        $staff = User::factory()->staff()->create();
        $target = User::factory()->staff()->create();

        $routes = [
            ['get', route('setting.pengguna')],
            ['get', route('setting.pengguna.create')],
            ['post', route('setting.pengguna.store')],
            ['get', route('setting.pengguna.edit', $target)],
            ['put', route('setting.pengguna.update', $target)],
            ['patch', route('setting.pengguna.toggle', $target)],
        ];

        foreach ($routes as [$method, $url]) {
            $this->actingAs($staff)->{$method}($url)->assertForbidden();
        }

        $this->assertTrue($target->fresh()->is_active, 'Penolakan tidak boleh mengubah apa pun.');
    }

    #[Test]
    public function an_owner_sees_the_full_management_surface(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Ahmad Fauzi']);
        User::factory()->staff()->create(['name' => 'Budi Santoso', 'is_active' => false]);

        $this->actingAs($owner)
            ->get(route('setting.pengguna'))
            ->assertOk()
            ->assertSee('Tambah Pengguna')
            ->assertSee('Aktifkan')
            ->assertSee('Budi Santoso');
    }

    #[Test]
    public function it_ignores_an_unwhitelisted_sort_column(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Ahmad Fauzi']);

        $this->actingAs($owner)
            ->get(route('setting.pengguna', ['sort' => 'email', 'direction' => 'desc']))
            ->assertOk()
            ->assertSee('Ahmad Fauzi');
    }

    #[Test]
    public function it_shows_an_empty_state_when_a_filter_excludes_everything(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get(route('setting.pengguna', ['q' => 'tidak-ada']))
            ->assertOk()
            ->assertSee('Pengguna tidak ditemukan');
    }
}
