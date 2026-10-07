<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::firstOrCreate(
            ['username' => 'owner'],
            [
                'name' => 'Pemilik Toko',
                'email' => 'owner@semi-hw.test',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
                'role' => Role::Owner,
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['username' => 'staff'],
            [
                'name' => 'Kasir Utama',
                'email' => 'staff@semi-hw.test',
                'email_verified_at' => now(),
                'password' => Hash::make('password'),
                'role' => Role::Staff,
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['username' => 'ahmad.fauzi'],
            [
                'name' => 'Ahmad Fauzi',
                'password' => Hash::make('password'),
                'role' => Role::Staff,
                'is_active' => true,
            ]
        );

        $owner->save();
    }
}
