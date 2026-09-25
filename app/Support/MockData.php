<?php

namespace App\Support;

use Carbon\Carbon;

class MockData
{
    public static function rupiah(int $amount): string
    {
        return 'Rp' . number_format($amount, 0, ',', '.');
    }

    public static function date(string $datetime): string
    {
        return Carbon::parse($datetime)->format('d M Y H:i').' WIB';
    }

    public static function consignors(): array
    {
        return [
            [
                'code' => 'CN01',
                'name' => 'Budi Santoso',
                'phone' => '+62 812-3456-7890',
                'scheme' => 'percent',
                'fee' => 20,
                'balance' => 1840000,
                'active' => true,
                'since' => '12 Jan 2024',
            ],
            [
                'code' => 'CN02',
                'name' => 'Rina Wijaya',
                'phone' => '+62 813-9988-7711',
                'scheme' => 'nett',
                'fee' => 65000,
                'balance' => 672000,
                'active' => true,
                'since' => '03 Mar 2024',
            ],
            [
                'code' => 'CN03',
                'name' => 'Agus Kurniawan',
                'phone' => '+62 857-1122-3344',
                'scheme' => 'flat',
                'fee' => 8000,
                'balance' => 0,
                'active' => false,
                'since' => '28 Jun 2024',
            ],
        ];
    }

    public static function racks(): array
    {
        return [
            ['code' => 'A-01-03', 'zone' => 'A', 'type' => 'DISPLAY', 'items' => 18, 'capacity' => 24, 'usage' => 75, 'active' => true],
            ['code' => 'A-02-01', 'zone' => 'A', 'type' => 'DISPLAY', 'items' => 22, 'capacity' => 24, 'usage' => 92, 'active' => true],
            ['code' => 'B-01-05', 'zone' => 'B', 'type' => 'STORAGE', 'items' => 40, 'capacity' => 48, 'usage' => 83, 'active' => true],
            ['code' => 'Q-00-01', 'zone' => 'Q', 'type' => 'QUARANTINE', 'items' => 7, 'capacity' => 12, 'usage' => 58, 'active' => true],
            ['code' => 'R-00-02', 'zone' => 'R', 'type' => 'RTV_STAGING', 'items' => 3, 'capacity' => 10, 'usage' => 30, 'active' => true],
            ['code' => 'C-03-08', 'zone' => 'C', 'type' => 'STORAGE', 'items' => 51, 'capacity' => 60, 'usage' => 85, 'active' => false],
        ];
    }

    public static function catalog(): array
    {
        return [
            ['sku' => 'OW00-HW-001', 'model' => '97 Mazda RX-7', 'series' => 'Car Culture', 'year' => 2024, 'color' => 'Silver', 'condition' => 'Mint', 'category' => 'PRIBADI', 'rack' => 'A-01-03', 'price' => 145000],
            ['sku' => 'OW00-HW-002', 'model' => 'Porsche 911 GT3 RS', 'series' => 'Porsche', 'year' => 2024, 'color' => 'GT Silver', 'condition' => 'Mint', 'category' => 'PRIBADI', 'rack' => 'A-01-03', 'price' => 165000],
            ['sku' => 'OW00-HW-003', 'model' => 'Honda Civic Type R', 'series' => 'Honda', 'year' => 2023, 'color' => 'Championship White', 'condition' => 'Clear', 'category' => 'PRIBADI', 'rack' => 'A-02-01', 'price' => 95000],
            ['sku' => 'CN01-HW-001', 'model' => 'Nissan Skyline GT-R R34', 'series' => 'Fast & Furious', 'year' => 2024, 'color' => 'Bayside Blue', 'condition' => 'Mint', 'category' => 'TITIP', 'rack' => 'A-01-03', 'price' => 220000],
            ['sku' => 'CN01-HW-002', 'model' => 'Toyota Supra MK4', 'series' => 'Fast & Furious', 'year' => 2024, 'color' => 'Pearl Orange', 'condition' => 'Mint', 'category' => 'TITIP', 'rack' => 'B-01-05', 'price' => 185000],
            ['sku' => 'CN02-HW-001', 'model' => 'Lamborghini Huracan', 'series' => 'Car Culture', 'year' => 2023, 'color' => 'Verde Mantis', 'condition' => 'Clear', 'category' => 'TITIP', 'rack' => 'B-01-05', 'price' => 175000],
            ['sku' => 'CN02-HW-002', 'model' => 'Ford Mustang GT', 'series' => 'American Scene', 'year' => 2022, 'color' => 'Oxford White', 'condition' => 'Damaged', 'category' => 'TITIP', 'rack' => 'Q-00-01', 'price' => 120000],
            ['sku' => 'OW00-HW-004', 'model' => 'Mazda 787B', 'series' => 'Car Culture', 'year' => 2022, 'color' => 'Renown Orange', 'condition' => 'Mint', 'category' => 'PRIBADI', 'rack' => 'A-02-01', 'price' => 135000],
        ];
    }

    public static function quarantineCases(): array
    {
        return [
            ['caseId' => 'Q-2026-014', 'arrivedAt' => '18 Sep 2026 10:12', 'age' => 6, 'skuFallback' => 'CN02-HW-002', 'model' => 'Ford Mustang GT', 'reason' => 'BARCODE TIDAK TERBACA', 'severity' => 'warning', 'candidate' => 'Ford Mustang GT · American Scene · 93%'],
            ['caseId' => 'Q-2026-017', 'arrivedAt' => '21 Sep 2026 14:40', 'age' => 3, 'skuFallback' => 'OW00-HW-00?', 'model' => 'Tidak teridentifikasi', 'reason' => 'PRODUK TANPA LABEL', 'severity' => 'error', 'candidate' => 'Mazda 787B · Car Culture · 71%'],
            ['caseId' => 'Q-2026-011', 'arrivedAt' => '12 Sep 2026 09:05', 'age' => 12, 'skuFallback' => 'CN01-SC-907', 'model' => 'Fast & Furious 5-pack', 'reason' => 'STOK PABRIK (DOS PACK)', 'severity' => 'error', 'candidate' => 'Nissan Skyline GT-R R34 · 45%'],
        ];
    }

    public static function transactions(): array
    {
        return [
            ['nota' => 'POS-CP2-2026-08913', 'time' => '22 Sep 2026 19:42', 'casher' => 'Dewi Lestari', 'shift' => 'Reguler Shift 2', 'items' => 3, 'total' => 505000, 'method' => 'TUNAI', 'status' => 'LUNAS'],
            ['nota' => 'POS-CP2-2026-08912', 'time' => '22 Sep 2026 18:17', 'casher' => 'Dewi Lestari', 'shift' => 'Reguler Shift 2', 'items' => 1, 'total' => 220000, 'method' => 'QRIS', 'status' => 'REFUND'],
            ['nota' => 'POS-CP2-2026-08911', 'time' => '22 Sep 2026 16:03', 'casher' => 'Ahmad Fauzi', 'shift' => 'Reguler Shift 1', 'items' => 2, 'total' => 335000, 'method' => 'KARTU', 'status' => 'LUNAS'],
            ['nota' => 'POS-CP2-2026-08910', 'time' => '22 Sep 2026 14:55', 'casher' => 'Ahmad Fauzi', 'shift' => 'Reguler Shift 1', 'items' => 5, 'total' => 780000, 'method' => 'TUNAI', 'status' => 'LUNAS'],
            ['nota' => 'POS-CP2-2026-08909', 'time' => '22 Sep 2026 11:30', 'casher' => 'Ahmad Fauzi', 'shift' => 'Reguler Shift 1', 'items' => 1, 'total' => 145000, 'method' => 'TUNAI', 'status' => 'VOID'],
            ['nota' => 'POS-CP2-2026-08908', 'time' => '21 Sep 2026 20:14', 'casher' => 'Dewi Lestari', 'shift' => 'Reguler Shift 2', 'items' => 4, 'total' => 640000, 'method' => 'QRIS', 'status' => 'LUNAS'],
            ['nota' => 'POS-CP2-2026-08907', 'time' => '21 Sep 2026 17:28', 'casher' => 'Dewi Lestari', 'shift' => 'Reguler Shift 2', 'items' => 2, 'total' => 310000, 'method' => 'TUNAI', 'status' => 'LUNAS'],
        ];
    }

    public static function activeBankItems(): array
    {
        return [
            ['sku' => 'CN01-HW-001', 'model' => 'Nissan Skyline GT-R R34', 'condition' => 'Mint', 'qty' => 1, 'price' => 220000, 'owner' => 'CN01'],
            ['sku' => 'OW00-HW-001', 'model' => '97 Mazda RX-7', 'condition' => 'Mint', 'qty' => 1, 'price' => 145000, 'owner' => 'OW00'],
            ['sku' => 'CN01-HW-002', 'model' => 'Toyota Supra MK4', 'condition' => 'Mint', 'qty' => 1, 'price' => 185000, 'owner' => 'CN01'],
        ];
    }

    public static function recentActivities(): array
    {
        return [
            ['time' => '14:02 WIB', 'text' => 'Penitipan masuk 8 unit (CN01-Budi Santoso) · SKU CN01-HW-004 → s/d 007', 'type' => 'inbound'],
            ['time' => '13:47 WIB', 'text' => 'Kasus karantina Q-2026-017 dipindahkan ke rak Q-00-01', 'type' => 'quarantine'],
            ['time' => '13:26 WIB', 'text' => 'Transaksi POS-CP2-2026-08913 selesai · Rp505.000 tunai', 'type' => 'sale'],
            ['time' => '12:58 WIB', 'text' => 'Settlement CN01-Budi Santoso dibuka · 12 SKU · Estimasi Rp1.840.000', 'type' => 'settlement'],
            ['time' => '12:41 WIB', 'text' => 'Printer label WMS-01 dites · 3×2cm OK (203 DPI)', 'type' => 'print'],
            ['time' => '11:30 WIB', 'text' => 'Stok opname dimulai oleh Ahmad Fauzi · Progress 342/450 rak', 'type' => 'opname'],
        ];
    }
}