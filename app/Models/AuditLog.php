<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Support\Audit\AuditDiff;
use App\Support\Audit\AuditFieldChange;
use App\Support\Format;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'device_id',
        'action',
        'entity',
        'entity_id',
        'entity_key',
        'before',
        'after',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Identitas apa yang dipakai entitas ini, untuk ditampilkan di laporan.
     *
     * Hampir semua baris punya primary key numerik, jadi kolom `entity_id`
     * yang dibaca. `Setting` tidak punya primary key sama sekali -- kuncinya
     * berupa teks dan disimpan di `entity_key` -- jadi baris itu perlu dibaca
     * dari kolom yang berbeda.
     *
     * Baris tanpa identitas sama sekali muncul di laporan audit: `imported()`
     * memang tidak menunjuk satu baris pun, melainkan satu peristiwa. Untuk
     * kasus itu dikembalikan `Format::EMPTY`, bukan string kosong, supaya
     * sel kosongnya terbaca sebagai "tidak ada" dan bukan sebagai teks yang
     * sengaja tidak diisi.
     */
    public function identity(): string
    {
        return $this->entity_key
            ?? ($this->entity_id !== null ? (string) $this->entity_id : Format::EMPTY);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Nama aksi yang siap dibaca manusia, dengan nilai cadangan yang jujur.
     */
    public function getActionLabelAttribute(): string
    {
        return AuditAction::labelFor($this->action);
    }

    /**
     * Warna badge untuk aksi ini.
     */
    public function getActionTypeAttribute(): string
    {
        return AuditAction::typeFor($this->action);
    }

    /**
     * Nama entitas untuk kolom daftar.
     *
     * Tidak ada pengecualian per entitas di sini. `Setting` memang menunjuk ke
     * kunci teksnya, dan setiap entitas lain menunjuk ke primary key-nya --
     * dua-duanya sudah ditangani oleh {@see identity()}, jadi kolom identitas
     * di sebelahnya sudah menunjuk ke tempat yang benar untuk keduanya.
     */
    public function getEntityLabelAttribute(): string
    {
        return $this->entity ?? Format::EMPTY;
    }

    /**
     * Ringkasan perubahan untuk kolom daftar.
     */
    public function getDiffSummaryAttribute(): string
    {
        return AuditDiff::summary($this->diffChanges());
    }

    /**
     * Perubahan terperinci untuk panel expand.
     *
     * Dipisah dari accessor attribute supaya view yang memanggilnya sekali per
     * baris tidak membangun ulang diff-nya di dua tempat sekaligus.
     *
     * @return list<AuditFieldChange>
     */
    public function diffChanges(): array
    {
        return AuditDiff::between($this->before, $this->after);
    }
}
