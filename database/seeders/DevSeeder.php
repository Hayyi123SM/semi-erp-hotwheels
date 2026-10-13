<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Membuat satu akun Developer dari kredensial environment.
 *
 * Developer sengaja tidak bisa dibuat lewat UI oleh Owner; seeder ini adalah
 * salah satu dari dua jalur sahnya (satunya: Developer yang sudah ada membuat
 * Developer baru). Tanpa `DEV_PASSWORD`, seeder tidak menyentuh apa pun --
 * tidak ada kata sandi bawaan yang ikut ke repositori.
 */
class DevSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('dev.password');

        if (! is_string($password) || $password === '') {
            return;
        }

        User::firstOrCreate(
            ['username' => (string) config('dev.username')],
            [
                'name' => (string) config('dev.name'),
                'email' => config('dev.email') ?: null,
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                'role' => Role::Dev,
                'is_active' => true,
            ],
        );
    }
}
