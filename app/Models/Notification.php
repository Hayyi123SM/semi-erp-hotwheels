<?php

namespace App\Models;

use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pesan notifikasi yang pernah disiapkan untuk dikirim.
 *
 * Baris ini adalah jejak audit, bukan antrean. Setelah terkirim, pesannya tetap
 * ada: kalau penitip bilang "bukti terima yang saya terima tidak sama", satu-
 *-satunya jawaban adalah pesan yang benar-benar keluar waktu itu, dan snapshot
 * `body` di baris inilah yang membuktikannya.
 *
 * Karena itu tidak ada kolom yang "hanya untuk menunggu" dihapus begitu
 * terkirim. Yang berubah cuma `status` dan kolom waktunya.
 */
class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'notification_key',
        'channel',
        'template_name',
        'recipient',
        'consignment_id',
        'requested_by',
    ];

    /**
     * `status`, `attempts`, `next_attempt_at`, `body`, `error_message`, dan
     * kolom waktu TIDAK ada di sini, sama seperti di `LabelPrintJob`.
     *
     * Kolom-kolom itu ditulis oleh `NotificationSender`, bukan dari form.
     * Kalau ikut mass-assignable, request dari browser bisa menulis
     * `status = READ` sendiri lalu notifikasi selesai tanpa pernah
     * dikirim -- dan baris itulah yang dipakai untuk membuktikan pesan mana
     * yang benar-benar keluar.
     */
    protected function casts(): array
    {
        return [
            'status' => NotificationStatus::class,
            'attempts' => 'integer',
            'body' => 'string',
            'error_message' => 'string',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * Bekukan isi pesan yang benar-benar dikirim.
     *
     * Sama seperti `LabelPrintJob::snapshotContent()`: isinya tidak pernah
     * ditimpa setelah ada. Template di Pengaturan bisa diubah kapan saja, dan
     * kalau pesan lama ikut berubah ketika template berubah, bukti "apa yang
     * terkirim" jadi tidak ada artinya.
     */
    public function snapshotBody(string $body): bool
    {
        if ($this->body !== null && $this->body !== '') {
            return false;
        }

        $this->forceFill([
            'body' => $body,
            'next_attempt_at' => now(),
        ])->saveQuietly();

        return true;
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Label status untuk badge di dokumen: status + alasan kalau gagal.
     */
    public function statusLabel(): string
    {
        return $this->status->label();
    }
}
