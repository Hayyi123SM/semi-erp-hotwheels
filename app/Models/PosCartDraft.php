<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosCartDraft extends Model
{
    use HasFactory;

    protected $table = 'pos_cart_drafts';

    protected $fillable = [
        'shift_id',
        'user_id',
        'device_id',
        'status',
        'items',
        'note',
        'expired_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'items' => 'array',
        'expired_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

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
