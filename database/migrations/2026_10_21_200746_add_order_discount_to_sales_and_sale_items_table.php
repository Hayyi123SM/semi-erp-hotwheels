<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('order_discount_type', 20)->nullable()->after('discount_total');
            $table->unsignedInteger('order_discount_value')->nullable()->after('order_discount_type');
            $table->unsignedInteger('order_discount_total')->default(0)->after('order_discount_value');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->unsignedInteger('order_discount')->default(0)->after('discount');
        });

        Schema::table('pos_cart_drafts', function (Blueprint $table) {
            $table->string('order_discount_type', 20)->nullable()->after('items');
            $table->unsignedInteger('order_discount_value')->nullable()->after('order_discount_type');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn([
                'order_discount_type',
                'order_discount_value',
                'order_discount_total',
            ]);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('order_discount');
        });

        Schema::table('pos_cart_drafts', function (Blueprint $table) {
            $table->dropColumn(['order_discount_type', 'order_discount_value']);
        });
    }
};
