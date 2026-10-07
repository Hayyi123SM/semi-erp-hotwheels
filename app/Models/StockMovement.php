<?php

namespace App\Models;

use App\Enums\MovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'lot_id',
        'type',
        'qty_delta',
        'ref_type',
        'ref_id',
        'actor_id',
        'device_id',
        'reason',
        'balance_after',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'qty_delta' => 'integer',
            'ref_id' => 'integer',
            'balance_after' => 'integer',
            'created_at' => 'datetime',
            'type' => MovementType::class,
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
