<?php

namespace App\Services\Pos;

use App\Enums\ShiftStatus;
use App\Models\PosCartDraft;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Keranjang kasir yang bertahan di server.
 *
 * Satu draft per (shift, pengguna, perangkat). Layar kasir boleh saja kehilangan
 * isinya karena refresh atau tab yang tertutup -- yang tidak boleh adalah nota
 * yang belum dibayar ikut hilang bersama layarnya. Karena itu isi keranjang
 * ditulis ke `pos_cart_drafts` setiap kali berubah, dan dibaca kembali saat
 * halaman dibuka.
 *
 * Draft hanya dianggap hidup selama shift-nya masih terbuka; begitu shift
 * ditutup, isinya sudah tidak bisa dibayar lagi dan ditandai kedaluwarsa. Batas
 * 24 jam adalah jaring pengaman untuk draft yatim -- shift yang terbuka
 * berkepanjangan karena kasir lupa menutupnya.
 */
class CartDraftService
{
    /**
     * Berapa lama draft bertahan tanpa diperbarui, dalam jam.
     */
    private const TTL_HOURS = 24;

    /**
     * Draft aktif milik pengguna, shift, dan perangkat ini, atau `null`.
     *
     * Draft yang shiftnya sudah tertutup -- atau shiftnya tidak ditemukan lagi --
     * ditandai kedaluwarsa di sini dan tidak dikembalikan: mengembalikannya akan
     * membuat kasir bisa membayar barang yang shift-nya sudah tidak berjalan.
     */
    public function getActive(?int $shiftId = null, ?string $deviceId = null): ?PosCartDraft
    {
        $userId = Auth::id();

        if ($userId === null) {
            return null;
        }

        $draft = PosCartDraft::query()
            ->active()
            ->where('user_id', $userId)
            ->where('shift_id', $shiftId)
            ->when(
                $deviceId !== null,
                fn ($query) => $query->where('device_id', $deviceId),
                fn ($query) => $query->whereNull('device_id'),
            )
            ->latest('id')
            ->first();

        if ($draft === null) {
            return null;
        }

        if ($draft->isExpired()) {
            $this->markExpired($draft);

            return null;
        }

        if ($draft->shift_id !== null) {
            $shift = Shift::find($draft->shift_id);

            if ($shift === null || $shift->status !== ShiftStatus::Open) {
                $this->markExpired($draft);

                return null;
            }
        }

        return $draft;
    }

    /**
     * Simpan isi keranjang, atau batalkan draft bila keranjangnya kosong.
     *
     * Keranjang kosong bukan draft: menulisnya sebagai baris ACTIVE akan membuat
     * halaman kasir menawarkan memulihkan keranjang yang memang belum diisi apa
     * pun. Jadi isi kosong diterjemahkan menjadi pembatalan.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function save(array $items, ?int $shiftId = null, ?string $deviceId = null, ?string $note = null): ?PosCartDraft
    {
        $userId = Auth::id();

        if ($userId === null) {
            throw new RuntimeException('Tidak ada pengguna yang masuk.');
        }

        if ($items === []) {
            $this->cancel($shiftId, $deviceId);

            return null;
        }

        $draft = $this->getActive($shiftId, $deviceId) ?? new PosCartDraft([
            'shift_id' => $shiftId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'status' => 'ACTIVE',
        ]);

        $draft->fill([
            'items' => $items,
            'note' => $note,
            'shift_id' => $shiftId,
            'device_id' => $deviceId,
            'expired_at' => Carbon::now()->addHours(self::TTL_HOURS),
        ])->save();

        return $draft->fresh();
    }

    /**
     * Tandai draft aktif sebagai dibatalkan.
     *
     * Dipakai saat kasir mengosongkan keranjang dengan sengaja: draft yang
     * dibatalkan tidak akan ditawarkan untuk dipulihkan lagi.
     */
    public function cancel(?int $shiftId = null, ?string $deviceId = null): void
    {
        $draft = $this->getActive($shiftId, $deviceId);

        if ($draft === null) {
            return;
        }

        $draft->status = 'CANCELLED';
        $draft->cancelled_at = now();
        $draft->save();
    }

    /**
     * Tandai draft selesai -- dipakai setelah keranjangnya dibayar.
     */
    public function markCompleted(PosCartDraft $draft): void
    {
        $draft->status = 'COMPLETED';
        $draft->completed_at = now();
        $draft->save();
    }

    /**
     * Tandai satu draft kedaluwarsa, idempoten.
     */
    public function markExpired(PosCartDraft $draft): void
    {
        if ($draft->status === 'EXPIRED') {
            return;
        }

        $draft->status = 'EXPIRED';
        $draft->save();
    }

    /**
     * Sapu draft ACTIVE yang sudah lewat masa berlakunya atau shiftnya tertutup.
     *
     * @return int jumlah draft yang ditandai kedaluwarsa
     */
    public function expireOld(): int
    {
        $expired = PosCartDraft::query()
            ->where('status', 'ACTIVE')
            ->whereNotNull('expired_at')
            ->where('expired_at', '<=', now())
            ->update(['status' => 'EXPIRED']);

        $closed = PosCartDraft::query()
            ->where('status', 'ACTIVE')
            ->whereNotNull('shift_id')
            ->whereHas('shift', fn ($query) => $query->where('status', ShiftStatus::Closed))
            ->update(['status' => 'EXPIRED']);

        return $expired + $closed;
    }
}
