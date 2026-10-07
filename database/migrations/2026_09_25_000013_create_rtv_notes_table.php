<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rtv_notes', function (Blueprint $table) {
            $table->id();
            $table->string('rtv_no', 40)->unique();
            $table->foreignId('consignor_id')->constrained('consignors')->restrictOnDelete();
            $table->string('status', 20)->default('DRAFT');
            $table->string('reason')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rtv_notes');
    }
};
