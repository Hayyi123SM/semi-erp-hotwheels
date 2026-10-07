<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_sequences', function (Blueprint $table) {
            // Satu baris per hari, bukan satu baris global. Nomor struk memang
            // direset tiap hari (HW-20261006-0001), dan kunci hari inilah yang
            // membuat reset itu tanpa perlu menghapus apa pun: besok baris baru
            // dibuat mulai dari nol, hari ini tetap monoton naik.
            $table->string('day', 8)->primary();
            $table->unsignedInteger('last_seq')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_sequences');
    }
};
