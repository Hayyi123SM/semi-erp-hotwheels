<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_visibilities', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120);
            $table->string('role', 20);
            $table->boolean('visible')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['key', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_visibilities');
    }
};
