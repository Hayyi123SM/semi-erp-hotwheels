<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_no', 40)->unique();
            $table->foreignId('consignor_id')->constrained('consignors')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamp('cut_off_at')->nullable();
            $table->unsignedInteger('total_bruto')->default(0);
            $table->unsignedInteger('total_fee')->default(0);
            $table->unsignedInteger('total_hak')->default(0);
            $table->unsignedInteger('refunds')->default(0);
            $table->integer('adjustments')->default(0);
            $table->integer('carry_over')->default(0);
            $table->integer('net_payable')->default(0);
            $table->string('status', 20)->default('DRAFT');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
