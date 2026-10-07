<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sku_sequences', function (Blueprint $table) {
            $table->string('owner_code', 10);
            $table->string('category_code', 5);
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->primary(['owner_code', 'category_code']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_sequences');
    }
};
