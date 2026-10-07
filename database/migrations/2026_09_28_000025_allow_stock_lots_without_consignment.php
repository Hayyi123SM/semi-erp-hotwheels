<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock In Pribadi menaruh lot dengan owner_type = OWN (OW00) tanpa consignment.
 * Kolom consignment_id sebelumnya NOT NULL sehingga stok milik toko sendiri
 * mustahil disimpan. Kepemilikan kini ditentukan oleh owner_type/owner_code,
 * bukan oleh keberadaan baris consignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropForeign(['consignment_id']);
        });

        Schema::table('stock_lots', function (Blueprint $table) {
            $table->unsignedBigInteger('consignment_id')->nullable()->change();
        });

        Schema::table('stock_lots', function (Blueprint $table) {
            $table->foreign('consignment_id')->references('id')->on('consignments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropForeign(['consignment_id']);
        });

        Schema::table('stock_lots', function (Blueprint $table) {
            $table->unsignedBigInteger('consignment_id')->nullable(false)->change();
        });

        Schema::table('stock_lots', function (Blueprint $table) {
            $table->foreign('consignment_id')->references('id')->on('consignments')->restrictOnDelete();
        });
    }
};
