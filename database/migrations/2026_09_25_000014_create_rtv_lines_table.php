<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rtv_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rtv_id')->constrained('rtv_notes')->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('stock_lots')->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rtv_lines');
    }
};
