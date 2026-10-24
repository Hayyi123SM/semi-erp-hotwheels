<?php

namespace App\Models;

use App\Enums\ShiftStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'user_id',
        'opened_at',
        'opening_cash',
        'closed_at',
        'closing_cash',
        'cash_diff',
        'closed_by',
        'cash_diff_approved_by',
        'status',
        'notes',
        'closing_notes',
        'closing_requested_at',
        'closing_approved_at',
        'closing_rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'opening_cash' => 'integer',
            'closed_at' => 'datetime',
            'closing_cash' => 'integer',
            'cash_diff' => 'integer',
            'status' => ShiftStatus::class,
            'closing_requested_at' => 'datetime',
            'closing_approved_at' => 'datetime',
        ];
    }

    /**
     * Shift yang masih berjalan: boleh dipakai menjual.
     *
     * Scope ini adalah satu-satunya definisi "sedang berjualan" di seluruh
     * aplikasi. Shift yang menunggu keputusan Owner sengaja tidak termasuk:
     * lacinya sudah dihitung, dan penjualan baru akan membuat angka itu salah.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ShiftStatus::Open);
    }

    /**
     * Shift yang belum selesai: masih berjalan atau menunggu keputusan Owner.
     *
     * Dipakai pertanyaan "boleh buka shift baru?" dan "shift mana yang sedang
     * dipegang kasir ini?". Kalau keduanya memakai `open()`, kasir yang shiftnya
     * menunggu persetujuan akan bisa membuka shift kedua -- dengan laci yang
     * sama dan uang pembuka yang terhitung dua kali.
     */
    public function scopeUnclosed(Builder $query): Builder
    {
        return $query->whereIn('status', [ShiftStatus::Open, ShiftStatus::PendingApproval]);
    }

    /**
     * Shift yang menunggu keputusan Owner, untuk halaman persetujuan.
     */
    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('status', ShiftStatus::PendingApproval);
    }

    /**
     * Shift milik satu kasir, untuk halaman yang menampilkan riwayatnya sendiri.
     */
    public function scopeForUser(Builder $query, ?int $userId): Builder
    {
        return $userId === null
            ? $query->whereNull('user_id')
            : $query->where('user_id', $userId);
    }

    /**
     * Shift yang belum selesai untuk perangkat tertentu, atau `null`.
     *
     * Diurutkan dari yang paling baru, karena kalau somehow ada lebih dari satu --
     * dua kasir memakai akun yang sama di satu mesin, atau data diimpor dari sistem
     * lama -- yang ditampilkan paling baru adalah shift yang sedang dipakai, bukan
     * shift yang terlupa.
     */
    public static function unclosedForDevice(?string $deviceId): ?self
    {
        $query = static::query()->unclosed();

        $deviceId === null
            ? $query->whereNull('device_id')
            : $query->where('device_id', $deviceId);

        return $query->latest('id')->first();
    }

    /**
     * Shift yang belum selesai milik satu kasir, atau `null`.
     *
     * Dipisah dari `unclosedForDevice` karena keduanya menjawab pertanyaan
     * berbeda dengan konsekuensi berbeda: yang satu mencegah shift ganda di satu
     * mesin, yang satu mencegah satu kasir punya dua shift belum selesai
     * sekaligus.
     */
    public static function unclosedForUser(?int $userId): ?self
    {
        $query = static::query()->unclosed();

        $userId === null
            ? $query->whereNull('user_id')
            : $query->where('user_id', $userId);

        return $query->latest('id')->first();
    }

    /**
     * Shift masih berjalan sampai sekarang?
     */
    public function isOpen(): bool
    {
        return $this->status === ShiftStatus::Open;
    }

    public function isPendingApproval(): bool
    {
        return $this->status === ShiftStatus::PendingApproval;
    }

    public function isClosed(): bool
    {
        return $this->status === ShiftStatus::Closed;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Yang menutup shift, kalau shift sudah ditutup.
     *
     * Berbeda dari `user`: `user_id` adalah kasir yang membuka shift, yang
     * tercatat sejak baris pertama ada. `closed_by` adalah orang yang menghitung
     * uang di laci, dan tidak selalu orang yang sama -- tutup shift untuk
     * menggantikan kasir yang pulang lebih awal memang kejadian yang wajar.
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Owner yang mengesahkan selisih kas di luar ambang batas.
     *
     * Terpisah dari `closedBy` karena yang mengesahkan bukan yang menghitung:
     * satu baris dengan dua orang di dalamnya tidak bisa lagi menjawab "kasirnya
     * siapa" sekaligus "pembuatnya siapa" tanpa menebak.
     */
    public function cashDiffApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cash_diff_approved_by');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
