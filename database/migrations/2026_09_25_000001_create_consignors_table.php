<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consignors', function (Blueprint $table) {
            $table->id();
            $table->string('consignor_code', 10)->unique();
            $table->string('name');
            $table->string('wa_number', 30)->nullable()->unique();
            $table->timestamp('wa_opt_in_at')->nullable();
            $table->text('address')->nullable();
            $table->date('agreement_date')->nullable();
            $table->string('scheme_type', 20)->default('PERCENTAGE');
            $table->decimal('scheme_rate', 5, 2)->nullable();
            $table->unsignedInteger('scheme_amount')->nullable();
            $table->string('discount_policy', 20)->default('STORE_BEARS');
            $table->string('loss_liability', 20)->default('STORE');
            $table->string('settlement_cycle', 20)->default('MONTHLY');
            $table->unsignedInteger('min_payout')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account')->nullable();
            $table->string('bank_holder')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignors');
    }
};
