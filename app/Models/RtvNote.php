<?php

namespace App\Models;

use App\Enums\RtvStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RtvNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'rtv_no',
        'consignor_id',
        'status',
        'reason',
        'executed_at',
        'created_by',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'executed_at' => 'datetime',
            'status' => RtvStatus::class,
        ];
    }

    public function consignor(): BelongsTo
    {
        return $this->belongsTo(Consignor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RtvLine::class, 'rtv_id');
    }

    /**
     * Apakah dokumen ini masih menggantung (FR-IC-35: satu sesi terbuka).
     */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Total unit yang diminta dokumen ini.
     */
    public function units(): int
    {
        return (int) $this->lines->sum('qty');
    }

    /**
     * Total unit yang sudah dipindai ke rak staging.
     */
    public function verifiedUnits(): int
    {
        return (int) $this->lines->sum('verified_qty');
    }
}
