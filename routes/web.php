<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Pages\InboundController;
use App\Http\Controllers\Pages\InventoryController;
use App\Http\Controllers\Pages\MasterController;
use App\Http\Controllers\Pages\PosController;
use App\Http\Controllers\Pages\ReportController;
use App\Http\Controllers\Pages\SettingController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // ===== 1. Master Data =====
    Route::get('/master/penitip', [MasterController::class, 'penitip'])->name('master.penitip');
    Route::get('/master/katalog-produk', [MasterController::class, 'katalogProduk'])->name('master.katalog-produk');
    Route::get('/master/lokasi-rak', [MasterController::class, 'lokasiRak'])->name('master.lokasi-rak');

    // ===== 2. Inbound =====
    Route::get('/inbound/stock-in-pribadi', [InboundController::class, 'stockInPribadi'])->name('inbound.stock-in-pribadi');
    Route::get('/inbound/consignment-in', [InboundController::class, 'consignmentIn'])->name('inbound.consignment-in');
    Route::get('/inbound/cetak-label', [InboundController::class, 'cetakLabel'])->name('inbound.cetak-label');

    // ===== 3. Inventory =====
    Route::get('/inventory/live-stock', [InventoryController::class, 'liveStock'])->name('inventory.live-stock');
    Route::get('/inventory/karantina', [InventoryController::class, 'karantina'])->name('inventory.karantina');
    Route::get('/inventory/stok-opname', [InventoryController::class, 'stockOpname'])->name('inventory.stok-opname');
    Route::get('/inventory/retur-rtv', [InventoryController::class, 'returRtv'])->name('inventory.retur-rtv');

    // ===== 4. POS / Kasir =====
    Route::get('/pos/kasir', [PosController::class, 'kasir'])->name('pos.kasir');
    Route::get('/pos/riwayat-transaksi', [PosController::class, 'riwayat'])->name('pos.riwayat');
    Route::get('/pos/shift-kasir', [PosController::class, 'shiftKasir'])->name('pos.shift-kasir');

    // ===== 5. Reports & Analisis =====
    Route::get('/reports/consignor-settlement', [ReportController::class, 'settlement'])->name('report.settlement');
    Route::get('/reports/profit-margin', [ReportController::class, 'margin'])->name('report.margin');
    Route::get('/reports/laporan-penjualan-stok', [ReportController::class, 'laporan'])->name('report.laporan');
    Route::get('/reports/audit-log', [ReportController::class, 'auditLog'])->name('report.audit-log');

    // ===== 6. Pengaturan =====
    Route::get('/settings/pengguna-role', [SettingController::class, 'pengguna'])->name('setting.pengguna');
    Route::get('/settings/perangkat', [SettingController::class, 'perangkat'])->name('setting.perangkat');
    Route::get('/settings/wa-template', [SettingController::class, 'waTemplate'])->name('setting.wa-template');
    Route::get('/settings/parameter', [SettingController::class, 'parameter'])->name('setting.parameter');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';