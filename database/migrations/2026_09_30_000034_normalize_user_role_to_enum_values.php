<?php

use App\Services\User\UserRoleNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyamakan `users.role` ke ejaan yang bisa dibaca `App\Enums\Role`.
 *
 * Logikanya ada di `UserRoleNormalizer`, dan migration ini hanya memanggilnya
 * lalu memperbaiki default kolomnya. Alasannya migration tidak bisa diuji:
 * `RefreshDatabase` sudah menjalankannya sebelum baris uji ada, jadi `artisan
 * migrate` di dalam test tidak melakukan apa-apa.
 *
 * Default kolom diperbaiki bersama, karena nilai yang sama juga menjadi default
 * setiap insert yang tidak menyebut role: seed, import, dan tinker. Memperbaiki
 * form pendaftaran saja akan menutup satu jalan masuk, bukan semuanya.
 *
 * `down()` mengembalikan default kolomnya saja. Barisnya sengaja tidak dikembalikan
 * ke huruf kecil, karena ejaan itu tidak bisa di-cast: mengembalikannya akan
 * memutus setiap akun selama rollback masih terpasang, dan tidak ada yang bisa
 * memperbaikinya dari situ.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(UserRoleNormalizer::class)->run();

        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default('STAFF')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default('staff')->change();
        });
    }
};
