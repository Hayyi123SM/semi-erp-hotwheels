<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * committed_by (consignments) dan voided_by (sales) keduanya menahan user id,
 * tapi awalnya dideklarasi sebagai unsignedBigInteger telanjang tanpa foreign key.
 * Akibatnya user yang dihapus tidak mengosongkan kolom, dan referensi bisa
 * menunjuk user yang tidak ada. Keduanya sekarang terikat ke users.id.
 */
return new class extends Migration
{
    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function foreignKeys(): array
    {
        return [
            ['consignments', 'committed_by'],
            ['sales', 'voided_by'],
        ];
    }

    public function up(): void
    {
        foreach ($this->foreignKeys() as [$table, $column]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->index($column);
            });

            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->foreign($column)->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->foreignKeys() as [$table, $column]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
                $blueprint->dropIndex([$column]);
            });
        }
    }
};
