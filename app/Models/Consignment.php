<?php

namespace App\Models;

use App\Enums\ConsignmentStatus;
use App\Enums\OwnerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Consignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'doc_no',
        'draft_id',
        'owner_type',
        'consignor_id',
        'source',
        'consignment_date',
        'notes',
        'qty_claimed',
        'qty_received',
        'variance_note',
        'saved_at',
        'idempotency_key',
        'status',
        'created_by',
        'committed_at',
        'committed_by',
    ];

    protected function casts(): array
    {
        return [
            'consignment_date' => 'date',
            'qty_claimed' => 'integer',
            'qty_received' => 'integer',
            'saved_at' => 'datetime',
            'committed_at' => 'datetime',
            'committed_by' => 'integer',
            'owner_type' => OwnerType::class,
            'status' => ConsignmentStatus::class,
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ConsignmentItem::class)->orderBy('line_no');
    }

    public function consignor(): BelongsTo
    {
        return $this->belongsTo(Consignor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stockLots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }

    /**
     * Riwayat notifikasi dokumen ini, terbaru lebih dulu.
     *
     * Hubungannya benar-benar satu-ke-banyak, bukan satu. `hasOne` yang
     * mengambil notifikasi terakhir terlihat benar sampai ada percobaan kirim
     * ulang: setelah itu, satu pesanan punya dua baris, dan `hasOne` diam-diam
     * menyembunyikan yang pertama -- termasuk percobaan yang gagal, yang justru
     * bagian yang paling perlu dilihat.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class)->latest('id');
    }
}
