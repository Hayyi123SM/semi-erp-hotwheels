<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\StockLot;

/**
 * Hasil pemeriksaan batas cetak ulang, dalam bentuk yang bisa langsung
 * ditampilkan ke petugas (FR-IB-22).
 *
 * Dipisah dari `ReprintLimit` supaya keputusan "boleh atau tidak" dan
 * kalimat yang dibacakan ke petugas berada di tempat yang sama. Kalau
 * kalimatnya dirangkai ulang di controller, cepat atau lambat akan berbeda dari
 * yang dihitung, dan petugas diberi alasan yang salah untuk menolak cetakan.
 */
final readonly class ReprintVerdict
{
    /**
     * @param  list<array{lot: StockLot, used: int, requested: int, allowed: int}>  $overQty
     *                                                                                        Lot yang akan punya lebih banyak label daripada barang yang diterima.
     * @param  list<array{lot: StockLot, today: int, allowed: int}>  $overDaily
     *                                                                           Lot yang sudah melewati jatah cetak ulang harian.
     */
    public function __construct(
        public array $overQty = [],
        public array $overDaily = [],
        private bool $actorIsOwner = false,
    ) {}

    public function isBreached(): bool
    {
        return $this->overQty !== [] || $this->overDaily !== [];
    }

    /**
     * Apakah aksi ini perlu PIN Owner.
     *
     * Batas harian tidak berlaku untuk Owner sama sekali -- matriks peran
     * memberi Owner akses penuh pada re-print, dan memaksa dia mengetik PIN
     * sendiri hanya menambah langkah tanpa menambah keamanan.
     *
     * Batas jumlah label tetap berlaku untuk Owner dalam arti lain: dia boleh
     *enbach, tapi kejadiannya tetap dicatat sebagai anomali, supaya laporan
     * "cetak melebihi qty" (FR-RP-22) tidak bisa dilewati oleh Owner diam-diam.
     */
    public function needsOwnerPin(): bool
    {
        return $this->isBreached() && ! $this->actorIsOwner;
    }

    /**
     * Nama aksi audit untuk setiap jenis pelanggaran yang terjadi.
     *
     * @return list<string>
     */
    public function breachActions(): array
    {
        $actions = [];

        if ($this->overQty !== []) {
            $actions[] = 'LABEL_REPRINT_OVER_QTY';
        }

        if ($this->overDaily !== []) {
            $actions[] = 'LABEL_REPRINT_DAILY_LIMIT';
        }

        return $actions;
    }

    /**
     * Satu kalimat yang menjelaskan kenapa cetakan ini tidak bisa langsung
     * lolos, ditulis untuk dibaca sambil berdiri di depan printer.
     */
    public function summary(): string
    {
        $sentences = [];

        foreach ($this->overQty as $breach) {
            $sentences[] = sprintf(
                '%s sudah punya %d dari %d label. %d label lagi berarti lebih banyak label daripada barang yang diterima.',
                $breach['lot']->sku,
                $breach['used'],
                $breach['used'] + $breach['allowed'],
                $breach['requested'],
            );
        }

        foreach ($this->overDaily as $breach) {
            $sentences[] = sprintf(
                '%s sudah dicetak ulang %d&times; hari ini, batas %d&times;.',
                $breach['lot']->sku,
                $breach['today'],
                $breach['allowed'],
            );
        }

        return implode(' ', $sentences);
    }
}
