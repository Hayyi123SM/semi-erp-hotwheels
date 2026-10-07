<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memisahkan "primary key numerik" dari "kunci string" di `audit_logs`.
 *
 * Satu tabel log hampir selalu menyimpan dua jenis identitas. `StockLot`, `User`,
 * dan `LabelPrintJob` punya primary key bilangan bulat. `Setting` tidak punya
 * primary key sama sekali -- identitasnya adalah kunci teksnya sendiri, seperti
 * `label.printer` atau `wa.template.consignment_receipt`.
 *
 * Schema sebelumnya hanya menyediakan satu kolom, `entity_id`, bertipe
 * `unsignedBigInteger`. Jadi identitas string tidak punya tempat. Dua jalan
 * keluar tersedia dan keduanya buruk:
 *
 * - Mengirim string ke `entity_id`. MySQL dengan `STRICT_TRANS_TABLES`
 *   menolak dengan "Incorrect integer value", jadi halaman pengaturannya 500.
 * - Melewatkan identitasnya. Lognya jadi tidak bisa menunjukkan setting mana
 *   yang berubah, padahal "template aktif waktu itu" justru hal pertama yang
 *   dicari saat isi template terbukti salah.
 *
 * Bug itu tidak pernah terlihat di suite harian karena SQLite tidak menegakkan
 * tipe kolom: string slip ke `INTEGER` tanpa error, sementara produksi
 * menolaknya.
 *
 * Kolomnya nullable dan ditambahkan, bukan diubah. Baris yang sudah ada tidak
 * punya nilai untuk `entity_key`, dan memang tidak membutuhkannya: primary
 * key-nya sudah tercatat di `entity_id`. Sekarang identitas string punya
 * tempatnya, dan `entity_id` kembali ke satu arti saja.
 *
 * Panjang 60 mengikuti kolom `entity` di sebelahnya, bukan kunci Setting
 * yang terpanjang saat ini (31 karakter). Sengaja longgar: menambah entitas
 * baru tidak seharusnya butuh migration baru hanya karena nama kuncinya
 * sedikit lebih panjang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('entity_key', 60)->nullable()->after('entity_id');
        });
    }

    public function down(): void
    {
        /**
         * Hanya kolomnya yang dibuang. Baris audit yang sudah tertulis tetap
         * dibiarkan: menghapus jejak siapa yang mengubah apa adalah keputusan
         * yang tidak boleh diambil diam-diam oleh `migrate:rollback`, dan nilai
         * yang hilang (`entity_key`) metadata, bukan isi bisnis.
         */
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropColumn('entity_key');
        });
    }
};
