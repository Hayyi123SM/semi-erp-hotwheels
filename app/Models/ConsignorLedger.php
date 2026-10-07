<?php

namespace App\Models;

use App\Enums\LedgerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsignorLedger extends Model
{
    use HasFactory;

    protected $table = 'consignor_ledger';

    public $timestamps = false;

    protected $fillable = [
        'consignor_id',
        'type',
        'amount',
        'sale_item_id',
        'sale_id',
        'settlement_id',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'sale_item_id' => 'integer',
            'sale_id' => 'integer',
            'settlement_id' => 'integer',
            'created_at' => 'datetime',
            'type' => LedgerType::class,
        ];
    }

    public function consignor(): BelongsTo
    {
        return $this->belongsTo(Consignor::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }
}
