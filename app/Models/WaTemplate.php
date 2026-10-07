<?php

namespace App\Models;

use App\Enums\WaCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WaTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'category', 'body', 'variables', 'is_active'];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_active' => 'boolean',
            'category' => WaCategory::class,
        ];
    }
}
