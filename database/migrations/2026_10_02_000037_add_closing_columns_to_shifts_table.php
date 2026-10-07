<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nama indeks ditulis lengkap, bukan dibiarkan otomatis.
     *
     * `dropIndex('closed_at')` berarti "drop indeks yang namanya `closed_at`",
     * bukan "drop indeks di kolom `closed_at`", dan SQL-nya jadi `drop index
     * closed_at` yang ditolak MySQL karena tidak ada indeks bernama begitu.
     * `up()` dan `down()` harus menyebut nama yang sama persis, jadi nama indeks
     * ada di kedua tempat, ditulis lengkap di dua tempat.
     */
    private const STATUS_USER_INDEX = 'shifts_status_user_id_index';

    private const STATUS_DEVICE_INDEX = 'shifts_status_device_id_index';

    private const CLOSED_AT_INDEX = 'shifts_closed_at_index';

    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('closed_by')->nullable()->after('cash_diff')->constrained('users')->nullOnDelete();
            $table->foreignId('cash_diff_approved_by')->nullable()->after('closed_by')->constrained('users')->nullOnDelete();
        });

        // Setiap pembaca "shift ini sudah dibuka belum" mengambil baris berdasarkan
        // status dan identitas, dan `status` hanya membedakan OPEN dari CLOSED.
        // Tanpa indeks, pertanyaan itu berubah jadi pindai seluruh tabel -- dan
        // tabel itu yang paling cepat tumbuh di aplikasi ini, satu baris per kasir
        // per hari.
        Schema::table('shifts', function (Blueprint $table) {
            $table->index(['status', 'user_id'], self::STATUS_USER_INDEX);
            $table->index(['status', 'device_id'], self::STATUS_DEVICE_INDEX);
            $table->index('closed_at', self::CLOSED_AT_INDEX);
        });
    }

    /**
     * Satu `drop index` per blok.
     *
     * MySQL pada host ini tidak menerapkan klausa `alter table` secara atomik:
     * klausa yang sudah dieksekusi tetap berlaku walaupun klausa berikutnya
     * ditolak. Kalau ketiganya ditumpuk dalam satu `alter table`, rollback yang
     * gagal di tengah meninggalkan indeks yang sebagian sudah hilang sementara
     * baris migrasinya masih tercatat sudah dijalankan -- keadaan yang tidak
     * bisa diperbaiki dengan rollback berikutnya.
     */
    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex(self::STATUS_USER_INDEX);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex(self::STATUS_DEVICE_INDEX);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex(self::CLOSED_AT_INDEX);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_diff_approved_by');
        });
    }
};
