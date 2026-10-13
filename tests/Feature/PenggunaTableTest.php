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
        // Aktor dipisah dari baris data: nama pemilik yang login ikut tampil di
        // sidebar dan topbar, sehingga tidak boleh menimpa urutan baris tabel.
        $actor = User::factory()->owner()->create(['name' => 'Andi Saputra']);
        User::factory()->owner()->create(['name' => 'Zeta Owner']);
        User::factory()->staff()->create(['name' => 'Alpha Staf']);

        $html = $this->actingAs($actor)
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
        // Aktor dipisah dari baris data: nama pemilik yang login ikut tampil di
        // sidebar dan topbar, sehingga baris Owner yang disaring harus milik
        // pengguna lain agar pengecekan tidak ikut menangkap chrome halaman.
        $actor = User::factory()->owner()->create(['name' => 'Andi Saputra']);
        User::factory()->owner()->create(['name' => 'Owner Satu']);
        User::factory()->staff()->create(['name' => 'Staf Satu']);

        $this->actingAs($actor)
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

    // ===== Owner: role Developer tidak boleh muncul atau dipakai =====

    #[Test]
    public function owner_cannot_choose_the_dev_role_when_creating_a_user(): void
    {
        $owner = User::factory()->owner()->create();

        $html = $this->actingAs($owner)
            ->get(route('setting.pengguna.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Owner', $html, 'Owner tetap boleh memilih Owner.');
        $this->assertStringContainsString('Staff', $html, 'Owner tetap boleh memilih Staff.');
        $this->assertStringNotContainsString('Developer', $html, 'Opsi Developer harus hilang dari form Owner.');
    }

    #[Test]
    public function owner_cannot_choose_the_dev_role_when_editing_a_user(): void
    {
        $owner = User::factory()->owner()->create();
        $target = User::factory()->staff()->create();

        $html = $this->actingAs($owner)
            ->get(route('setting.pengguna.edit', $target))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Developer', $html, 'Opsi Developer harus hilang dari form edit Owner.');
    }

    #[Test]
    public function owner_cannot_create_a_user_with_role_dev(): void
    {
        $owner = User::factory()->owner()->create();

        // Dropdown memang disembunyikan, tapi request bisa dibuat tangan:
        // validasi server-lah yang menolak, bukan UI-nya.
        $this->actingAs($owner)->post(route('setting.pengguna.store'), [
            'name' => 'Developer Baru',
            'username' => 'developer.baru',
            'role' => Role::Dev->value,
            'password' => 'rahasia123',
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['username' => 'developer.baru']);
    }

    #[Test]
    public function owner_cannot_change_a_user_role_to_dev(): void
    {
        $owner = User::factory()->owner()->create();
        $target = User::factory()->staff()->create(['username' => 'staf-umum']);

        $this->actingAs($owner)->put(route('setting.pengguna.update', $target), [
            'name' => $target->name,
            'username' => 'staf-umum',
            'role' => Role::Dev->value,
        ])->assertSessionHasErrors('role');

        $this->assertSame(Role::Staff->value, $target->fresh()->role->value, 'Role target tidak boleh berubah.');
    }

    #[Test]
    public function owner_does_not_see_dev_users_in_the_list(): void
    {
        $owner = User::factory()->owner()->create(['name' => 'Ahmad Fauzi']);
        $dev = User::factory()->dev()->create(['name' => 'Si Developer']);

        $html = $this->actingAs($owner)
            ->get(route('setting.pengguna'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Si Developer', $html, 'Baris Developer tidak boleh muncul.');
        $this->assertStringNotContainsString('Developer', $html, 'Filter/opsi Developer tidak boleh muncul.');
        $this->assertDatabaseHas('users', ['id' => $dev->id, 'role' => Role::Dev->value]);
    }

    #[Test]
    public function owner_cannot_edit_or_toggle_a_dev_user(): void
    {
        $owner = User::factory()->owner()->create();
        $dev = User::factory()->dev()->create();

        $this->actingAs($owner)->get(route('setting.pengguna.edit', $dev))->assertForbidden();
        $this->actingAs($owner)->patch(route('setting.pengguna.toggle', $dev))->assertForbidden();

        $this->assertTrue($dev->fresh()->is_active, 'Penolakan tidak boleh mengubah status akun Developer.');
        $this->assertSame(Role::Dev->value, $dev->fresh()->role->value);
    }

    // ===== Developer: boleh mengelola pengguna penuh =====

    #[Test]
    public function dev_can_open_the_user_list_and_the_create_form(): void
    {
        $dev = User::factory()->dev()->create();

        $this->actingAs($dev)->get(route('setting.pengguna'))->assertOk();
        $this->actingAs($dev)->get(route('setting.pengguna.create'))->assertOk();
    }

    #[Test]
    public function dev_can_create_a_dev_user(): void
    {
        $dev = User::factory()->dev()->create();

        $this->actingAs($dev)->post(route('setting.pengguna.store'), [
            'name' => 'Developer Baru',
            'username' => 'developer.baru',
            'role' => Role::Dev->value,
            'password' => 'rahasia123',
        ])->assertRedirect(route('setting.pengguna'));

        $this->assertDatabaseHas('users', ['username' => 'developer.baru', 'role' => Role::Dev->value]);
    }

    #[Test]
    public function dev_sees_dev_users_in_the_list(): void
    {
        $dev = User::factory()->dev()->create(['name' => 'Si Developer']);
        User::factory()->dev()->create(['name' => 'Saudara Developer']);

        $this->actingAs($dev)
            ->get(route('setting.pengguna'))
            ->assertOk()
            ->assertSee('Saudara Developer')
            ->assertSee('Developer');
    }

    #[Test]
    public function dev_can_edit_a_dev_user(): void
    {
        $actor = User::factory()->dev()->create();
        $target = User::factory()->dev()->create(['username' => 'dev-tujuan']);

        $this->actingAs($actor)
            ->get(route('setting.pengguna.edit', $target))
            ->assertOk();
    }

    #[Test]
    public function dev_still_cannot_open_other_settings(): void
    {
        // Memberi akses pengelolaan pengguna tidak boleh membuka Pengaturan
        // lain yang tetap milik Owner.
        $dev = User::factory()->dev()->create();

        $this->actingAs($dev)->get(route('setting.perangkat'))->assertForbidden();
    }

    #[Test]
    public function staff_cannot_reach_user_management_even_though_dev_can(): void
    {
        $staff = User::factory()->staff()->create();
        $target = User::factory()->staff()->create();

        $this->actingAs($staff)->get(route('setting.pengguna'))->assertForbidden();
        $this->actingAs($staff)->post(route('setting.pengguna.store'), ['role' => Role::Owner->value])->assertForbidden();
        $this->actingAs($staff)->get(route('setting.pengguna.edit', $target))->assertForbidden();
    }
}
