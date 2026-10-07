<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RtvLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'rtv_id',
        'lot_id',
        'qty',
        'verified_qty',
        'verified_at',
        'verified_by',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'verified_qty' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function rtvNote(): BelongsTo
    {
        return $this->belongsTo(RtvNote::class, 'rtv_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    /**
     * Orang yang memindai unit terakhir untuk baris ini.
     *
     * Terpisah dari `creator` dokumen: yang membuat sesi dan yang memindai
     * fisiknya bisa dua orang berbeda, dan "siapa yang benar-benar memegang
     * barangnya" adalah pertanyaan yang muncul justru ketika ada yang kurang.
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Apakah semua unit baris ini sudah dipindai ke rak staging (FR-IC-32).
     */
    public function isFullyVerified(): bool
    {
        return $this->verified_qty >= $this->qty;
    }
}
