<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'email_verified_at',
        'password',
        'role',
        'pin',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'pin',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
        ];
    }

    public function isOwner(): bool
    {
        return $this->role === Role::Owner;
    }

    public function isStaff(): bool
    {
        return $this->role === Role::Staff;
    }

    /**
     * Halaman muka per peran.
     *
     * Owner memegang seluruh modul dan mendarat di dashboard. Staff hanya
     * bekerja di POS, jadi mendarat di kasir. Dipakai oleh halaman muka dan
     * semua pengalihan setelah login supaya tidak ada satu pun jalur yang
     * menjatuhkan Staff ke halaman yang akan menolaknya.
     */
    public function homeRoute(): string
    {
        return $this->isOwner() ? 'dashboard' : 'pos.kasir';
    }

    /**
     * Inisial untuk avatar, diambil dari nama.
     *
     * Nama multi-kata memakai huruf pertama kata pertama dan terakhir
     * ("Ahmad Fauzi" -> "AF"), nama satu kata cukup huruf pertamanya.
     * Nama kosong jatuh ke huruf pertama `username` agar avatar tidak pernah
     * tampil kosong, dan kalau itu pun tidak ada, satu tanda tanya.
     */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim((string) $this->name)) ?: [];

        if ($words === [] || $words === ['']) {
            $fallback = trim((string) $this->username);
            $char = $fallback === '' ? '?' : mb_substr($fallback, 0, 1);
        } elseif (count($words) === 1) {
            $char = mb_substr($words[0], 0, 1);
        } else {
            $char = mb_substr($words[0], 0, 1).mb_substr($words[array_key_last($words)], 0, 1);
        }

        return mb_strtoupper($char);
    }

    public function consignments(): HasMany
    {
        return $this->hasMany(Consignment::class, 'created_by');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }
}
