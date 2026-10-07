<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 30)->unique();
            $table->foreignId('consignment_id')->constrained('consignments')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('category_code', 5)->default('HW');
            $table->string('owner_type', 10)->default('CONSIGN');
            $table->string('owner_code', 10);
            $table->foreignId('consignor_id')->nullable()->constrained('consignors')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('card_condition', 20);
            $table->string('blister_condition', 20);
            $table->unsignedInteger('list_price');
            $table->unsignedInteger('cost_price')->nullable();
            $table->string('scheme_type', 20)->nullable();
            $table->decimal('scheme_rate', 5, 2)->nullable();
            $table->unsignedInteger('scheme_amount')->nullable();
            $table->string('discount_policy', 20)->nullable();
            $table->unsignedSmallInteger('terms_version')->default(1);
            $table->unsignedInteger('qty_received')->default(0);
            $table->unsignedInteger('qty_on_hand')->default(0);
            $table->unsignedInteger('labels_printed')->default(0);
            $table->foreignId('rack_id')->nullable()->constrained('racks')->nullOnDelete();
            $table->string('status', 20)->default('AVAILABLE');
            $table->timestamp('last_sold_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_lots');
    }
};
