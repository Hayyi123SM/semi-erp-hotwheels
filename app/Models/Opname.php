<?php

namespace App\Models;

use App\Enums\OpnameScope;
use App\Enums\OpnameStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Opname extends Model
{
    use HasFactory;

    protected $fillable = [
        'opname_no',
        'scope',
        'rack_id',
        'scope_value',
        'status',
        'started_at',
        'movement_from_id',
        'submitted_at',
        'closed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'closed_at' => 'datetime',
            'movement_from_id' => 'integer',
            'scope' => OpnameScope::class,
            'status' => OpnameStatus::class,
        ];
    }

    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OpnameLine::class);
    }
}
