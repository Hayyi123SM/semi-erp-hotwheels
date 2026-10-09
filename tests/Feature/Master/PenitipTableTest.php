<?php

namespace Tests\Feature\Master;

use App\Enums\ConsignorStatus;
use App\Enums\LedgerType;
use App\Enums\SchemeType;
use App\Models\Consignor;
use App\Models\ConsignorLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PenitipTableTest extends TestCase
{
    use RefreshDatabase;

    private function penitip(string $name, array $overrides = []): Consignor
    {
        return Consignor::create(array_merge([
            'consignor_code' => 'CN'.str_pad((string) Consignor::count() + 1, 2, '0', STR_PAD_LEFT),
            'name' => $name,
            'status' => ConsignorStatus::Active,
            'scheme_type' => SchemeType::Percentage,
            'scheme_rate' => 10,
        ], $overrides));
    }

    #[Test]
    public function it_renders_a_paginated_table_instead_of_loading_every_row(): void
    {
        $owner = User::factory()->owner()->create();

        foreach (range(1, 12) as $i) {
            $this->penitip('Penitip '.$i);
        }

        $this->actingAs($owner)
            ->get(route('master.penitip'))
            ->assertOk()
            ->assertSee('Penitip 1')
            ->assertSee('Penitip 10')
            ->assertDontSee('Penitip 11')
            ->assertSee('dari 12 data');
    }

    #[Test]
    public function it_sorts_on_the_server(): void
    {
        $owner = User::factory()->owner()->create();
        $this->penitip('Zeta');
        $this->penitip('Alpha');

        $html = $this->actingAs($owner)
            ->get(route('master.penitip', ['sort' => 'name', 'direction' => 'asc']))
            ->assertOk()
            ->getContent();

        $this->assertLessThan(strpos($html, 'Zeta'), strpos($html, 'Alpha'));
    }

    #[Test]
    public function it_searches_through_code_and_whatsapp_number(): void
    {
        $owner = User::factory()->owner()->create();
        $this->penitip('Budi Santoso', ['wa_number' => '08123456789']);
        $this->penitip('Andi Saputra', ['wa_number' => '08987654321']);

        $this->actingAs($owner)
            ->get(route('master.penitip', ['q' => '08123']))
            ->assertOk()
            ->assertSee('Budi Santoso')
            ->assertDontSee('Andi Saputra');
    }

    #[Test]
    public function it_filters_by_status(): void
    {
        $owner = User::factory()->owner()->create();
        $this->penitip('Penitip Aktif', ['status' => ConsignorStatus::Active]);
        $this->penitip('Penitip Arsip', ['status' => ConsignorStatus::Archived]);

        $this->actingAs($owner)
            ->get(route('master.penitip', ['status' => ConsignorStatus::Archived->value]))
            ->assertOk()
            ->assertSee('Penitip Arsip')
            ->assertDontSee('Penitip Aktif');
    }

    #[Test]
    public function it_renders_the_scheme_badge_without_asking_users_for_private_rates(): void
    {
        $owner = User::factory()->owner()->create();
        $this->penitip('Budi', ['scheme_type' => SchemeType::Percentage, 'scheme_rate' => 12.5]);
        $this->penitip('Andi', ['scheme_type' => SchemeType::Flat, 'scheme_amount' => 7500, 'scheme_rate' => null]);

        $this->actingAs($owner)
            ->get(route('master.penitip'))
            ->assertOk()
            ->assertSee('12.5% dari harga jual')
            ->assertSee('Flat Rp7.500/unit');
    }

    /**
     * Halaman penitip hanya untuk Owner, jadi Staff ditolak sejak di pintu.
     */
    #[Test]
    public function staff_cannot_open_the_consignor_table(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->get(route('master.penitip'))
            ->assertForbidden();
    }

    #[Test]
    public function it_computes_the_due_balance_from_the_ledger(): void
    {
        $owner = User::factory()->owner()->create();
        $consignor = $this->penitip('Budi');

        ConsignorLedger::create([
            'consignor_id' => $consignor->id,
            'type' => LedgerType::SaleAccrual,
            'amount' => 500000,
            'reference' => 'INV-1',
        ]);
        ConsignorLedger::create([
            'consignor_id' => $consignor->id,
            'type' => LedgerType::SettlementPayment,
            'amount' => 200000,
            'reference' => 'PAY-1',
        ]);

        $this->actingAs($owner)
            ->get(route('master.penitip'))
            ->assertOk()
            ->assertSee('Rp300.000');
    }

    #[Test]
    public function it_offers_restore_instead_of_archive_for_archived_consignors(): void
    {
        $owner = User::factory()->owner()->create();
        $this->penitip('Penitip Aktif');
        $this->penitip('Penitip Arsip', ['status' => ConsignorStatus::Archived]);

        $this->actingAs($owner)
            ->get(route('master.penitip'))
            ->assertOk()
            ->assertSee('Aktifkan')
            ->assertSee('Arsip');
    }

    #[Test]
    public function it_ignores_an_unwhitelisted_sort_column(): void
    {
        $owner = User::factory()->owner()->create();
        $this->penitip('Budi');

        $this->actingAs($owner)
            ->get(route('master.penitip', ['sort' => 'wa_number', 'direction' => 'desc']))
            ->assertOk()
            ->assertSee('Budi');
    }

    #[Test]
    public function it_shows_an_empty_state_when_no_penitip_exists(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get(route('master.penitip'))
            ->assertOk()
            ->assertSee('Belum ada penitip')
            ->assertSee('Tambahkan penitip pertama');
    }

    #[Test]
    public function it_switches_the_empty_state_when_a_filter_is_active(): void
    {
        $owner = User::factory()->owner()->create();
        $this->penitip('Budi');

        $this->actingAs($owner)
            ->get(route('master.penitip', ['q' => 'tidak-ada']))
            ->assertOk()
            ->assertSee('Penitip tidak ditemukan');
    }
}
