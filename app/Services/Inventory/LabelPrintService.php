<?php

namespace App\Services\Inventory;

use App\Enums\ConsignmentStatus;
use App\Enums\LabelReason;
use App\Enums\LabelStatus;
use App\Models\Consignment;
use App\Models\LabelPrintJob;
use App\Models\StockLot;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Label\LabelPrinterSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * State machine label print.
 *
 * Semua perpindahan status lewat sini, bukan lewat controller, supaya
 * aturannya -- kapan `labels_printed` naik, kapan job boleh diulang -- hanya
 * ada di satu tempat.
 *
 * Alur normal:
 *
 *     QUEUED --dicetak--> SENT --dikonfirmasi--> CONFIRMED
 *        ^                 |
 *        |                 v
 *      (retry)          FAILED
 *
 * Yang paling penting dari alur ini: `labels_printed` naik saat CONFIRMED,
 * bukan saat SENT. Printer thermal tidak memberi umpan balik apa pun -- kertas
 * bisa habis di tengah, head bisa panas, dan operator bisa kelepet menekan
 * tombol cetak untuk halaman yang salah. Kalau counter naik begitu operator
 * menekan "sudah dicetak", angka lot jadi lebih besar dari label yang benar-
 * benar menempel pada unit, dan konsignment dianggap selesai padahal ada unit
 * tanpa label.
 */
class LabelPrintService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ReprintLimit $limits,
        private readonly LabelPrinterSettings $printer,
    ) {}

    /**
     * Operator menekan "sudah dicetak": job dikirim ke printer.
     *
     * Belum ada yang terukur di sini. SENT berarti "sudah dikirim ke printer",
     * bukan "label sudah jadi".
     */
    public function markSent(Collection $ids): int
    {
        return $this->transition(
            ids: $ids,
            from: LabelStatus::Queued,
            to: LabelStatus::Sent,
            action: 'PRINT_LABELS',
            attributes: fn (LabelPrintJob $job) => [
                'status' => LabelStatus::Sent->value,
                'printed_at' => now(),
            ],
        );
    }

    /**
     * Operator melihat labelnya keluar dari printer dan menyentuhnya: status
     * CONFIRMED, dan hanya di sinilah `labels_printed` bertambah.
     */
    public function confirm(Collection $ids): int
    {
        $confirmed = $this->transition(
            ids: $ids,
            from: LabelStatus::Sent,
            to: LabelStatus::Confirmed,
            action: 'CONFIRM_LABELS',
            attributes: fn (LabelPrintJob $job) => [
                'status' => LabelStatus::Confirmed->value,
                'confirmed_at' => now(),
            ],
            onChanged: fn (LabelPrintJob $job) => $job->lot()->increment('labels_printed', $job->copies),
        );

        if ($confirmed > 0) {
            $this->completeFullyLabelledConsignments(
                LabelPrintJob::query()
                    ->whereKey($ids)
                    ->join('stock_lots', 'stock_lots.id', '=', 'label_print_jobs.lot_id')
                    ->pluck('stock_lots.consignment_id')
                    ->filter()
                    ->unique()
            );
        }

        return $confirmed;
    }

    /**
     * Cetak gagal: kertas habis, head panas, atau operator menekan cancel.
     *
     * Job tidak dihapus dan `labels_printed` tidak bertambah, supaya operator
     * bisa mencoba ulang dari daftar yang sama.
     */
    public function markFailed(Collection $ids, string $message): int
    {
        $message = trim($message);

        return $this->transition(
            ids: $ids,
            from: LabelStatus::Sent,
            to: LabelStatus::Failed,
            action: 'FAIL_LABELS',
            attributes: fn (LabelPrintJob $job) => [
                'status' => LabelStatus::Failed->value,
                'error_message' => $message,
                'failed_at' => now(),
            ],
            reason: $message,
        );
    }

    /**
     * Kembalikan job yang gagal ke antrean supaya bisa dicetak lagi.
     *
     * `payload` sengaja tidak dihapus. Isi label yang sudah dibekukan saat
     * render pertama harus dipakai ulang -- kalau tidak, re-print karena
     * `PRICE_CHANGE` akan menghasilkan label dengan harga yang sama seperti
     * cetakan pertama, yaitu alasan kenapa alasan itu ada.
     */
    public function retry(Collection $ids): int
    {
        return $this->transition(
            ids: $ids,
            from: LabelStatus::Failed,
            to: LabelStatus::Queued,
            action: 'RETRY_LABELS',
            attributes: fn (LabelPrintJob $job) => [
                'status' => LabelStatus::Queued->value,
                'error_message' => null,
                'failed_at' => null,
                'printed_at' => null,
            ],
        );
    }

    /**
     * Buat job cetak ULANG untuk sekumpulan lot.
     *
     * Job baru, bukan job lama yang dihidupkan kembali. Job lama yang sudah
     * CONFIRMED adalah bukti bahwa label pernah keluar, dan `labels_printed`
     * untuk label itu sudah dihitung. Menghidupkannya lagi berarti satu label
     * dihitung dua kali.
     *
     * `payload` juga TIDAK disalin dari job lama. Isi label dibaca lagi dari
     * lot, jadi re-print karena `PRICE_CHANGE` benar-benar menghasilkan harga
     * baru. Kalau payload lama ikut disalin, alasan `PRICE_CHANGE` akan
     * menghasilkan label dengan harga yang sama seperti cetakan pertama.
     *
     * @param  array<int, int>  $lotIds
     * @param  int|null  $approvedBy  Owner yang menotorisasi cetakan yang melewati
     *                                batas (FR-AUTH-01). `null` berarti tidak ada
     *                                yang perlu dinotorisasi.
     * @return int jumlah job yang dibuat
     *
     * @throws ReprintLimitExceeded
     */
    public function reprint(
        array $lotIds,
        int $copies,
        LabelReason $reason,
        ?int $userId,
        ?int $approvedBy = null,
    ): int {
        return DB::transaction(function () use ($lotIds, $copies, $reason, $userId, $approvedBy): int {
            // Lot dibaca ulang DI DALAM transaksi dan dikunci. Kalau barisnya
            // dibaca di luar, dua operator yang menekan tombol hampir
            // bersamaan bisa dua-duanya melihat angka lama, dua-duanya lolos
            // batas, lalu dua job dibuat untuk slot yang sama -- persis yang
            // dicegah FR-IB-22.
            $lots = StockLot::query()
                ->whereKey($lotIds)
                ->lockForUpdate()
                ->get();

            if ($lots->isEmpty()) {
                return 0;
            }

            $actor = $userId === null ? null : User::query()->find($userId);

            // Diperiksa lagi di sini, bukan cuma di FormRequest. Angka yang
            // dipakai request sudah bisa basi: antara operator menekan tombol
            // dan Owner mengetik PIN-nya, label lot yang sama bisa saja
            // keluar dari printer sehingga `labels_printed`-nya sudah naik.
            $verdict = $this->limits->evaluate($lots, $copies, $actor);

            if ($verdict->needsOwnerPin() && $approvedBy === null) {
                throw new ReprintLimitExceeded($verdict);
            }

            $breached = $this->breachedLotIds($verdict);
            $approver = $approvedBy ?? ($verdict->isBreached() ? $actor?->getKey() : null);
            $created = 0;

            foreach ($lots as $lot) {
                $isBreached = isset($breached[$lot->id]);

                $job = LabelPrintJob::create([
                    'lot_id' => $lot->id,
                    'copies' => $copies,
                    'reason' => $reason,
                    'template' => $this->printer->defaultTemplate(),
                    'show_price' => true,
                    'status' => LabelStatus::Queued,
                    'requested_by' => $userId,
                    // Hanya diisi kalau ada yang perlu dinotorisasi. Job yang
                    // normal dibiarkan `null` supaya "ada override" dan "tidak
                    // ada override" tidak tercampur di laporan.
                    'approved_by' => $isBreached ? $approver : null,
                ]);

                // Dihitung saat job DIBUAT, bukan saat label keluar: yang
                // dihitung adalah berapa kali label dicetak ulang, dan job
                // yang dibuat lalu dibatalkan operator tetap berarti percetakan
                // dicoba. Menunggu label konfirmasi akan membuat pola cetakan
                // berulang tidak terlihat -- justru pola itu yang dicari.
                $lot->increment('reprint_count', $copies);

                $this->audit->log(
                    action: 'REPRINT_LABELS',
                    entity: 'LabelPrintJob',
                    entityId: $job->id,
                    before: ['reprint_count' => $lot->reprint_count - $copies],
                    after: ['reprint_count' => $lot->reprint_count],
                    reason: $reason->value,
                );

                // Anomali dicatat terpisah dari `REPRINT_LABELS`, karena
                // FR-RP-22 meminta laporan anomali bisa menyaring "cetak
                // melebihi qty" tanpa ikut menghitung semua cetakan biasa.
                if ($isBreached) {
                    $this->auditBreach($verdict, $lot, $copies, $reason, $approver);
                }

                $created++;
            }

            return $created;
        });
    }

    /**
     * Id lot yang ikut melewati batas, untuk apa pun batas yang terlampaui.
     *
     * @return array<int, true>
     */
    private function breachedLotIds(ReprintVerdict $verdict): array
    {
        $ids = [];

        foreach (array_merge($verdict->overQty, $verdict->overDaily) as $breach) {
            $ids[$breach['lot']->id] = true;
        }

        return $ids;
    }

    /**
     * Catat satu lot yang cetakannya melewati batas.
     *
     * Nama Owner yang menotorisasi ikut ditulis di dalam `after`, karena
     * `audit_logs.user_id` adalah petugas yang menekan tombol -- dan yang
     * perlu ditelusuri FR-AUTH-01 justru siapa yang mengizinkan.
     */
    private function auditBreach(
        ReprintVerdict $verdict,
        StockLot $lot,
        int $copies,
        LabelReason $reason,
        mixed $approver,
    ): void {
        $overQty = collect($verdict->overQty)->firstWhere('lot.id', $lot->id);

        foreach ($verdict->breachActions() as $action) {
            $this->audit->log(
                action: $action,
                entity: 'StockLot',
                entityId: $lot->id,
                after: array_filter([
                    'copies' => $copies,
                    'labels_printed' => $lot->labels_printed,
                    'qty_received' => $lot->qty_received,
                    'in_flight' => $overQty['used'] ?? null,
                    'approved_by' => $approver,
                ], static fn ($value): bool => $value !== null),
                reason: $reason->value,
            );
        }
    }

    /**
     * Jalankan satu perpindahan status untuk sekumpulan job.
     *
     * Baris yang sudah diproses operator lain dilewati, bukan di-error. Dua
     * operator bisa menekan tombol konfirmasi untuk job yang sama, dan yang
     * kedua akan gagal hanya karena yang pertama lebih dulu selesai. Kalau itu
     * di-error, operator diberi kesan ada masalah padahal tidak ada.
     */
    private function transition(
        Collection $ids,
        LabelStatus $from,
        LabelStatus $to,
        string $action,
        \Closure $attributes,
        ?\Closure $onChanged = null,
        ?string $reason = null,
    ): int {
        return DB::transaction(function () use ($ids, $from, $to, $action, $attributes, $onChanged, $reason): int {
            $changed = 0;

            foreach ($ids as $id) {
                // `lockForUpdate` menahan baris sampai transaksi selesai, jadi
                // dua operator yang menekan tombol bersamaan tidak bisa
                // sama-sama membaca status lama lalu keduanya mengubah baris
                // yang sama -- yang kedua akan menaikkan `labels_printed` untuk
                // label yang sudah dihitung sekali.
                $job = LabelPrintJob::query()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->first();

                // `status` di-cast jadi enum, jadi harus dibandingkan dengan
                // enum. Perbandingan dengan `->value` akan selalu benar
                // (enum tidak pernah `===` string), sehingga semua job
                // dilewati tanpa pernah berubah.
                if ($job === null || $job->status !== $from) {
                    continue;
                }

                $job->forceFill($attributes($job));
                $job->save();

                $onChanged?->__invoke($job);

                $this->audit->log(
                    action: $action,
                    entity: 'LabelPrintJob',
                    entityId: $job->id,
                    before: ['status' => $from->value],
                    after: ['status' => $to->value],
                    reason: $reason,
                );

                $changed++;
            }

            return $changed;
        });
    }

    /**
     * Tutup konsignment yang semua labelnya sudah terkonfirmasi.
     *
     * Konsignment dibaca lewat `stock_lots.consignment_id`, bukan kolom salinan
     * di `label_print_jobs`. Kolom salinan bisa melenceng dari lot aslinya dan
     * membuat konsignment tertutup terlalu dini atau tidak pernah tertutup.
     */
    private function completeFullyLabelledConsignments(Collection $consignmentIds): void
    {
        foreach ($consignmentIds as $consignmentId) {
            $outstanding = LabelPrintJob::query()
                ->whereIn('lot_id', $this->lotsOfConsignment($consignmentId))
                ->where('status', '!=', LabelStatus::Confirmed->value)
                ->exists();

            if ($outstanding) {
                continue;
            }

            Consignment::query()
                ->whereKey($consignmentId)
                ->where('status', ConsignmentStatus::Committed->value)
                ->update(['status' => ConsignmentStatus::Completed->value]);
        }
    }

    /**
     * Subquery id lot milik satu konsignment.
     *
     * Dipakai sebagai subquery, bukan eager load, karena yang dibutuhkan hanya
     * "masih ada yang belum terkonfirmasi" -- bukan isi lotnya.
     */
    private function lotsOfConsignment(int $consignmentId): Builder
    {
        return DB::table('stock_lots')
            ->where('consignment_id', $consignmentId)
            ->select('id');
    }
}
