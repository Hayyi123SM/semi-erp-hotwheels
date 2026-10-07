<?php

namespace App\Models;

use App\Enums\SaleStatus;
use App\Support\Format;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_sale_id',
        'receipt_no',
        'shift_id',
        'device_id',
        'user_id',
        'sold_at_client',
        'sold_at',
        'subtotal',
        'discount_total',
        'total',
        'status',
        'voided_at',
        'voided_by',
        'void_reason',
        'flags',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'sold_at_client' => 'datetime',
            'sold_at' => 'datetime',
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'total' => 'integer',
            'voided_at' => 'datetime',
            'voided_by' => 'integer',
            'flags' => 'array',
            'synced_at' => 'datetime',
            'status' => SaleStatus::class,
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(ConsignorLedger::class);
    }

    /**
     * Metode pembayarannya dalam satu kalimat, mis. "Tunai" atau "QRIS + Tunai".
     *
     * Nama, bukan kode: daftar ini dibaca kasir sambil membandingkan nota dengan
     * struk di tangannya, dan `TUNAI` di sana bukan `Cash`. Dipisah dengan `+`
     * karena satu nota boleh dibayar gabungan, dan dua baris untuk satu nota
     * membuat tabel ini menghitung nota lebih banyak dari kenyataan.
     */
    public function methodSummary(): string
    {
        $methods = $this->payments
            ->map(fn (SalePayment $payment): string => $payment->method->label())
            ->unique()
            ->values();

        return $methods->isEmpty()
            ? Format::EMPTY
            : $methods->implode(' + ');
    }

    /**
     * Catatan singkat untuk status yang bukan `PAID`.
     *
     * Dua status itu tidak salah, hanya belum bisa dipercaya, dan riwayat harus
     * menjelaskan kenapa tanpa kasir perlu membuka detailnya: nota dibuat perangkat
     * offline yang belum sampai ke server, atau ketentuan skema yang dipakai saat
     * penjualan sudah berubah setelahnya.
     */
    public function flagSummary(): ?string
    {
        return match ($this->status) {
            SaleStatus::SyncConflict => 'Perangkat mengirim nota yang sama dengan nomor berbeda',
            SaleStatus::TermsStale => 'Ketentuan skema berubah setelah nota ini dibuat',
            default => null,
        };
    }
}
