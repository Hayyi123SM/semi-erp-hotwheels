<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-IB-20 dan FR-IB-22 butuh empat hal yang tabel antrean label belum punya.
 *
 * 1. `payload` — cuplikan isi label pada saat job dibuat. Tanpa ini, histori
 *    cetak menulis ulang dirinya sendiri begitu produk diubah namanya atau
 *    harganya: label yang tertempel di rak dan catatan sistem berhenti sepakat,
 *    dan tidak ada cara membuktikan apa yang sebenarnya tercetak. Snapshot
 *    disimpan sebagai JSON, bukan dihitung ulang saat render, justru karena itu
 *    tujuannya.
 *
 * 2. `device_id` — printer mana yang mengerjakan job. Nomor printer tidak
 *    direferensikan ke tabel terpisah: dengan pencetakan lewat browser, yang
 *    mencetak adalah peramban itu sendiri, jadi kolom ini bebas teks dan
 *    tetap berguna sebagai jejak siapa yang mencetaknya.
 *
 * 3. `error_message` — alasan kenapa job masuk FAILED. Tanpa ini, "coba lagi"
 *    hanya mencoba hal yang sama sekali belum diketahui gagal apa.
 *
 * 4. `rendered_at` — kapan payload dirakit. Bedanya dengan `created_at`: job
 *    bisa menunggu berhari-hari sebelum benar-benar dicetak, dan isi label bisa
 *    saja berubah di antara keduanya.
 *
 * Index `status` ditambahkan karena antrean selalu difilter status dan
 * diurutkan sehingga QUEUED lebih dulu; tanpa itu setiap halaman antrean
 * melakukan pemindaian penuh tabel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('label_print_jobs', function (Blueprint $table) {
            $table->json('payload')->nullable()->after('template');
            $table->string('device_id', 60)->nullable()->after('template');
            $table->text('error_message')->nullable()->after('status');
            $table->timestamp('rendered_at')->nullable()->after('printed_at');

            $table->index('status', 'label_print_jobs_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('label_print_jobs', function (Blueprint $table) {
            $table->dropIndex('label_print_jobs_status_index');

            $table->dropColumn(['payload', 'device_id', 'error_message', 'rendered_at']);
        });
    }
};
