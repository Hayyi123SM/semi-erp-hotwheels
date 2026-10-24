<?php

namespace App\Services\Inventory;

use App\Models\Rack;
use App\Models\StockLot;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya tempat menghitung dan menjaga kapasitas rak.
 *
 * Kapasitas diukur dalam unit fisik (`SUM(qty_on_hand)`), bukan jumlah lot,
 * supaya angka di beranda, halaman rak, dan formulir penerimaan berbicara
 * tentang hal yang sama. `capacity` kosong atau nol berarti tanpa batas.
 *
 * Pemeriksaan harus dipanggil di dalam transaksi pemanggil. Rak dibaca ulang
 * dengan `lockForUpdate()` sebelum dihitung agar dua penerimaan bersamaan
 * tidak sama-sama lolos dari sisa ruang yang sama.
 */
class RackCapacityService
{
    public function usedUnits(Rack $rack): int
    {
        return (int) StockLot::query()
            ->where('rack_id', $rack->getKey())
            ->sum('qty_on_hand');
    }

    /**
     * Sisa ruang dalam unit; `null` berarti rak tanpa batas kapasitas.
     */
    public function available(Rack $rack): ?int
    {
        if (! $this->isLimited($rack)) {
            return null;
        }

        return max(0, (int) $rack->capacity - $this->usedUnits($rack));
    }

    /**
     * Periksa beberapa rak sekaligus, biasanya untuk satu dokumen penerimaan
     * yang barisnya bisa mendarat di rak berbeda atau menumpuk di rak yang sama.
     *
     * @param  array<int, int>  $incomingByRack  qty yang diminta per id rak
     *
     * @throws ValidationException
     */
    public function assertIncomingFits(array $incomingByRack, string $errorKey = 'items'): void
    {
        // Urutan kunci dibuat tetap supaya dua dokumen yang menyentuh rak yang
        // sama tidak mengunci dalam urutan berlawanan dan saling deadlock.
        ksort($incomingByRack);

        foreach ($incomingByRack as $rackId => $incoming) {
            if ($incoming < 1) {
                continue;
            }

            $rack = Rack::query()->lockForUpdate()->find($rackId);

            if ($rack !== null) {
                $this->assertFits($rack, $incoming, $errorKey);
            }
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertFits(Rack $rack, int $incoming, string $errorKey = 'items'): void
    {
        if ($incoming < 1 || ! $this->isLimited($rack)) {
            return;
        }

        $used = $this->usedUnits($rack);
        $remaining = (int) $rack->capacity - $used;

        if ($incoming <= $remaining) {
            return;
        }

        throw ValidationException::withMessages([
            $errorKey => sprintf(
                'Rak %s tidak cukup: kapasitas %d unit, terpakai %d, diminta %d (sisa %d).',
                $rack->code,
                $rack->capacity,
                $used,
                $incoming,
                max(0, $remaining),
            ),
        ]);
    }

    /**
     * Cegah kapasitas diturunkan di bawah isi rak saat ini.
     *
     * Nilai yang sama dengan kapasitas tersimpan tidak diperiksa ulang: rak
     * yang sudah terlanjur melebihi kapasitas (data lama) tetap boleh diubah
     * bagian lainnya tanpa memaksa menaikkan kapasitas lebih dulu.
     *
     * @throws ValidationException
     */
    public function assertCapacityCoversUsage(Rack $rack, ?int $capacity, string $errorKey = 'capacity'): void
    {
        if ($capacity === null || $capacity <= 0 || $capacity === (int) $rack->capacity) {
            return;
        }

        $used = $this->usedUnits($rack);

        if ($capacity >= $used) {
            return;
        }

        throw ValidationException::withMessages([
            $errorKey => sprintf(
                'Kapasitas %d unit tidak boleh di bawah pemakaian saat ini (%d unit). Kosongkan untuk tanpa batas.',
                $capacity,
                $used,
            ),
        ]);
    }

    public function isLimited(Rack $rack): bool
    {
        return (int) $rack->capacity > 0;
    }
}
