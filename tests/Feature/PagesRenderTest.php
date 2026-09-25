<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PagesRenderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('appRoutes')]
    public function page_renders_with_layout(string $route): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get($route)
            ->assertOk();

        $response->assertSee('<!DOCTYPE html>', false);
        $response->assertSee('/build/assets/', false);
    }

    public static function appRoutes(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'master.penitip' => ['/master/penitip'],
            'master.katalog-produk' => ['/master/katalog-produk'],
            'master.lokasi-rak' => ['/master/lokasi-rak'],
            'inbound.stock-in-pribadi' => ['/inbound/stock-in-pribadi'],
            'inbound.consignment-in' => ['/inbound/consignment-in'],
            'inbound.cetak-label' => ['/inbound/cetak-label'],
            'inventory.live-stock' => ['/inventory/live-stock'],
            'inventory.karantina' => ['/inventory/karantina'],
            'inventory.stok-opname' => ['/inventory/stok-opname'],
            'inventory.retur-rtv' => ['/inventory/retur-rtv'],
            'pos.kasir' => ['/pos/kasir'],
            'pos.riwayat' => ['/pos/riwayat-transaksi'],
            'pos.shift-kasir' => ['/pos/shift-kasir'],
            'report.settlement' => ['/reports/consignor-settlement'],
            'report.margin' => ['/reports/profit-margin'],
            'report.laporan' => ['/reports/laporan-penjualan-stok'],
            'report.audit-log' => ['/reports/audit-log'],
            'setting.pengguna' => ['/settings/pengguna-role'],
            'setting.perangkat' => ['/settings/perangkat'],
            'setting.wa-template' => ['/settings/wa-template'],
            'setting.parameter' => ['/settings/parameter'],
        ];
    }
}