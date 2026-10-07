<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('series_id')->nullable()->constrained('product_series')->nullOnDelete();
            $table->string('name');
            $table->string('casting_code')->nullable();
            $table->smallInteger('year')->nullable();
            $table->string('color')->nullable();
            $table->string('packaging_type', 20)->default('CARDED');
            $table->string('card_condition', 20)->default('MINT');
            $table->string('blister_condition', 20)->default('CLEAR');
            $table->string('factory_barcode_ref', 30)->nullable();
            $table->unsignedInteger('default_list_price');
            $table->json('photos')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
