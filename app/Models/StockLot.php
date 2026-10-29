<?php

namespace App\Models;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\DiscountPolicy;
use App\Enums\LotStatus;
use App\Enums\OwnerType;
use App\Enums\SchemeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockLot extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku',
        'consignment_id',
        'sequence',
        'category_code',
        'owner_type',
        'owner_code',
        'consignor_id',
        'product_id',
        'card_condition',
        'blister_condition',
        'list_price',
        'cost_price',
        'scheme_type',
        'scheme_rate',
        'scheme_amount',
        'discount_policy',
        'terms_version',
        'negative_margin_flag',
        'qty_received',
        'qty_on_hand',
        'labels_printed',
        'reprint_count',
        'rack_id',
        'status',
        'last_sold_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'list_price' => 'integer',
            'cost_price' => 'integer',
            'scheme_rate' => 'decimal:2',
            'scheme_amount' => 'integer',
            'terms_version' => 'integer',
            'negative_margin_flag' => 'boolean',
            'qty_received' => 'integer',
            'qty_on_hand' => 'integer',
            'labels_printed' => 'integer',
            'reprint_count' => 'integer',
            'last_sold_at' => 'datetime',
            'owner_type' => OwnerType::class,
            'card_condition' => CardCondition::class,
            'blister_condition' => BlisterCondition::class,
            'scheme_type' => SchemeType::class,
            'discount_policy' => DiscountPolicy::class,
            'status' => LotStatus::class,
        ];
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class);
    }

    public function consignor(): BelongsTo
    {
        return $this->belongsTo(Consignor::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * Foreign key-nya ditulis eksplisit. Tanpa itu Eloquent menebak
     * `stock_lot_id` dari nama kelas, sedangkan kolomnya `lot_id` -- dan
     * query baru gagal saat relasi benar-benar dipakai, bukan saat ditulis.
     */
    public function labelPrintJobs(): HasMany
    {
        return $this->hasMany(LabelPrintJob::class, 'lot_id');
    }

    /**
     * Lot yang masih boleh disesuaikan lewat opname.
     *
     * Lot kosong ikut di sini karena opname per SKU juga dipakai untuk
     * memulihkan unit yang ditemukan kembali. Scope ini sengaja tidak dipakai
     * untuk opname seluruh gudang/per rak: sesi seperti itu hanya menghitung
     * barang yang benar-benar ada, bukan seluruh riwayat lot kosong.
     */
    public function scopeOpnameAdjustable(Builder $query): Builder
    {
        return $query->whereIn('status', [LotStatus::Available->value, LotStatus::SoldOut->value]);
    }

    /**
     * Lot yang punya unit fisik untuk dihitung pada opname seluruh gudang/rak.
     *
     * Lot nol tidak punya barang untuk dihitung dan tidak boleh muncul di
     * picker Mulai Sesi untuk cakupan tersebut.
     */
    public function scopeOpnameCountable(Builder $query): Builder
    {
        return $query->opnameAdjustable()->where('qty_on_hand', '>', 0);
    }

    public function isOwned(): bool
    {
        return $this->owner_type === OwnerType::Own;
    }

    public function isSellable(): bool
    {
        return $this->status === LotStatus::Available && $this->qty_on_hand > 0;
    }

    public function canBeReduced(): bool
    {
        return $this->isSellable();
    }

    public function canBeIncreased(): bool
    {
        return in_array($this->status, [LotStatus::Available, LotStatus::SoldOut], true);
    }
}
