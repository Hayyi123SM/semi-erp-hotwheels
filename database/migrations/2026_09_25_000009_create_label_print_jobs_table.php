<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained('stock_lots')->restrictOnDelete();
            $table->unsignedInteger('copies')->default(1);
            $table->string('reason', 30)->nullable();
            $table->string('template', 10)->default('3x2');
            $table->boolean('show_price')->default(true);
            $table->string('status', 20)->default('QUEUED');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_print_jobs');
    }
};
