<?php

namespace App\Models;

use App\Enums\RackType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rack extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'zone', 'type', 'capacity', 'is_active'];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
            'type' => RackType::class,
        ];
    }

    public function stockLots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }

    public function quarantineCases(): HasMany
    {
        return $this->hasMany(QuarantineCase::class);
    }

    public function opnames(): HasMany
    {
        return $this->hasMany(Opname::class);
    }
}
