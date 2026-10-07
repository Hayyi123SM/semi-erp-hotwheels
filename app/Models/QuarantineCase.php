<?php

namespace App\Models;

use App\Enums\QuarantineStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuarantineCase extends Model
{
    use HasFactory;

    protected $fillable = [
        'case_no',
        'status',
        'qty',
        'photo_urls',
        'attributes',
        'rack_id',
        'assigned_lot_id',
        'S',
        'C',
        'Q',
        'V',
        'counted_at',
        'decided_by',
        'evidence',
        'resolved_at',
        'opened_by',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'photo_urls' => 'array',
            'attributes' => 'array',
            'S' => 'integer',
            'C' => 'integer',
            'Q' => 'integer',
            'V' => 'integer',
            'counted_at' => 'datetime',
            'evidence' => 'array',
            'resolved_at' => 'datetime',
            'status' => QuarantineStatus::class,
        ];
    }

    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class);
    }

    public function assignedLot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'assigned_lot_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
