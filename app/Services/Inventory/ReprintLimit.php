<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\LabelReason;
use App\Enums\LabelStatus;
use App\Models\LabelPrintJob;
use App\Models\StockLot;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Batas jumlah label untuk cetak ulang (FR-IB-22 + matriks peran).
 *
 * Ada dua batas, dan keduanya sengaja dipisah karena answered question yang
 * berbeda:
 *
 * 1. **Jumlah label vs barang yang diterima.** Ini yang menghentikan "stiker
 *    liar": label yang lebih banyak dari unit berarti ada barang tanpa label
 *    atau ada label untuk barang yang tidak pernah masuk. Yang dibandingkan
 *    bukan hanya `labels_printed`, tapi juga cetakan yang masih berjalan
 *    (`QUEUED`/`SENT`) -- kalau tidak, dua operator yang menekan tombol
 *    re-print berurutan pada lot yang sama akan dua-duanya lolos, karena yang
 *    kedua tidak melihat cetakan milik yang pertama.
 *
 * 2. **Jatah cetak ulang harian per SKU.** Ini bukan pengaman integritas data,
 *    melainkan rem terhadap oper yang mengulang-ulang tanpa progressing: label
 *    sobek, cetak ulang, sobek lagi. Dihitung dari jumlah *percobaan* (job),
 *    bukan jumlah label, karena job `ADDITIONAL_UNITS` 200 eksemplar tetap satu
 *    percobaan; menghitung label akan membuat satu permintaan besar langsung
 *    menghabiskan jatah seharian.
 *
 * Dihitung di satu tempat, dan hasilnya dipakai dua kali: FormRequest
 * menentukan apakah form harus membawa token PIN, dan `LabelPrintService`
 * memeriksanya lagi di dalam transaksi. Pemeriksaan kedua bukan paranoia --
 * angka di form bisa sudah basi ketika operator sedang mengetik PIN-nya.
 */
class ReprintLimit
{
    /**
     * Jatah cetak ulang harian untuk Staff, per lot.
     *
     * 3 sesuai matriks peran. Batas ini tidak dihitung ulang di tempat lain,
     * jadi mengubahnya di sini mengubah perilaku di request, service, dan
     * pesan yang dibacakan ke petugas sekaligus.
     */
    public const int DAILY_STAFF_LIMIT = 3;

    /**
     * Lot yang masih berjalan: sudah dibuat, belum keluar dari printer, dan
     * belum dinyatakan gagal.
     *
     * `FAILED` tidak dihitung karena job itu memang tidak menghasilkan label --
     * retry memakai job yang sama (lihat `LabelPrintService::retry`), jadi
     * tidak ada risiko menghitungnya dua kali.
     */
    private const IN_FLIGHT = [
        LabelStatus::Queued->value,
        LabelStatus::Sent->value,
    ];

    /**
     * @param  Collection<int, StockLot>  $lots
     */
    public function evaluate(Collection $lots, int $copies, ?User $actor): ReprintVerdict
    {
        if ($lots->isEmpty()) {
            return new ReprintVerdict;
        }

        $inFlight = $this->inFlightCopies($lots);
        $reprintsToday = $this->reprintsToday($lots);

        $overQty = [];
        $overDaily = [];

        foreach ($lots as $lot) {
            $used = (int) $lot->labels_printed + $inFlight[$lot->id];
            $allowed = (int) $lot->qty_received - $used;

            if ($copies > $allowed) {
                $overQty[] = [
                    'lot' => $lot,
                    'used' => $used,
                    'requested' => $copies,
                    'allowed' => $allowed,
                ];
            }

            $today = $reprintsToday[$lot->id];

            if ($today >= self::DAILY_STAFF_LIMIT) {
                $overDaily[] = [
                    'lot' => $lot,
                    'today' => $today,
                    'allowed' => self::DAILY_STAFF_LIMIT,
                ];
            }
        }

        return new ReprintVerdict($overQty, $overDaily, (bool) $actor?->isOwner());
    }

    /**
     * Jumlah label yang sudah dialokasikan tapi belum keluar, per lot.
     *
     * @param  Collection<int, StockLot>  $lots
     * @return array<int, int>
     */
    private function inFlightCopies(Collection $lots): array
    {
        return $this->perLot(
            $lots,
            LabelPrintJob::query()
                ->whereIn('lot_id', $lots->modelKeys())
                ->whereIn('status', self::IN_FLIGHT),
            'SUM(copies)',
        );
    }

    /**
     * Jumlah job cetak ulang yang dibuat hari ini, per lot.
     *
     * Job beralasan `INITIAL` tidak dihitung: itu label pertama yang dibuat
     * otomatis saat barang masuk, bukan cetak ulang yang dicoba oper.
     *
     * @param  Collection<int, StockLot>  $lots
     * @return array<int, int>
     */
    private function reprintsToday(Collection $lots): array
    {
        return $this->perLot(
            $lots,
            LabelPrintJob::query()
                ->whereIn('lot_id', $lots->modelKeys())
                ->whereIn('reason', LabelReason::reprints())
                ->where('created_at', '>=', now()->startOfDay()),
            'COUNT(*)',
        );
    }

    /**
     * @param  Collection<int, StockLot>  $lots
     * @return array<int, int>
     */
    private function perLot(Collection $lots, Builder $query, string $aggregate): array
    {
        if ($lots->isEmpty()) {
            return [];
        }

        $totals = $query
            ->selectRaw("lot_id, {$aggregate} AS total")
            ->groupBy('lot_id')
            ->pluck('total', 'lot_id');

        $byLot = [];

        foreach ($lots as $lot) {
            $byLot[$lot->id] = (int) ($totals[$lot->id] ?? 0);
        }

        return $byLot;
    }
}
