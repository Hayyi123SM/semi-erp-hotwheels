<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SkuSequence extends Model
{
    use HasFactory;

    protected $primaryKey = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['owner_code', 'category_code', 'last_seq'];

    protected function casts(): array
    {
        return [
            'last_seq' => 'integer',
        ];
    }
}
