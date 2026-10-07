<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom verifikasi fisik untuk RTV (FR-IC-32).
     *
     * `rtv_lines` dari awal hanya menyimpan qty yang diminta. Verifikasi scan
     * butuh angka kedua: berapa unit yang benar-benar sudah dipindai orang ke
     * rak staging. Tanpa kolom ini hitungannya numpang lewat di session atau di
     * ingatan peramban, dan "jumlah fisik = qty RTV" tidak pernah benar-benar
     * diperiksa sebelum barang keluar gudang.
     *
     * `verified_at`/`verified_by` menempel pada baris, bukan pada dokumen:
     * dua SKU dalam satu sesi bisa saja selesai dipindai pada menit yang
     * berbeda oleh dua orang yang berbeda, dan pertanyaan "siapa yang
     * memindai, kapan" dijawab per SKU.
     */
    public function up(): void
    {
        Schema::table('rtv_lines', function (Blueprint $table) {
            $table->unsignedInteger('verified_qty')->default(0)->after('qty');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rtv_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['verified_qty', 'verified_at']);
        });
    }
};
