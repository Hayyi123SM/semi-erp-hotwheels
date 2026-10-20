<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\StockInPribadiDraft;
use App\Services\Pos\CartDraftService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Daftar produk Stock In Pribadi yang bertahan di server.
 *
 * Satu draft per (pengguna, perangkat). Form ini bisa tertutup di tengah
 * pengisian, dan daftar produk yang sudah dipilih -- lengkap dengan HPP yang
 * sudah diketik -- tidak boleh hilang bersama layarnya. Karena itu isinya
 * ditulis ke `stock_in_pribadi_drafts` setiap kali berubah, dan dibaca kembali
 * saat halaman dibuka.
 *
 * Tidak ada shift di sini: form ini diisi jauh sebelum barang diterima, jadi
 * yang mengikat umur draft hanya masa berlakunya sendiri. Batas 24 jam adalah
 * jaring pengaman untuk draft yatim -- form yang dibuka lalu ditinggal begitu
 * saja.
 *
 * Pola yang dipakai sama persis dengan {@see CartDraftService}:
 * draft yang isinya kosong bukan draft, melainkan pembatalan.
 */
class StockInPribadiDraftService
{
    /**
     * Berapa lama draft bertahan tanpa diperbarui, dalam jam.
     */
    private const TTL_HOURS = 24;

    /**
     * Draft aktif milik pengguna dan perangkat ini, atau `null`.
     *
     * Draft yang sudah lewat umurnya ditandai kedaluwarsa di sini dan tidak
     * dikembalikan, supaya halaman tidak menawarkan memulihkan daftar yang
     * isinya sudah basi.
     */
    public function getActive(?string $deviceId = null): ?StockInPribadiDraft
    {
        $userId = Auth::id();

        if ($userId === null) {
            return null;
        }

        $draft = StockInPribadiDraft::query()
            ->active()
            ->where('user_id', $userId)
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
            $draft->status = 'EXPIRED';
            $draft->save();

            return null;
        }

        return $draft;
    }

    /**
     * Simpan isi daftar, atau batalkan draft bila daftarnya kosong.
     *
     * Daftar kosong bukan draft: menyimpannya sebagai baris ACTIVE akan
     * membuat halaman menawarkan memulihkan daftar yang memang belum diisi apa
     * pun. Jadi isi kosong diterjemahkan menjadi pembatalan.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function save(array $items, ?string $deviceId = null): ?StockInPribadiDraft
    {
        $userId = Auth::id();

        if ($userId === null) {
            throw new RuntimeException('Tidak ada pengguna yang masuk.');
        }

        if ($items === []) {
            $this->cancel($deviceId);

            return null;
        }

        $draft = $this->getActive($deviceId) ?? new StockInPribadiDraft([
            'user_id' => $userId,
            'device_id' => $deviceId,
            'status' => 'ACTIVE',
        ]);

        $draft->fill([
            'items' => $items,
            'device_id' => $deviceId,
            'expired_at' => Carbon::now()->addHours(self::TTL_HOURS),
        ])->save();

        return $draft->fresh();
    }

    /**
     * Tandai draft aktif sebagai dibatalkan.
     *
     * Dipakai saat daftar dikosongkan dengan sengaja dan setelah form berhasil
     * di-commit: draft yang dibatalkan tidak akan ditawarkan lagi.
     */
    public function cancel(?string $deviceId = null): void
    {
        $draft = $this->getActive($deviceId);

        if ($draft === null) {
            return;
        }

        $draft->status = 'CANCELLED';
        $draft->cancelled_at = now();
        $draft->save();
    }
}
