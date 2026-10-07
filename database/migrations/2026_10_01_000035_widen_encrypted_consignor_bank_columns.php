<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memperbesar tiga kolom rekening penitip yang isinya disimpan terenkripsi.
 *
 * Alasannya panjang, bukan selera: `encrypted` di Laravel tidak hanya
 * menyandikan teks, ia juga membungkusnya dalam JSON dan memberinya tag
 * integritas. Untuk `AES-256-CBC` dan kunci 32 byte, plaintext 23 karakter
 * sudah jadi 228 byte, dan 24 karakter melompat ke 256 byte -- melewati
 * `varchar(255)`.
 *
 * Kolomnya tadinya `string`, jadi MySQL menolak dengan "Data too long" dan
 * lalu memotong sisanya. SQLite tidak menegakkan batas `varchar`, jadi di
 * suite harian test ini hijau sementara produksi menolak menyimpan nama
 * penitip yang sedikit lebih panjang. ITulah yang membiarkan bug ini
 * bertahan: database yang dipakai test tidak bisa melihatnya.
 *
 * Tiga kolom jadi `text` karena isinya ciphertext, bukan teks yang dibaca
 * manusia. Perluasan ke `text` tidak merusak data yang sudah ada: semua
 * nilai yang ada lebih pendek dari 255 byte, dan `text` menampingnya tanpa
 * perubahan apa pun.
 *
 * Enkripsi tidak diubah di sini. Kalau someday kuncinya panjangnya berbeda,
 * ciphertext-nya juga akan berbeda -- tapi panjangnya selalu dibatasi oleh
 * plaintext, bukan oleh kunci, jadi `text` tetap cukup untuk semua kunci
 * yang dipakai aplikasi ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consignors', function (Blueprint $table): void {
            $table->text('bank_name')->nullable()->change();
            $table->text('bank_account')->nullable()->change();
            $table->text('bank_holder')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('consignors', function (Blueprint $table): void {
            // Dikembalikan ke `string` hanya kalau isinya muat. Nilai yang
            // sudah terenkripsi hampir pasti lebih panjang dari 255 byte, jadi
            // `down()` di sini bisa gagal -- dan itu memang lebih jujur
            // daripada memotong ciphertext menjadi tidak bisa didekripsi.
            $table->string('bank_name')->nullable()->change();
            $table->string('bank_account')->nullable()->change();
            $table->string('bank_holder')->nullable()->change();
        });
    }
};
