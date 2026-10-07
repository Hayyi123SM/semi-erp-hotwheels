<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timestamp untuk tiap tahap persetujuan label.
 *
 * `printed_at` saja tidak cukup untuk mencari label yang menggantung: satu
 * job bisa berstatus SENT sejak lama dan tidak pernah dikonfirmasi karena
 * operator tidak pernah melihat keluarannya. Tanpa `confirmed_at`, tidak ada
 * yang bisa membedakan "baru saja dikirim" dari "sudah tiga hari menggantung".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('label_print_jobs', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('printed_at');
            $table->timestamp('failed_at')->nullable()->after('error_message');

            // Mempercepat pencarian "job SENT yang menggantung" untuk warning
            // "Belum berlabel" di dashboard.
            $table->index(['status', 'printed_at'], 'label_print_jobs_pending_index');
        });
    }

    public function down(): void
    {
        Schema::table('label_print_jobs', function (Blueprint $table) {
            $table->dropIndex('label_print_jobs_pending_index');
            $table->dropColumn(['confirmed_at', 'failed_at']);
        });
    }
};
