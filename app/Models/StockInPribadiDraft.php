<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daftar produk Stock In Pribadi yang bertahan di server.
 *
 * Satu draft per (pengguna, perangkat): pengisian form ini bisa terhenti
 * karena tab tertutup atau browser dimuat ulang di tengah jalan, dan daftar
 * produk yang sudah dipilih -- termasuk HPP yang sudah diketik -- tidak boleh
 * hilang bersama layarnya. Beda dengan keranjang kasir, tidak ada shift yang
 * mengikatnya, jadi umur draft hanya dibatasi {@see $this->isExpired()}.
 */
class StockInPribadiDraft extends Model
{
    protected $table = 'stock_in_pribadi_drafts';

    protected $fillable = [
        'user_id',
        'device_id',
        'status',
        'items',
        'expired_at',
        'cancelled_at',
    ];

    protected $casts = [
        'items' => 'array',
        'expired_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'ACTIVE');
    }

    public function isExpired(): bool
    {
        if ($this->status === 'EXPIRED') {
            return true;
        }

        if ($this->expired_at && $this->expired_at->isPast()) {
            return true;
        }

        return false;
    }
}
