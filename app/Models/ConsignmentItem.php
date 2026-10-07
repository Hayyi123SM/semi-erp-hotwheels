<?php

namespace App\Models;

use App\Enums\SchemeType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsignmentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'consignment_id',
        'line_no',
        'product_id',
        'qty',
        'rack_id',
        'card_condition',
        'blister_condition',
        'list_price',
        'scheme_type',
        'scheme_rate',
        'scheme_amount',
        'discount_policy',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'qty' => 'integer',
            'list_price' => 'integer',
            'scheme_rate' => 'decimal:2',
            'scheme_amount' => 'integer',
            'scheme_type' => SchemeType::class,
        ];
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class);
    }

    /**
     * Nilai yang benar-benar akan dipakai saat commit. Draft menyimpan null untuk
     * berarti "mewarisi profil penitip", jadi bentuk yang tersimpan dan bentuk
     * yang dipakai lot harus dibaca lewat satu pintu yang sama.
     *
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public function resolvedAttributes(array $defaults): array
    {
        return [
            'card_condition' => $this->card_condition,
            'blister_condition' => $this->blister_condition,
            'list_price' => $this->list_price ?? ($defaults['list_price'] ?? null),
            'scheme_type' => $this->scheme_type ?? ($defaults['scheme_type'] ?? null),
            'scheme_rate' => $this->scheme_rate ?? ($defaults['scheme_rate'] ?? null),
            'scheme_amount' => $this->scheme_amount ?? ($defaults['scheme_amount'] ?? null),
            'discount_policy' => $this->discount_policy ?? ($defaults['discount_policy'] ?? null),
        ];
    }
}
