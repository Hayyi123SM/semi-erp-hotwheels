<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laporan yang dibangun di Phase A membaca rentang tanggal pada tiga kolom yang
 * belum punya index sama sekali: `sales.sold_at` (laporan penjualan & margin),
 * `sale_payments.created_at` (rekap metode bayar), dan `consignor_ledger.created_at`
 * (saldo & aging penitip). Tiga tabel ini hanya tumbuh -- tidak pernah menyusut --
 * jadi tanpa index setiap buka laporan akan memindai seluruh riwayat sejak toko
 * pertama kali buka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->index('sold_at', 'sales_sold_at_index');
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->index('created_at', 'sale_payments_created_at_index');
        });

        Schema::table('consignor_ledger', function (Blueprint $table) {
            $table->index('created_at', 'consignor_ledger_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_sold_at_index');
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropIndex('sale_payments_created_at_index');
        });

        Schema::table('consignor_ledger', function (Blueprint $table) {
            $table->dropIndex('consignor_ledger_created_at_index');
        });
    }
};
