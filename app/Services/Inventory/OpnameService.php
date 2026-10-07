<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\AdjustmentReason;
use App\Enums\AuditAction;
use App\Enums\LotStatus;
use App\Enums\MovementType;
use App\Enums\OpnameLineStatus;
use App\Enums\OpnameScope;
use App\Enums\OpnameStatus;
use App\Models\Opname;
use App\Models\OpnameLine;
use App\Models\Rack;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\DeviceId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Siklus opname: mulai → hitung → ajukan → putuskan (FR-IC-20..23).
 *
 * Tiga aturan menopang seluruh class ini:
 *
 * 1. **Blind.** Selama sesi menghitung, angka sistem tidak pernah keluar dari
 *    service -- bukan disembunyikan di template, melainkan tidak dihitung
 *    sama sekali di mana pun yang bisa dibaca Staff. Yang keluar dari `count()`
 *    hanyalah baris yang sudah diperbarui.
 * 2. **Selisih selalu relatif terhadap t0.** `system_qty` adalah snapshot pada
 *    saat sesi dimulai; angka yang dibandingkan dengan hitungan fisik adalah
 *    `system_qty` ditambah gerakan yang terjadi sesudahnya (FR-IC-22), bukan
 *    `qty_on_hand` yang bisa saja sudah berubah di tengah sesi.
 * 3. **Angka basi tidak boleh dipakai.** Baris membawa tanda air
 *    `stock_movements.id` pada saat ia dihitung. Stok yang bergerak setelah
 *    tanda air itu membuat hitungannya sudah tidak menggambarkan isi rak, jadi
 *    sesi ditolak saat diajukan dan baris ditolak saat disetujui, keduanya
 *    dengan pesan yang menyuruh menghitung ulang -- bukan diam-diam memakai
 *    angka yang sudah basi.
 *
 * Tanda air memakai id, bukan timestamp. `stock_movements.created_at` hanya
 * sedetik: gerakan yang jatuh pada detik yang sama dengan `started_at` bisa
 * salah masuk ke sisi mana pun dengan sekat berbasis waktu, dan kesalahan itu
 * tidak terlihat di layar mana pun sampai stoknya meleset.
 */
final class OpnameService
{
    /**
     * Percobaan ulang transaksi bila terjadi deadlock antar-kasir.
     */
    private const int TX_ATTEMPTS = 5;

    /**
     * Batas atas hitungan fisik satu baris.
     *
     * Angka ini bukan batas stok yang nyata -- rak berisi ratusan unit memang
     * mungkin -- melainkan penjaring salah ketik: kolomnya `unsignedInteger`
     * dan angka sembilan digit penuh berasal dari jari yang menahan tombol,
     * bukan dari penghitungan.
     */
    public const int MAX_COUNTED_QTY = 99_999;

    /**
     * Alasan yang masuk akal untuk selisih kurang dan selisih lebih.
     *
     * Diperiksa di sini, bukan hanya di form, karena alasan itu menjadi bagian
     * dari gerakan stok yang akan dibaca orang selamanya: "unit hilang" untuk
     * kenaikan stok dan "unit ditemukan" untuk kehilangan membuat catatan yang
     * menyalah sendiri.
     */
    public const array MINUS_REASONS = [AdjustmentReason::Lost, AdjustmentReason::Damaged, AdjustmentReason::Miscount];

    public const array PLUS_REASONS = [AdjustmentReason::Found, AdjustmentReason::Miscount];

    public function __construct(
        private readonly OpnameNoService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Mulai satu sesi: nomor, snapshot, dan baris-barisnya dibuat sekali jalan.
     *
     * @param  Rack|null  $rack  wajib bila cakupannya RACK
     * @param  string|null  $sku  wajib bila cakupannya SKU
     *
     * @throws ValidationException
     */
    public function start(OpnameScope $scope, ?Rack $rack = null, ?string $sku = null, ?User $actor = null): Opname
    {
        $this->guardNoOpenSession();
        $this->guardScopeArguments($scope, $rack, $sku);

        // Baca baris di dalam transaksi, bukan sebelumnya: snapshot t0 hanya
        // berarti bila baris yang dibaca dan qty yang disimpan berasal dari
        // detik yang sama dengan pembuatan sesinya.
        return DB::transaction(function () use ($scope, $rack, $sku, $actor): Opname {
            // Dibaca ulang, diperiksa ulang: sesi kedua yang sama-sama lolos
            // pengecekan di atas harus kalah di sini, bukan berjalan berdua.
            $this->guardNoOpenSession();

            $lots = $this->lotsInScope($scope, $rack, $sku)->get();

            if ($lots->isEmpty()) {
                throw ValidationException::withMessages([
                    'scope' => 'Tidak ada lot pada cakupan ini. Periksa rak atau SKU yang dipilih.',
                ]);
            }

            $opname = Opname::create([
                'opname_no' => $this->numbers->next(),
                'scope' => $scope,
                'rack_id' => $rack?->id,
                'scope_value' => $scope === OpnameScope::Sku ? $sku : null,
                'status' => OpnameStatus::Counting,
                'started_at' => now(),
                'movement_from_id' => $this->lastMovementId(),
                'created_by' => $actor?->id,
            ]);

            foreach ($lots as $lot) {
                OpnameLine::create([
                    'opname_id' => $opname->id,
                    'lot_id' => $lot->id,
                    // Snapshot t0 (FR-IC-20). Angka inilah yang kelak ditambah
                    // gerakan sesudah t0 untuk menghasilkan ekspektasi baris.
                    'system_qty' => $lot->qty_on_hand,
                    'status' => OpnameLineStatus::Pending,
                ]);
            }

            $this->audit->log(
                AuditAction::OpnameStart->value,
                Opname::class,
                (int) $opname->id,
                [],
                [
                    'opname_no' => $opname->opname_no,
                    'scope' => $scope->value,
                    'lines' => $lots->count(),
                ],
                null,
            );

            return $opname;
        }, self::TX_ATTEMPTS);
    }

    /**
     * Catat hitungan fisik satu baris.
     *
     * @throws ValidationException
     */
    public function count(Opname $opname, OpnameLine $line, int $qty, ?User $actor = null): OpnameLine
    {
        if ($qty < 0 || $qty > self::MAX_COUNTED_QTY) {
            throw ValidationException::withMessages([
                'counted_qty' => 'Jumlah terhitung harus antara 0 dan '.self::MAX_COUNTED_QTY.'.',
            ]);
        }

        return DB::transaction(function () use ($opname, $line, $qty, $actor): OpnameLine {
            $fresh = OpnameLine::query()->lockForUpdate()->findOrFail($line->getKey());

            // Dibaca ulang, diperiksa ulang: di antara permintaan ini dan baris
            // di atas, sesi bisa saja sudah diajukan oleh orang lain.
            $session = Opname::query()->lockForUpdate()->findOrFail($opname->getKey());

            $this->guardCountable($session, $fresh);

            $lot = StockLot::query()->lockForUpdate()->findOrFail($fresh->lot_id);

            $expected = $this->expectedQty($session, $fresh);

            // Ledger dan stok wajib sepakat. Bila tidak, angka yang akan
            // dibandingkan dengan hitungan fisik bukan angka yang dipakai POS
            // saat menjual, dan menghitung di atasnya hanya menumpuk selisih
            // yang salah sasangan.
            if ($expected !== $lot->qty_on_hand) {
                throw ValidationException::withMessages([
                    'counted_qty' => sprintf(
                        'Pergerakan stok lot %s tidak tercatat lengkap (ekspektasi %d, stok sistem %d). Hubungi Owner sebelum menghitung baris ini.',
                        $lot->sku,
                        $expected,
                        $lot->qty_on_hand,
                    ),
                ]);
            }

            $diff = $qty - $expected;

            $fresh->update([
                'counted_qty' => $qty,
                'diff_qty' => $diff,
                'counted_at' => now(),
                // Tanda air per lot: gerakan dengan id lebih besar dari ini
                // berarti stok berubah setelah angka ini dimasukkan.
                'counted_movement_id' => $this->lastMovementId($lot),
                'counted_by' => $actor?->id,
                'status' => $diff === 0 ? OpnameLineStatus::Ok : OpnameLineStatus::Counted,
                // Menghitung ulang menghapus keputusan lama: alasan dan
                // persetujuan yang melekat pada angka sebelumnya tidak lagi
                // menjelaskan angka yang sekarang.
                'reason' => null,
                'approved_by' => null,
            ]);

            return $fresh->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * Ajukan sesi ke Owner: semua baris wajib sudah dihitung dan tidak ada
     * yang basi (FR-IC-21..22, dan keputusan sesi bahwa submit menutup pintu
     * penghitungan).
     *
     * @throws ValidationException
     */
    public function submit(Opname $opname, ?User $actor = null): Opname
    {
        return DB::transaction(function () use ($opname): Opname {
            $fresh = Opname::query()->lockForUpdate()->findOrFail($opname->getKey());

            if ($fresh->status !== OpnameStatus::Counting) {
                throw ValidationException::withMessages([
                    'opname' => 'Sesi ini sudah berstatus '.$fresh->status->label().'.',
                ]);
            }

            $lines = $fresh->lines()->with('lot')->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'opname' => 'Sesi ini tidak berisi baris apa pun.',
                ]);
            }

            $uncounted = $lines->filter(fn (OpnameLine $line) => $line->counted_qty === null);

            if ($uncounted->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'opname' => sprintf(
                        '%d baris belum dihitung: %s.',
                        $uncounted->count(),
                        $this->skuList($uncounted),
                    ),
                ]);
            }

            $stale = $lines->filter(fn (OpnameLine $line) => $this->isStale($line));

            if ($stale->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'opname' => sprintf(
                        'Stok bergerak setelah dihitung, hitung ulang: %s.',
                        $this->skuList($stale),
                    ),
                ]);
            }

            $fresh->update([
                'status' => OpnameStatus::PendingApproval,
                'submitted_at' => now(),
            ]);

            $this->audit->log(
                AuditAction::OpnameSubmit->value,
                Opname::class,
                (int) $fresh->id,
                ['status' => OpnameStatus::Counting->value],
                ['status' => OpnameStatus::PendingApproval->value],
                null,
            );

            return $fresh->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * Putuskan satu baris selisih: terapkan ke stok, atau tolak tanpa menyentuh
     * stok (FR-IC-23).
     *
     * @param  string  $decision  `APPROVE` atau `REJECT`
     * @param  User|null  $approver  Owner yang memberi otorisasi; bila null,
     *                               pemanggil sendiri yang Owner
     *
     * @throws ValidationException
     */
    public function review(Opname $opname, OpnameLine $line, string $decision, ?AdjustmentReason $reason, ?User $approver = null): OpnameLine
    {
        return DB::transaction(function () use ($opname, $line, $decision, $reason, $approver): OpnameLine {
            $session = Opname::query()->lockForUpdate()->findOrFail($opname->getKey());
            $fresh = OpnameLine::query()->with('lot')->lockForUpdate()->findOrFail($line->getKey());

            if ($session->status !== OpnameStatus::PendingApproval) {
                throw ValidationException::withMessages([
                    'opname' => 'Sesi ini sudah berstatus '.$session->status->label().'; baris tidak bisa diputuskan lagi.',
                ]);
            }

            if ($fresh->status !== OpnameLineStatus::Counted) {
                throw ValidationException::withMessages([
                    'opname' => 'Baris ini tidak menunggu persetujuan (status: '.$fresh->status->label().').',
                ]);
            }

            if ($this->isStale($fresh)) {
                throw ValidationException::withMessages([
                    'opname' => sprintf(
                        'Stok lot %s bergerak setelah baris ini dihitung. Hitung ulang baris tersebut sebelum memutuskannya.',
                        $fresh->lot?->sku ?? $fresh->lot_id,
                    ),
                ]);
            }

            if ($decision === 'REJECT') {
                // Menolak berarti: sistem tetap seperti adanya, hitungan fisik
                // tidak dipakai. Tidak ada alasan yang melekat, karena alasan
                // hanya relevan untuk perubahan yang benar-benar diterapkan.
                $fresh->update([
                    'status' => OpnameLineStatus::Rejected,
                    'approved_by' => $approver?->id,
                    'reason' => null,
                ]);

                $this->audit->log(
                    AuditAction::OpnameReject->value,
                    OpnameLine::class,
                    (int) $fresh->id,
                    ['status' => OpnameLineStatus::Counted->value, 'diff_qty' => $fresh->diff_qty],
                    ['status' => OpnameLineStatus::Rejected->value, 'diff_qty' => $fresh->diff_qty],
                    $fresh->lot?->sku,
                );

                return $this->finalize($session, $fresh);
            }

            $this->guardReasonMatchesDiff($fresh, $reason);

            $lot = StockLot::query()->lockForUpdate()->findOrFail($fresh->lot_id);
            $delta = (int) $fresh->diff_qty;
            $newQty = $lot->qty_on_hand + $delta;

            if ($newQty < 0) {
                throw ValidationException::withMessages([
                    'opname' => sprintf(
                        'Persetujuan akan membuat stok lot %s menjadi negatif. Hitung ulang baris ini.',
                        $lot->sku,
                    ),
                ]);
            }

            $lot->update([
                'qty_on_hand' => $newQty,
                'status' => $this->statusAfterAdjustment($lot, $newQty),
            ]);

            StockMovement::create([
                'lot_id' => $lot->id,
                'type' => $delta > 0 ? MovementType::AdjPlus : MovementType::AdjMinus,
                'qty_delta' => $delta,
                'ref_type' => Opname::class,
                'ref_id' => $session->id,
                'actor_id' => $approver?->id,
                'device_id' => DeviceId::current(),
                'reason' => $reason?->value,
                'balance_after' => $newQty,
            ]);

            $fresh->update([
                'status' => OpnameLineStatus::Approved,
                'approved_by' => $approver?->id,
                'reason' => $reason,
            ]);

            $this->audit->log(
                AuditAction::OpnameApprove->value,
                OpnameLine::class,
                (int) $fresh->id,
                ['status' => OpnameLineStatus::Counted->value, 'qty_on_hand' => $lot->qty_on_hand - $delta, 'diff_qty' => $delta],
                ['status' => OpnameLineStatus::Approved->value, 'qty_on_hand' => $newQty, 'diff_qty' => $delta],
                sprintf('%s · selisih %d · %s', $fresh->lot?->sku ?? ('#'.$fresh->lot_id), $delta, $reason->value),
            );

            return $this->finalize($session, $fresh);
        }, self::TX_ATTEMPTS);
    }

    /**
     * Batalkan sesi yang masih menghitung: barisnya dibuang beserta angka-angka
     * yang belum pernah menjadi keputusan siapa pun.
     *
     * @throws ValidationException
     */
    public function cancel(Opname $opname, ?User $actor = null): Opname
    {
        return DB::transaction(function () use ($opname): Opname {
            $fresh = Opname::query()->lockForUpdate()->findOrFail($opname->getKey());

            if ($fresh->status !== OpnameStatus::Counting) {
                throw ValidationException::withMessages([
                    'opname' => 'Hanya sesi yang sedang menghitung yang bisa dibatalkan. Sesi ini berstatus '.$fresh->status->label().'.',
                ]);
            }

            $fresh->update(['status' => OpnameStatus::Cancelled]);

            $this->audit->log(
                AuditAction::OpnameCancel->value,
                Opname::class,
                (int) $fresh->id,
                ['status' => OpnameStatus::Counting->value],
                ['status' => OpnameStatus::Cancelled->value],
                null,
            );

            return $fresh->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * Angka sistem suatu baris pada saat ini: snapshot t0 ditambah gerakan
     * sesudah t0 (FR-IC-22).
     */
    private function expectedQty(Opname $opname, OpnameLine $line): int
    {
        $moved = (int) StockMovement::query()
            ->where('lot_id', $line->lot_id)
            ->where('id', '>', (int) $opname->movement_from_id)
            ->sum('qty_delta');

        return (int) $line->system_qty + $moved;
    }

    /**
     * Apakah hitungan baris ini sudah basi: ada gerakan stok yang terjadi
     * setelah angkanya dimasukkan.
     */
    private function isStale(OpnameLine $line): bool
    {
        if ($line->counted_movement_id === null || $line->counted_at === null) {
            return false;
        }

        return StockMovement::query()
            ->where('lot_id', $line->lot_id)
            ->where('id', '>', $line->counted_movement_id)
            ->exists();
    }

    private function guardNoOpenSession(): void
    {
        $open = Opname::query()
            ->whereIn('status', OpnameStatus::openValues())
            ->first();

        if ($open !== null) {
            throw ValidationException::withMessages([
                'scope' => 'Masih ada sesi opname terbuka ('.$open->opname_no.' · '.$open->status->label().'). Selesaikan atau batalkan sesi itu lebih dulu.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function guardScopeArguments(OpnameScope $scope, ?Rack $rack, ?string $sku): void
    {
        if ($scope === OpnameScope::Rack && $rack === null) {
            throw ValidationException::withMessages(['rack_id' => 'Pilih rak yang akan dihitung.']);
        }

        if ($scope === OpnameScope::Sku && ($sku === null || $sku === '')) {
            throw ValidationException::withMessages(['sku' => 'Isi SKU yang akan dihitung.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function guardCountable(Opname $opname, OpnameLine $line): void
    {
        if ($opname->status !== OpnameStatus::Counting) {
            throw ValidationException::withMessages([
                'counted_qty' => 'Sesi sudah berstatus '.$opname->status->label().'; hitungan fisik tidak bisa diubah lagi.',
            ]);
        }

        if ($line->opname_id !== $opname->getKey()) {
            throw ValidationException::withMessages([
                'counted_qty' => 'Baris ini bukan bagian dari sesi tersebut.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function guardReasonMatchesDiff(OpnameLine $line, ?AdjustmentReason $reason): void
    {
        if ($reason === null) {
            throw ValidationException::withMessages([
                'reason' => 'Pilih alasan selisih sebelum menyetujui.',
            ]);
        }

        $allowed = $line->diff_qty > 0 ? self::PLUS_REASONS : self::MINUS_REASONS;

        if (! in_array($reason, $allowed, true)) {
            throw ValidationException::withMessages([
                'reason' => $line->diff_qty > 0
                    ? 'Selisihnya lebih banyak unit; alasan yang masuk akal adalah Ditemukan atau Salah hitung.'
                    : 'Selisihnya lebih sedikit unit; alasan yang masuk akal adalah Hilang, Rusak, atau Salah hitung.',
            ]);
        }
    }

    /**
     * Lot yang masuk cakupan: yang fisiknya memang ada di rak.
     *
     * Lot dengan qty nol dikeluarkan -- tidak ada unit yang bisa dihitung, dan
     * kasus "unit ditemukan milik lot lama" memang jalurnya lewat Karantina,
     * bukan lewat opname. Lot yang sudah keluar dari gudang (dikembalikan,
     * dihapus, dibatalkan) juga dikeluarkan karena barangnya sudah tidak ada
     * untuk dihitung.
     */
    private function lotsInScope(OpnameScope $scope, ?Rack $rack, ?string $sku): Builder
    {
        $query = StockLot::query()
            ->whereIn('status', [LotStatus::Available->value, LotStatus::SoldOut->value])
            ->where('qty_on_hand', '>', 0);

        return match ($scope) {
            OpnameScope::Rack => $query->where('rack_id', $rack?->getKey()),
            OpnameScope::Sku => $query->where('sku', $sku),
            default => $query,
        };
    }

    /**
     * Id gerakan terakhir, sebagai sekat antara "sebelum" dan "sesudah".
     *
     * Tanpa gerakan sama sekali hasilnya 0, yang membuat `id > 0` berarti
     * "semua gerakan" -- memang yang diinginkan untuk sesi pertama.
     */
    private function lastMovementId(?StockLot $lot = null): int
    {
        $query = StockMovement::query();

        if ($lot !== null) {
            $query->where('lot_id', $lot->getKey());
        }

        return (int) $query->max('id');
    }

    /**
     * Tutup sesi bila semua baris sudah berada di keadaan akhir.
     *
     * Dipanggil setelah setiap keputusan, bukan sekali di akhir: baris yang
     * menunggu diurutan pertama tidak boleh menahan sesi yang baris terakhirnya
     * sudah selesai, dan tidak ada tugas terpisah yang bisa lupa memanggilnya.
     */
    private function finalize(Opname $opname, OpnameLine $line): OpnameLine
    {
        $waiting = $opname->lines()
            ->whereIn('status', [OpnameLineStatus::Pending->value, OpnameLineStatus::Counted->value])
            ->count();

        if ($waiting === 0 && $opname->status === OpnameStatus::PendingApproval) {
            $opname->update([
                'status' => OpnameStatus::Approved,
                'closed_at' => now(),
            ]);
        }

        return $line;
    }

    /**
     * Status lot setelah penyesuaian.
     *
     * Nol berarti raknya kosong untuk lot itu; kembali berisi berarti lot yang
     * tadinya habis kembali punya unit. Status lain tidak diubah -- lot yang
     * sudah keluar dari gudang tidak masuk cakupan opname sama sekali.
     */
    private function statusAfterAdjustment(StockLot $lot, int $newQty): LotStatus
    {
        if ($newQty === 0) {
            return LotStatus::SoldOut;
        }

        return $lot->status === LotStatus::SoldOut ? LotStatus::Available : $lot->status;
    }

    /**
     * Daftar SKU untuk pesan galat, dipotong supaya pesan tetap bisa dibaca di
     * satu baris notifikasi.
     *
     * @param  Collection<int, OpnameLine>  $lines
     */
    private function skuList($lines, int $limit = 5): string
    {
        $skus = $lines
            ->take($limit)
            ->map(fn (OpnameLine $line) => $line->lot?->sku ?? ('#'.$line->lot_id))
            ->values();

        $rest = $lines->count() - $skus->count();

        return $skus->implode(', ').($rest > 0 ? ', dan '.$rest.' lainnya' : '');
    }
}
