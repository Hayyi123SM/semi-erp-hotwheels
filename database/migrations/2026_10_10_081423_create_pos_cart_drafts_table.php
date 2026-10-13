<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_cart_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('device_id', 50)->nullable()->index();
            $table->enum('status', ['ACTIVE', 'COMPLETED', 'EXPIRED', 'CANCELLED'])->default('ACTIVE')->index();
            $table->json('items')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('expired_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['shift_id', 'user_id', 'device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_cart_drafts');
    }
};
