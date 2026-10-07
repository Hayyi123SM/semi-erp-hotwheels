<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enam kolom yang menopang aturan inti siklus opname:
     *
     * - `movement_from_id` adalah tanda air `stock_movements.id` pada saat sesi
     *   dimulai (t0). FR-IC-20 minta snapshot qty sistem pada t0 dan FR-IC-22
     *   minta selisih dihitung dari "qty sistem pada t0 ± movement sejak t0",
     *   jadi sesi memerlukan batas yang tidak ambigu antara gerakan sebelum dan
     *   sesudah sesi. Id dipakai, bukan timestamp: `stock_movements.created_at`
     *   hanya sedetik, dan dua gerakan yang jatuh pada detik yang sama dengan
     *   `started_at` akan salah masuk hitungan dengan sekat berbasis waktu.
     * - `scope_value` menyimpan cakupan SKU; cakupan lain memakai `rack_id`
     *   atau tidak butuh nilai apa pun.
     * - `submitted_at` memisahkan "sesi berjalan" dari "sesi menunggu
     *   persetujuan", supaya `closed_at` tetap berarti saat sesi benar-benar
     *   selesai di tangan Owner.
     *
     * Di sisi baris:
     *
     * - `counted_at` + `counted_movement_id` adalah tanda air pada saat SATU
     *   baris dihitung. Stok yang bergerak setelah angka itu dimasukkan membuat
     *   hitungannya basi, dan sesi tidak boleh diajukan dengan angka basi --
     *   inilah yang mendeteksinya tanpa menebak-nebak dari timestamp.
     * - `counted_by` mencatat siapa yang menghitung. Audit log tidak mencatat
     *   setiap baris (ratusan baris per sesi hanya untuk satu kejadian yang
     *   sama), jadi jejaknya disimpan di barisnya sendiri.
     */
    public function up(): void
    {
        Schema::table('opnames', function (Blueprint $table) {
            $table->string('scope_value', 30)->nullable()->after('rack_id');
            $table->unsignedBigInteger('movement_from_id')->nullable()->after('started_at');
            $table->timestamp('submitted_at')->nullable()->after('movement_from_id');
        });

        Schema::table('opname_lines', function (Blueprint $table) {
            $table->timestamp('counted_at')->nullable()->after('diff_qty');
            $table->unsignedBigInteger('counted_movement_id')->nullable()->after('counted_at');
            $table->foreignId('counted_by')->nullable()->after('counted_movement_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('opname_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('counted_by');
            $table->dropColumn(['counted_movement_id', 'counted_at']);
        });

        Schema::table('opnames', function (Blueprint $table) {
            $table->dropColumn(['submitted_at', 'movement_from_id', 'scope_value']);
        });
    }
};
