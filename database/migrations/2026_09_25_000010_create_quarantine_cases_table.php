<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quarantine_cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_no', 30)->unique();
            $table->string('status', 30)->default('OPEN');
            $table->unsignedInteger('qty');
            $table->json('photo_urls')->nullable();
            $table->json('attributes')->nullable();
            $table->foreignId('rack_id')->nullable()->constrained('racks')->nullOnDelete();
            $table->foreignId('assigned_lot_id')->nullable()->constrained('stock_lots')->nullOnDelete();
            $table->integer('S')->nullable();
            $table->integer('C')->nullable();
            $table->integer('Q')->nullable();
            $table->integer('V')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('evidence')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quarantine_cases');
    }
};
