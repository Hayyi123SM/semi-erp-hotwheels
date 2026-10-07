<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Berapa kali label untuk satu lot dicetak ulang.
 *
 * Disimpan di lot, bukan dihitung ulang dari `label_print_jobs`, karena yang
 * dibutuhkan bukan "berapa job re-print yang pernah dibuat" tapi "berapa label
 * tambahan yang benar-benar keluar untuk lot ini". Kalau job-nya dibatalkan
 * sebelum dicetak, job re-print bertambah tapi label tidak keluar, dan angka di
 * lot tetap truthfully.
 *
 * Kolom ini juga dasar untuk anomali FR-IB-22: `reprint_count` yang melonjak
 * pada satu lot tanpa penjelasan adalah salah satu bentuk "stiker liar" yang
 * tercatat rapi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->unsignedInteger('reprint_count')->default(0)->after('labels_printed');

            $table->index(['reprint_count'], 'stock_lots_reprint_count_index');
        });
    }

    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table) {
            $table->dropIndex('stock_lots_reprint_count_index');
            $table->dropColumn('reprint_count');
        });
    }
};
