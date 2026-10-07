<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('stock_lots')->restrictOnDelete();
            $table->string('sku', 30);
            $table->string('owner_code', 10);
            $table->unsignedInteger('qty')->default(1);
            $table->unsignedInteger('list_price')->default(0);
            $table->unsignedInteger('discount')->default(0);
            $table->unsignedInteger('sell_price')->default(0);
            $table->string('scheme_type', 20)->nullable();
            $table->decimal('scheme_rate', 5, 2)->nullable();
            $table->unsignedInteger('scheme_amount')->nullable();
            $table->unsignedSmallInteger('terms_version')->default(1);
            $table->unsignedInteger('cost_price_snapshot')->nullable();
            $table->integer('fee_toko')->nullable();
            $table->integer('hak_penitip')->nullable();
            $table->string('input_method', 10)->default('SCAN');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
