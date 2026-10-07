<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opname_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opname_id')->constrained('opnames')->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('stock_lots')->restrictOnDelete();
            $table->unsignedInteger('system_qty')->default(0);
            $table->unsignedInteger('counted_qty')->nullable();
            $table->integer('diff_qty')->nullable();
            $table->string('reason', 20)->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opname_lines');
    }
};
