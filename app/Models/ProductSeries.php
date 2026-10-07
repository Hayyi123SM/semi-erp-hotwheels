<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductSeries extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'series_id');
    }
}
