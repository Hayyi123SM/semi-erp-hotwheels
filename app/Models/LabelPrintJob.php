<?php

namespace App\Models;

use App\Enums\LabelReason;
use App\Enums\LabelStatus;
use App\Services\Label\LabelContent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabelPrintJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'lot_id',
        'copies',
        'reason',
        'template',
        'show_price',
        'status',
        'requested_by',
        'approved_by',
        'printed_at',
    ];

    /**
     * `payload`, `device_id`, `error_message`, `confirmed_at`, dan `failed_at`
     * sengaja tidak ada di sini. Kolom-kolom itu diisi oleh sistem saat label
     * benar-benar dirender atau saat transisi status, bukan dari form. Kalau
     * ikut mass-assignable, siapa pun yang bisa mengirim request bisa menulis
     * `confirmed_at` sendiri dan membuat `labels_printed` naik tanpa label
     * yang benar-benar keluar dari printer.
     */
    protected function casts(): array
    {
        return [
            'copies' => 'integer',
            'show_price' => 'boolean',
            'printed_at' => 'datetime',
            'rendered_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
            'payload' => 'array',
            'error_message' => 'string',
            'reason' => LabelReason::class,
            'status' => LabelStatus::class,
        ];
    }

    /**
     * Bekukan isi label untuk job ini.
     *
     * Ini satu-satunya jalan menulis `payload`. Kolomnya sengaja tidak ada di
     * `$fillable`, jadi request dari browser tidak bisa mengisinya; sekaligus
     *_method ini tetap menyediakan cara yang jelas dari dalam aplikasi.
     * `update()` biasa tidak bisa dipakai di sini karena diam-diam mengabaikan
     * kolom yang tidak ada di `$fillable` -- termasuk diam-diam gagal menulis.
     *
     * Kalau `payload` sudah ada, isinya TIDAK ditimpa: snapshot pertama kali
     * yang harus bertahan, supaya re-print menghasilkan label yang sama dengan
     * cetakan sebelumnya.
     */
    public function snapshotContent(LabelContent $content): bool
    {
        if ($this->payload !== null && $this->payload !== []) {
            return false;
        }

        $this->forceFill([
            'payload' => $content->toPayload(),
            'rendered_at' => now(),
        ])->saveQuietly();

        return true;
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'lot_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
