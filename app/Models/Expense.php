<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'expense_date',
        'expense_category_id',
        'amount',
        'description',
        'vendor_name',
        'payment_method',
        'reference_no',
        'notes',
        'created_by',
        'updated_by',
        'is_imported',
        'import_batch',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'integer',
            'is_imported' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeForPeriod($query, ?string $from = null, ?string $to = null)
    {
        if ($from) {
            $query->whereDate('expense_date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('expense_date', '<=', $to);
        }

        return $query;
    }
}
