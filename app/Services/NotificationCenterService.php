<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OpnameStatus;
use App\Models\Opname;
use App\Models\User;
use App\Services\Report\DashboardService;

/**
 * Item-item yang tampil di pusat notifikasi topbar.
 *
 * Satu item agregat per sumber, dibangun dari angka yang sama dengan yang
 * dipakai halaman bersangkutan -- maka badge dan panel tidak bisa berbeda dari
 * dashboard. Tidak ada tabel sendiri: item dihitung live dari sumber yang ada,
 * dan sumber baru cukup menambah satu builder di sini. Bila nanti diperlukan
 * alur persetujuan yang memakai read-state, `id` di tiap item menjadi tempat
 * duduk untuk snapshot yang disimpan.
 */
final class NotificationCenterService
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    /**
     * @return array{count: int, items: list<array{id: string, tone: string, title: string, message: string, href: string}>}
     */
    public function items(?User $viewer): array
    {
        // Semua item mengarah ke modul non-POS -- persetujuan opname, karantina,
        // dan label -- yang kini Owner-only. Menampilkannya ke Staff hanya
        // menghasilkan tautan menuju halaman 403, jadi bukan-Owner menerima
        // pusat notifikasi kosong.
        if (! $viewer?->isOwner()) {
            return ['count' => 0, 'items' => []];
        }

        $items = [];

        $this->pushWhenNonEmpty($items, $this->pendingApprovalOpnames());

        $quarantine = $this->dashboard->quarantineSummary();

        $this->pushWhenNonEmpty($items, $this->openQuarantine($quarantine['count'], $quarantine['oldestDays']));

        $this->pushWhenNonEmpty($items, $this->unconfirmedLabels($this->dashboard->unconfirmedLabels()));

        return [
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * @param  list<array{id: string, tone: string, title: string, message: string, href: string}>  $items
     * @param  array{id: string, tone: string, title: string, message: string, href: string}|null  $item
     */
    private function pushWhenNonEmpty(array &$items, ?array $item): void
    {
        if ($item !== null) {
            $items[] = $item;
        }
    }

    /**
     * @return array{id: string, tone: string, title: string, message: string, href: string}|null
     */
    private function pendingApprovalOpnames(): ?array
    {
        $count = Opname::query()->where('status', OpnameStatus::PendingApproval)->count();

        if ($count === 0) {
            return null;
        }

        return [
            'id' => 'opname-pending-approval',
            'tone' => 'warning',
            'title' => 'Persetujuan Opname',
            'message' => $count.' sesi opname menunggu persetujuan kamu',
            'href' => route('inventory.stok-opname'),
        ];
    }

    /**
     * @return array{id: string, tone: string, title: string, message: string, href: string}|null
     */
    private function openQuarantine(int $count, int $oldestDays): ?array
    {
        if ($count === 0) {
            return null;
        }

        $message = $count.' kasus karantina menunggu verifikasi';

        if ($oldestDays > 0) {
            $message .= ' · tertua '.$oldestDays.' hari';
        }

        return [
            'id' => 'karantina',
            'tone' => 'error',
            'title' => 'Kasus Karantina',
            'message' => $message,
            'href' => route('inventory.karantina'),
        ];
    }

    /**
     * @return array{id: string, tone: string, title: string, message: string, href: string}|null
     */
    private function unconfirmedLabels(int $count): ?array
    {
        if ($count === 0) {
            return null;
        }

        return [
            'id' => 'unconfirmed-labels',
            'tone' => 'warning',
            'title' => 'Label Belum Dikonfirmasi',
            'message' => $count.' label menunggu konfirmasi cetak',
            'href' => route('inbound.cetak-label'),
        ];
    }
}
