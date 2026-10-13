<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quarantine_sequences', function (Blueprint $table) {
            // Satu baris per hari, sama dengan `receipt_sequences`: nomor kasus
            // (QC-20261010-001) direset tiap hari, dan kunci hari inilah yang
            // membuat reset itu tanpa menghapus apa pun.
            $table->string('day', 8)->primary();
            $table->unsignedInteger('last_seq')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quarantine_sequences');
    }
};
