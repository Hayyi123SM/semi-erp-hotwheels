<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            /**
             * Idempotensi, bukan identitas baris. SRS 6.5: "idempotensi dengan
             * `notification_key` (mis. `consignment_receipt:{consignment_id}`)".
             *
             * Karena itu indeks uniknya ada di kolom ini dan bukan di
             * `consignment_id`: satu dokumen memang hanya boleh punya satu e-receipt,
             * tapi kolom ini yang masih bisa dipakai ulang nanti untuk template
             * lain tanpa mengubah tabel. Klik "Kirim" dua kali karena operator
             * tidak yakin tombolnya masuk menghasilkan dua nota, bukan satu.
             */
            $table->string('notification_key', 80)->unique();

            $table->string('channel', 20)->default('WHATSAPP');
            $table->string('template_name', 40);

            // Bentuk yang disimpan, selalu `62` diikuti digit. Bentuk yang
            // dibaca orang ada di `WhatsappNumber::display()`, dan bentuk untuk
            // `wa.me` di-build ulang dari kolom ini.
            $table->string('recipient', 20);

            /**
             * Salinan isi pesan yang benar-benar diserahkan, bukan template yang
             * dipakai saat itu saja.
             *
             * Tanpa kolom ini, begitu template diubah di Pengaturan, isi yang
             * sebenarnya diterima penitip tidak ada lagi di mana pun. Yang
             * tersisa cuma template yang sekarang, dan itu bukan yang dikirim --
             * sehingga pertanyaan "kok angkanya beda?" tidak bisa dijawab dari
             * sistem.
             */
            $table->text('body')->nullable();

            /**
             * Alasan kegagalan, dalam kalimat yang bisa dibaca Staff.
             *
             * Dipisah dari `status` karena `FAILED` tidak menjawab sendiri
             * pertanyaan "gagal kenapa?". Tanpa jawabannya, notifikasi yang gagal
             * terlihat sama persis dengan yang masih menunggu dicoba.
             */
            $table->string('error_message')->nullable();

            $table->string('status', 20)->default('PENDING');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();

            $table->foreignId('consignment_id')->nullable()->constrained('consignments')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });

        /**
         * Antrean ambil yang sudah jatuh tempo, jadi pencarian selalu memakai
         * `status` dan `next_attempt_at`. Tanpa indeks, tiap retry memindai
         * seluruh tabel notifikasi yang tidak pernah dihapus.
         */
        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['status', 'next_attempt_at'], 'notifications_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
