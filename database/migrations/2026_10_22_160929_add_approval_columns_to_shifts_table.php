<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom untuk tutup shift yang menunggu keputusan Owner.
     *
     * `closing_notes` dipisah dari `notes` supaya catatan pembukaan tidak
     * tertimpa catatan penutupan: keduanya menjawab pertanyaan yang berbeda
     * ("kenapa modalnya tidak biasa" dan "kenapa selisihnya begini"), dan satu
     * kolom hanya bisa menyimpan salah satunya.
     *
     * `closing_rejection_reason` disimpan di baris shift, bukan hanya di audit
     * log, karena kasir yang shiftnya ditolak harus membaca alasannya di halaman
     * yang sama saat ia menghitung ulang -- bukan menggali laporan audit.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->text('closing_notes')->nullable()->after('notes');
            $table->timestamp('closing_requested_at')->nullable()->after('closing_notes');
            $table->timestamp('closing_approved_at')->nullable()->after('closing_requested_at');
            $table->string('closing_rejection_reason', 500)->nullable()->after('closing_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn([
                'closing_notes',
                'closing_requested_at',
                'closing_approved_at',
                'closing_rejection_reason',
            ]);
        });
    }
};
