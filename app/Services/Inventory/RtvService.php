<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\AuditAction;
use App\Enums\LotStatus;
use App\Enums\MovementType;
use App\Enums\OpnameStatus;
use App\Enums\OwnerType;
use App\Enums\QuarantineStatus;
use App\Enums\RackType;
use App\Enums\RtvStatus;
use App\Models\Consignor;
use App\Models\Opname;
use App\Models\QuarantineCase;
use App\Models\Rack;
use App\Models\RtvLine;
use App\Models\RtvNote;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\DeviceId;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Siklus Retur ke penitip: buat → staging → verifikasi scan → eksekusi
 * (FR-IC-30..35).
 *
 * Empat aturan menopang seluruh class ini:
 *
 * 1. **Qty yang diminta adalah janji, bukan angka mati.** Sejak dokumen dibuat,
 *    stok bisa bergerak (terjual, dipindah, diopname). Karena itu setiap langkah
 *    yang mengubah sesuatu -- membuat, staging, eksekusi -- membaca ulang baris
 *    yang bersangkutan dan memeriksa ulang, bukan memakai angka yang dibawa dari
 *    layar sebelumnya.
 * 2. **Verifikasi adalah prasyarat keluar gudang.** Eksekusi menolak bila ada
 *    baris yang `verified_qty`-nya kurang dari `qty`. Tanpa aturan ini angka
 *    fisik yang diminta orang di layar langkah 1 cukup untuk mengurangi stok,
 *    dan "pindai tiap unit" hanya menjadi hiasan.
 * 3. **FR-IC-35 dijaga di tiga titik, bukan satu.** Lot yang terikat kasus
 *    Karantina terbuka atau sesi opname yang belum selesai ditolak saat dibuat,
 *    saat dipindah ke rak staging, dan saat dieksekusi -- karena kedua ikatan
 *    itu bisa muncul di tengah sesi, persis ketika barang sudah di rak
 *    penampung dan paling mudah dikirim begitu saja.
 * 4. **Eksekusi mengurangi stok sekali.** Satu transaksi untuk seluruh dokumen:
 *    bila satu baris gagal, tidak ada baris pun yang berkurang, sehingga
 *    keadaan setelah kegagalan sama persis dengan keadaan sebelumnya.
 *
 * Pemeriksaan dilakukan dua kali -- sekali di luar transaksi supaya galat yang
 * paling umum keluar tanpa membuka transaksi, dan sekali lagi di dalam
 * transaksi terhadap baris yang dibaca ulang dengan kunci. `lockForUpdate()`
 * tidak berfungsi di SQLite yang dipakai suite pengujian, jadi baca-ulang
 * kedualah yang benar-benar menjaga.
 */
final class RtvService
{
    /**
     * Percobaan ulang transaksi bila terjadi deadlock antar-kasir.
     */
    private const int TX_ATTEMPTS = 5;

    public function __construct(
        private readonly RtvNoService $numbers,
        private readonly AuditLogger $audit,
        private readonly StockTransferService $transfer,
    ) {}

    /**
     * Buat satu dokumen RTV beserta baris-barisnya (FR-IC-30).
     *
     * @param  array<array-key, mixed>  $qtyByLot  qty kembali per `lot_id`
     *
     * @throws ValidationException
     */
    public function create(Consignor $consignor, array $qtyByLot, ?string $reason, ?User $actor): RtvNote
    {
        $quantities = $this->normalizeQuantities($qtyByLot);

        $this->guardNoOpenNote();

        return DB::transaction(function () use ($consignor, $quantities, $reason, $actor): RtvNote {
            // Dibaca ulang, diperiksa ulang: dokumen kedua yang sama-sama lolos
            // pengecekan di atas harus kalah di sini, bukan berjalan berdua.
            $this->guardNoOpenNote();

            $note = RtvNote::create([
                'rtv_no' => $this->numbers->next(),
                'consignor_id' => $consignor->getKey(),
                'status' => RtvStatus::Draft,
                'reason' => $reason,
                'created_by' => $actor?->getKey(),
            ]);

            $units = 0;

            foreach ($quantities as $lotId => $qty) {
                $lot = StockLot::query()->lockForUpdate()->find($lotId);

                if ($lot === null) {
                    throw ValidationException::withMessages([
                        'qty' => 'Sebuah lot yang dipilih tidak ditemukan lagi. Muat ulang halaman lalu pilih ulang.',
                    ]);
                }

                $this->guardReturnable($lot, $consignor, $qty);

                RtvLine::create([
                    'rtv_id' => $note->getKey(),
                    'lot_id' => $lot->getKey(),
                    'qty' => $qty,
                    'verified_qty' => 0,
                ]);

                $units += $qty;
            }

            $this->audit->log(
                AuditAction::RtvCreate->value,
                RtvNote::class,
                (int) $note->getKey(),
                [],
                [
                    'rtv_no' => $note->rtv_no,
                    'consignor' => $consignor->name,
                    'skus' => count($quantities),
                    'units' => $units,
                ],
                $reason,
            );

            return $note;
        }, self::TX_ATTEMPTS);
    }

    /**
     * Pindahkan seluruh baris dokumen ke rak `RTV_STAGING` (FR-IC-32).
     *
     * @throws ValidationException
     */
    public function moveToStaging(RtvNote $note, ?User $actor = null): RtvNote
    {
        $staging = $this->stagingRack();

        return DB::transaction(function () use ($note, $actor, $staging): RtvNote {
            $fresh = RtvNote::query()->lockForUpdate()->findOrFail($note->getKey());

            if ($fresh->status !== RtvStatus::Draft) {
                throw ValidationException::withMessages([
                    'rtv' => 'Sesi RTV ini sudah berstatus '.$fresh->status->label().'; barang hanya bisa dipindah ke rak staging sekali.',
                ]);
            }

            $lines = $fresh->lines()->with('lot')->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'rtv' => 'Sesi ini tidak berisi baris apa pun.',
                ]);
            }

            foreach ($lines as $line) {
                $lot = $line->lot;

                if ($lot === null) {
                    throw ValidationException::withMessages([
                        'rtv' => 'Salah satu baris kehilangan lot-nya. Hubungi Owner sebelum memindahkan barang.',
                    ]);
                }

                // Ikatan bisa muncul sesudah dokumen dibuat: sesi opname yang
                // dimulai, atau kasus karantina yang dibuka, sementara barang
                // menunggu di rak asalnya. Memindahkannya ke rak penampung saat
                // itu mengeluarkan barang dari pengawasan sesuatu yang sedang
                // diperiksa orang lain.
                $this->guardBlockers($lot, 'rtv');

                $this->transfer->transfer($lot, $staging, $actor, 'Staging RTV '.$fresh->rtv_no);
            }

            $before = ['status' => $fresh->status->value];

            $fresh->update(['status' => RtvStatus::Verifying]);

            $this->audit->log(
                AuditAction::RtvStaging->value,
                RtvNote::class,
                (int) $fresh->getKey(),
                $before,
                ['status' => RtvStatus::Verifying->value, 'rack' => $staging->code, 'lines' => $lines->count()],
                null,
            );

            return $fresh->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * Catat satu unit yang dipindai (FR-IC-32).
     *
     * @throws ValidationException
     */
    public function scan(RtvNote $note, string $sku, ?User $actor = null): RtvLine
    {
        return DB::transaction(function () use ($note, $sku, $actor): RtvLine {
            $fresh = RtvNote::query()->with('lines.lot')->lockForUpdate()->findOrFail($note->getKey());

            if ($fresh->status !== RtvStatus::Verifying) {
                throw ValidationException::withMessages([
                    'sku' => 'Sesi RTV berstatus '.$fresh->status->label().'; pemindaian hanya bisa dilakukan setelah barang dipindah ke rak staging.',
                ]);
            }

            // SKU unik di `stock_lots`, jadi pencocokan ini menemukan tepat satu
            // lot. Yang dicari adalah baris yang masih kekurangan hitungan:
            // memindai unit keluaran untuk kesekian kalinya tidak boleh
            // menaikkan angka di atas qty yang memang direncanakan.
            $line = $fresh->lines
                ->first(fn (RtvLine $row): bool => $row->lot?->sku === $sku && ! $row->isFullyVerified());

            if ($line === null) {
                $known = $fresh->lines->contains(fn (RtvLine $row): bool => $row->lot?->sku === $sku);

                throw ValidationException::withMessages([
                    'sku' => $known
                        ? 'Semua unit '.$sku.' sudah dipindai.'
                        : 'SKU '.$sku.' bukan bagian sesi RTV ini.',
                ]);
            }

            $line->update([
                'verified_qty' => $line->verified_qty + 1,
                'verified_at' => now(),
                'verified_by' => $actor?->getKey(),
            ]);

            return $line->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * Setujui dan eksekusi dokumen: kurangi stok, catat gerakan `RTV`
     * (FR-IC-33).
     *
     * Persetujuan dan eksekusi sengaja satu langkah: pada versi ini tidak ada
     * dokumen yang menunggu antara keduanya, dan memisahkannya akan menambah
     * satu status yang harus dijaga tanpa ada yang membacanya.
     *
     * @throws ValidationException
     */
    public function approve(RtvNote $note, ?User $approver = null): RtvNote
    {
        return DB::transaction(function () use ($note, $approver): RtvNote {
            $fresh = RtvNote::query()
                ->with(['lines.lot', 'consignor'])
                ->lockForUpdate()
                ->findOrFail($note->getKey());

            if ($fresh->status !== RtvStatus::Verifying) {
                throw ValidationException::withMessages([
                    'rtv' => 'Sesi RTV ini berstatus '.$fresh->status->label().'; hanya sesi yang sedang memverifikasi yang bisa dieksekusi.',
                ]);
            }

            if ($fresh->lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'rtv' => 'Sesi ini tidak berisi baris apa pun.',
                ]);
            }

            $unverified = $fresh->lines->filter(fn (RtvLine $line): bool => ! $line->isFullyVerified());

            if ($unverified->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'rtv' => sprintf(
                        '%d baris belum terverifikasi: %s. Pindai semua unit sebelum menyetujui.',
                        $unverified->count(),
                        $this->skuList($unverified),
                    ),
                ]);
            }

            $units = 0;
            $skus = 0;

            foreach ($fresh->lines as $line) {
                $lot = StockLot::query()->lockForUpdate()->find($line->lot_id);

                if ($lot === null) {
                    throw ValidationException::withMessages([
                        'rtv' => 'Sebuah baris kehilangan lot-nya. Sesi tidak dieksekusi.',
                    ]);
                }

                // Baca-ulang terakhir: stok yang terjual atau berpindah sejak
                // dokumen dibuat membuat angka di layar basi. Mengeksekusi
                // angka basi berarti mengurangi stok yang sudah lama berpindah
                // tangan.
                $this->guardReturnable($lot, $fresh->consignor, $line->qty, 'rtv');

                $newQty = $lot->qty_on_hand - $line->qty;

                $lot->update([
                    'qty_on_hand' => $newQty,
                    // Nol berarti seluruh unit lot ini keluar gudang; stok
                    // tersisa berarti lotnya masih punya unit dan statusnya
                    // tidak berubah.
                    'status' => $newQty === 0 ? LotStatus::Returned : $lot->status,
                ]);

                StockMovement::create([
                    'lot_id' => $lot->getKey(),
                    'type' => MovementType::Rtv,
                    'qty_delta' => -$line->qty,
                    'ref_type' => RtvNote::class,
                    'ref_id' => $fresh->getKey(),
                    'actor_id' => $approver?->getKey(),
                    'device_id' => DeviceId::current(),
                    'reason' => $fresh->reason,
                    'balance_after' => $newQty,
                ]);

                $units += $line->qty;
                $skus++;
            }

            $before = ['status' => $fresh->status->value];

            $fresh->update([
                'status' => RtvStatus::Executed,
                'executed_at' => now(),
                'approved_by' => $approver?->getKey(),
            ]);

            $this->audit->log(
                AuditAction::RtvExecute->value,
                RtvNote::class,
                (int) $fresh->getKey(),
                $before,
                [
                    'status' => RtvStatus::Executed->value,
                    'rtv_no' => $fresh->rtv_no,
                    'consignor' => $fresh->consignor?->name,
                    'skus' => $skus,
                    'units' => $units,
                ],
                $fresh->reason,
            );

            return $fresh->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * Batalkan sesi yang belum dieksekusi (FR-IC-30, dokumen boleh ditinggal).
     *
     * @throws ValidationException
     */
    public function cancel(RtvNote $note, ?User $actor = null): RtvNote
    {
        return DB::transaction(function () use ($note): RtvNote {
            $fresh = RtvNote::query()->lockForUpdate()->findOrFail($note->getKey());

            if (! $fresh->status->isOpen()) {
                throw ValidationException::withMessages([
                    'rtv' => 'Sesi RTV sudah berstatus '.$fresh->status->label().'; tidak bisa dibatalkan.',
                ]);
            }

            $before = ['status' => $fresh->status->value];

            $fresh->update(['status' => RtvStatus::Cancelled]);

            $this->audit->log(
                AuditAction::RtvCancel->value,
                RtvNote::class,
                (int) $fresh->getKey(),
                $before,
                ['status' => RtvStatus::Cancelled->value],
                null,
            );

            return $fresh->refresh();
        }, self::TX_ATTEMPTS);
    }

    /**
     * Rak staging: satu-satunya rak yang menampung barang menunggu dikirim.
     *
     * Dipilih dari kolom `type`, bukan dari kode rak tertentu: kode rak adalah
     * data yang bisa diubah lewat menu master, sementara "rak bertipe
     * RTV_STAGING" adalah aturan yang tidak berpindah tempat.
     *
     * @throws ValidationException
     */
    private function stagingRack(): Rack
    {
        $staging = Rack::query()
            ->where('type', RackType::RtvStaging->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->first();

        if ($staging === null) {
            throw ValidationException::withMessages([
                'rtv' => 'Rak bertipe RTV_STAGING tidak tersedia atau sedang nonaktif. Aktifkan rak staging di menu Lokasi Rak lebih dulu.',
            ]);
        }

        return $staging;
    }

    /**
     * Masukkan `qty[lot_id]` menjadi peta `lot_id => int`, buang entri kosong.
     *
     * Form mengirim seluruh baris tabel, termasuk yang dibiarkan kosong. Baris
     * kosong berarti "jangan ikut", bukan "salah ketik": membiarkannya lolos
     * ke aturan `integer` akan menolak seluruh formulir hanya karena satu
     * kolom tidak diisi, dan pesannya tidak menyebut kolom mana pun.
     *
     * @return array<int, int>
     */
    private function normalizeQuantities(array $qtyByLot): array
    {
        $quantities = [];

        foreach ($qtyByLot as $lotId => $qty) {
            if ($qty === null || $qty === '' || $qty === false) {
                continue;
            }

            $quantities[(int) $lotId] = (int) $qty;
        }

        return $quantities;
    }

    /**
     * Apakah lot ini boleh dikembalikan sebanyak `$qty` (FR-IC-30, FR-IC-35).
     *
     * @throws ValidationException
     */
    private function guardReturnable(StockLot $lot, ?Consignor $consignor, int $qty, string $field = 'qty'): void
    {
        if ($lot->owner_type !== OwnerType::Consign) {
            throw ValidationException::withMessages([
                $field => sprintf('%s adalah barang milik toko; retur hanya untuk barang titipan.', $lot->sku),
            ]);
        }

        if ($consignor !== null && $lot->consignor_id !== $consignor->getKey()) {
            throw ValidationException::withMessages([
                $field => sprintf('%s bukan milik penitip yang dipilih.', $lot->sku),
            ]);
        }

        if ($lot->status !== LotStatus::Available) {
            throw ValidationException::withMessages([
                $field => sprintf('%s berstatus %s dan tidak bisa dikembalikan.', $lot->sku, $lot->status->value),
            ]);
        }

        if ($qty < 1) {
            throw ValidationException::withMessages([
                $field => sprintf('Qty kembali untuk %s minimal satu unit.', $lot->sku),
            ]);
        }

        if ($lot->qty_on_hand < $qty) {
            throw ValidationException::withMessages([
                $field => sprintf(
                    'Qty RTV untuk %s (%d) melebihi stok tersedia (%d). Kurangi qty atau muat ulang halaman.',
                    $lot->sku,
                    $qty,
                    $lot->qty_on_hand,
                ),
            ]);
        }

        $this->guardBlockers($lot, $field);
    }

    /**
     * Dua ikatan yang menutup pintur RTV (FR-IC-35): kasus Karantina terbuka
     * dan sesi opname yang belum selesai.
     *
     * Pengecekan tetap ditulis meski modul Karantina belum beroperasi penuh:
     * aturannya milik skema, bukan milik modul, dan saat kasus pertama dibuat
     * aturan ini harus sudah ada -- bukan ditambahkan setelah satu unit sempat
     * terkirim dua arah.
     *
     * @throws ValidationException
     */
    private function guardBlockers(StockLot $lot, string $field = 'qty'): void
    {
        $case = QuarantineCase::query()
            ->where('assigned_lot_id', $lot->getKey())
            ->whereIn('status', QuarantineStatus::openValues())
            ->first();

        if ($case !== null) {
            throw ValidationException::withMessages([
                $field => sprintf('%s terkait kasus Karantina %s yang belum selesai. Selesaikan kasus itu lebih dulu.', $lot->sku, $case->case_no),
            ]);
        }

        $opname = Opname::query()
            ->whereIn('status', OpnameStatus::openValues())
            ->whereHas('lines', fn ($lines) => $lines->where('lot_id', $lot->getKey()))
            ->first();

        if ($opname !== null) {
            throw ValidationException::withMessages([
                $field => sprintf('%s terkait sesi opname %s yang masih terbuka. Sesi opname harus selesai atau dibatalkan lebih dulu.', $lot->sku, $opname->opname_no),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function guardNoOpenNote(): void
    {
        $open = RtvNote::query()
            ->whereIn('status', RtvStatus::openValues())
            ->latest('id')
            ->first();

        if ($open !== null) {
            throw ValidationException::withMessages([
                'consignor_id' => 'Masih ada sesi RTV terbuka ('.$open->rtv_no.' · '.$open->status->label().'). Selesaikan atau batalkan sesi itu lebih dulu.',
            ]);
        }
    }

    /**
     * Daftar SKU untuk pesan galat, dipotong supaya pesan tetap terbaca di
     * satu baris notifikasi.
     *
     * @param  Collection<int, RtvLine>  $lines
     */
    private function skuList($lines, int $limit = 5): string
    {
        $skus = $lines
            ->take($limit)
            ->map(fn (RtvLine $line): string => $line->lot?->sku ?? ('#'.$line->lot_id))
            ->values();

        $rest = $lines->count() - $skus->count();

        return $skus->implode(', ').($rest > 0 ? ', dan '.$rest.' lainnya' : '');
    }
}
