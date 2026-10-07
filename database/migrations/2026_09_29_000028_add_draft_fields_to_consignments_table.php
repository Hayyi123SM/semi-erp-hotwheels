<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-IB-14: commit harus atomik dan boleh di-retry setelah koneksi putus tanpa
 * menggandakan data. `idempotency_key` disimpan di baris yang sama dengan dokumen,
 * jadi retry dengan kunci yang sama ketemu dokumen yang sama dan cukup dialihkan
 * ke hasil yang lama -- bukan membuat dokumen kedua.
 *
 * `draft_id` (UUID, sesuai langkah 1) adalah identitas yang dipakai Staff untuk
 * melanjutkan draft, bukan `id` numerik: id internal tidak pernah ditampilkan, dan
 * UUID tidak menebak-nebak berarti draft milik orang lain tidak bisa di antiduga
 * dari URL.
 *
 * `saved_at` hanya indikator auto-save. Deliberate tidak memakai
 * `updated_at`: draft yang baru dibuat akan punya `updated_at` >= `saved_at`
 * pada baris yang sama, sehingga keduanya tidak bisa dibedakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignments', function (Blueprint $table) {
            $table->uuid('draft_id')->nullable()->unique()->after('id');
            $table->timestamp('saved_at')->nullable()->after('variance_note');
            $table->string('idempotency_key', 64)->nullable()->unique()->after('saved_at');

            $table->index(['status', 'created_by'], 'consignments_draft_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('consignments', function (Blueprint $table) {
            $table->dropIndex('consignments_draft_lookup_index');
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['draft_id', 'saved_at', 'idempotency_key']);
        });
    }
};
