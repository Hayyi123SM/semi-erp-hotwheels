<?php

namespace App\Models;

use App\Enums\AdjustmentReason;
use App\Enums\OpnameLineStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpnameLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'opname_id',
        'lot_id',
        'system_qty',
        'counted_qty',
        'diff_qty',
        'counted_at',
        'counted_movement_id',
        'counted_by',
        'reason',
        'status',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'system_qty' => 'integer',
            'counted_qty' => 'integer',
            'diff_qty' => 'integer',
            'counted_at' => 'datetime',
            'counted_movement_id' => 'integer',
            'reason' => AdjustmentReason::class,
            'status' => OpnameLineStatus::class,
        ];
    }

    public function opname(): BelongsTo
    {
        return $this->belongsTo(Opname::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Penghitung baris ini: siapa yang memasukkan angka fisiknya.
     *
     * Berbeda dari `approver`, yang memutuskan; hitung fisik dan persetujuan
     * memang dua orang yang berbeda, dan keduanya ditulis terpisah supaya
     * pertanyaan "siapa yang menghitung, siapa yang mengizinkan" bisa dijawab
     * tanpa membaca log.
     */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
