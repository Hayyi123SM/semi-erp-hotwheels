<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris draft. Doc tidak menyebut tabel ini secara eksplisit, tapi draft tidak bisa
 * dilanjutkan tanpa somewhere to persist baris grid -- selama ini baris hanya hidup
 * di POST commit, jadi once the tab tertutup atau HP mati di tengah calculation,
 * isi form hilang seluruhnya.
 *
 * Kolomnya sengaja meniru `stock_lots` supaya commit cukup memindahkan nilainya dan
 * tidak perlu memetakan ulang. `list_price`, `scheme_*`, dan `discount_policy`
 * nullable berarti "mewarisi profil penitip", bukan nol -- null adalah bahasa yang
 * sudah dipakai `stock_lots` juga, jadi tidak ada nilai yang berarti berbeda antara
 * draft dan lot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consignment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consignment_id')->constrained('consignments')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->foreignId('rack_id')->nullable()->constrained('racks')->nullOnDelete();
            $table->string('card_condition', 20);
            $table->string('blister_condition', 20);
            $table->unsignedInteger('list_price')->nullable();
            $table->string('scheme_type', 20)->nullable();
            $table->decimal('scheme_rate', 5, 2)->nullable();
            $table->unsignedInteger('scheme_amount')->nullable();
            $table->string('discount_policy', 20)->nullable();
            $table->timestamps();

            $table->unique(['consignment_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignment_items');
    }
};
