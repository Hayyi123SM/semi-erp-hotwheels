<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            DevSeeder::class,
            MasterSeeder::class,
        ]);

        // Data contoh hanya untuk lingkungan pengembangan; produksi tidak boleh
        // ikut terisi nota penjualan dan pergeseran kasir palsu.
        if (app()->environment(['local', 'development', 'testing'])) {
            $this->call(DemoSeeder::class);
        }
    }
}
