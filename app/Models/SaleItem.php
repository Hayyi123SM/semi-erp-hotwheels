<?php

namespace App\Models;

use App\Enums\InputMethod;
use App\Enums\SchemeType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'lot_id',
        'sku',
        'owner_code',
        'qty',
        'list_price',
        'discount',
        'sell_price',
        'scheme_type',
        'scheme_rate',
        'scheme_amount',
        'terms_version',
        'cost_price_snapshot',
        'fee_toko',
        'hak_penitip',
        'input_method',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'list_price' => 'integer',
            'discount' => 'integer',
            'sell_price' => 'integer',
            'scheme_rate' => 'decimal:2',
            'scheme_amount' => 'integer',
            'terms_version' => 'integer',
            'cost_price_snapshot' => 'integer',
            'fee_toko' => 'integer',
            'hak_penitip' => 'integer',
            'scheme_type' => SchemeType::class,
            'input_method' => InputMethod::class,
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function ledger(): HasOne
    {
        return $this->hasOne(ConsignorLedger::class);
    }
}
