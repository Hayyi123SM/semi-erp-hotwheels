<?php

namespace App\Models;

use App\Enums\ConsignorStatus;
use App\Enums\DiscountPolicy;
use App\Enums\LossLiability;
use App\Enums\SchemeType;
use App\Enums\SettlementCycle;
use App\Support\WhatsappNumber;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Consignor extends Model
{
    use HasFactory;

    protected $fillable = [
        'consignor_code',
        'name',
        'wa_number',
        'wa_opt_in_at',
        'address',
        'agreement_date',
        'scheme_type',
        'scheme_rate',
        'scheme_amount',
        'discount_policy',
        'loss_liability',
        'settlement_cycle',
        'min_payout',
        'bank_name',
        'bank_account',
        'bank_holder',
        'status',
        'notes',
    ];

    /**
     * Nomor WhatsApp dinormalkan di satu tempat saja, yaitu di sini.
     *
     * Mutator, bukan aturan request, karena request bukan satu-satunya pintu
     * masuk: impor dan tinker menulis kolom yang sama. Aturan di request akan
     * membuat kedua pintu itu menulis bentuk yang berbeda dari form, dan kolom
     * yang(unique) di database hanya menahan duplikasi byte yang identik -- bukan
     * satu nomor yang ditulis `0812-3456-7890` di satu baris dan
     * `+6281234567890` di baris lain.
     */
    protected function waNumber(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => WhatsappNumber::normalize($value),
        );
    }

    /**
     * Nomor dalam bentuk yang ditampilkan ke orang.
     *
     * Nilai di database tetap bentuk polos karena itu yang dibaca `wa.me`;
     * bentuk yang dikelompokkan hanya untuk dibaca manusia di tabel dan form.
     */
    protected function waNumberDisplay(): Attribute
    {
        return Attribute::get(fn (): ?string => WhatsappNumber::display($this->wa_number));
    }

    protected function casts(): array
    {
        return [
            'wa_opt_in_at' => 'datetime',
            'agreement_date' => 'date',
            'scheme_rate' => 'decimal:2',
            'scheme_amount' => 'integer',
            'min_payout' => 'integer',
            'bank_name' => 'encrypted',
            'bank_account' => 'encrypted',
            'bank_holder' => 'encrypted',
            'scheme_type' => SchemeType::class,
            'discount_policy' => DiscountPolicy::class,
            'loss_liability' => LossLiability::class,
            'settlement_cycle' => SettlementCycle::class,
            'status' => ConsignorStatus::class,
        ];
    }

    public function consignments(): HasMany
    {
        return $this->hasMany(Consignment::class);
    }

    public function stockLots(): HasMany
    {
        return $this->hasMany(StockLot::class);
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(ConsignorLedger::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }

    public function rtvNotes(): HasMany
    {
        return $this->hasMany(RtvNote::class);
    }
}
