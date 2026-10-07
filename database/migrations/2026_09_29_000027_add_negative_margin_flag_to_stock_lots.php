<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-06 meminta setiap lot yang fee toko-nya melebihi harga jual ditandai, bukan
 * diam-diam disimpan.
 *
 * Di inbound kolom ini belum akan pernah bernilai `true`: diskon baru ada di POS,
 * jadi di sini `D = 0` dan `P = L`, dan rentang skema (`0 < nett < harga`,
 * `0 < flat < harga`) sudah menolak kasusnya lebih dulu. Kolomnya tetap sekarang
 * supaya `stock_lots` dan `sale_items` punya bentuk yang sama, dan supaya tidak
 * ada migrasi yang menyentuh tabel yang sudah berisi data produksi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->boolean('negative_margin_flag')->default(false)->after('terms_version');
        });
    }

    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropColumn('negative_margin_flag');
        });
    }
};
