<?php

namespace App\Models;

use App\Enums\SettlementStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Settlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'settlement_no',
        'consignor_id',
        'period_start',
        'period_end',
        'cut_off_at',
        'total_bruto',
        'total_fee',
        'total_hak',
        'refunds',
        'adjustments',
        'carry_over',
        'net_payable',
        'status',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'cut_off_at' => 'datetime',
            'total_bruto' => 'integer',
            'total_fee' => 'integer',
            'total_hak' => 'integer',
            'refunds' => 'integer',
            'adjustments' => 'integer',
            'carry_over' => 'integer',
            'net_payable' => 'integer',
            'approved_at' => 'datetime',
            'status' => SettlementStatus::class,
        ];
    }

    public function consignor(): BelongsTo
    {
        return $this->belongsTo(Consignor::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SettlementPayment::class);
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(ConsignorLedger::class);
    }
}
