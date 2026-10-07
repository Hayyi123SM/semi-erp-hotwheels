<?php

use App\Services\Master\ConsignorWhatsappNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Menyamakan `consignors.wa_number` ke satu bentuk, untuk data yang sudah ada.
 *
 * Logikanya ada di `ConsignorWhatsappNormalizer`, dan migration ini hanya
 * memanggilnya. Alasannya migration tidak bisa diuji: `RefreshDatabase` sudah
 * menjalankannya sebelum baris uji ada, jadi `artisan migrate` di dalam test
 * tidak melakukan apa-apa dan tabrakan legacy hanya bisa diasumsikan benar.
 * Dengan logikanya di kelas yang bisa dipanggil, tabrakan itu bisa dibuktikan.
 *
 * Yang dinormalkan dan siapa yang menang tabrakan dijelaskan di sana. Ringkasnya:
 * yang punya `wa_opt_in_at` menang, dan baris yang kalah dikosongkan -- bukan
 * dihapus -- lalu dicatat di `laravel.log`.
 *
 * `down()` sengaja tidak mengubah apa-apa. Bentuk asli `+6281234567890` tidak
 * bisa direkonstruksi dari `6281234567890` -- tanda `+` dan spasinya memang
 * sudah hilang saat normalisasi, dan mengarangnya kembali berarti menulis data
 * yang tidak pernah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('consignors') || ! Schema::hasColumn('consignors', 'wa_number')) {
            return;
        }

        app(ConsignorWhatsappNormalizer::class)->run();
    }

    public function down(): void
    {
        // Sengaja kosong, lihat catatan di atas.
    }
};
