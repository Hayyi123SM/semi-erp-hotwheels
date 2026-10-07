<?php

declare(strict_types=1);

namespace App\Services\Inventory;

/**
 * Hasil satu baris skema, sudah berupa Rupiah bulat.
 *
 * DTO, bukan array, supaya kolom `fee_toko` tidak bisa diisi dengan kunci yang
 * salah tanpa ketahuan di tempat pemanggilannya. `negativeMargin` sengaja ada
 * di sini dan bukan hanya di guard: pemanggil yang butuh menandai lot (BR-06)
 * akan membacanya dari sini, bukan menghitung ulang.
 */
final readonly class TermsBreakdown
{
    public function __construct(
        /** Harga list di sistem. */
        public int $listPrice,
        /** Diskon yang dialokasikan ke item ini. */
        public int $discount,
        /** Harga jual aktual: `L - D`. */
        public int $sellPrice,
        /** Pendapatan toko per unit, sudah dibulatkan. */
        public int $storeFee,
        /** Hak penitip per unit: `P - fee` untuk `STORE_BEARS`, sisanya untuk `SHARED`. */
        public int $consignorRight,
        /** `true` bila fee toko negatif sehingga marginnya habis. */
        public bool $negativeMargin,
    ) {}

    /**
     * Fee toko dikali qty baris.
     *
     * Perkalian dilakukan di sini, bukan di pemanggil, karena pembulatan sudah
     * selesai di level per unit: `round(P x rate) x qty` dan
     * `round(P x rate x qty)` bisa berbeda, dan yang benar menurut BR-05 adalah
     * yang pertama.
     */
    public function storeFeeFor(int $qty): int
    {
        return $this->storeFee * $qty;
    }

    public function consignorRightFor(int $qty): int
    {
        return $this->consignorRight * $qty;
    }
}
