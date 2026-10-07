<?php

namespace App\Models;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\PackagingType;
use App\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'series_id',
        'name',
        'casting_code',
        'year',
        'color',
        'packaging_type',
        'card_condition',
        'blister_condition',
        'factory_barcode_ref',
        'default_list_price',
        'photos',
        'tags',
        'needs_review',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'default_list_price' => 'integer',
            'photos' => 'array',
            'tags' => 'array',
            'needs_review' => 'boolean',
            'packaging_type' => PackagingType::class,
            'card_condition' => CardCondition::class,
            'blister_condition' => BlisterCondition::class,
            'status' => ProductStatus::class,
        ];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(ProductSeries::class, 'series_id');
    }

    public function stockLots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }
}
