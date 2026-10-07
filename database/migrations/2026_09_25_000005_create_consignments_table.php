<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consignments', function (Blueprint $table) {
            $table->id();
            $table->string('doc_no', 40)->unique();
            $table->string('owner_type', 10)->default('CONSIGN');
            $table->foreignId('consignor_id')->nullable()->constrained('consignors')->restrictOnDelete();
            $table->string('source')->nullable();
            $table->date('consignment_date');
            $table->text('notes')->nullable();
            $table->unsignedInteger('qty_claimed')->nullable();
            $table->unsignedInteger('qty_received')->nullable();
            $table->text('variance_note')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->unsignedBigInteger('committed_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consignments');
    }
};
