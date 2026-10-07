<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\DiscountPolicy;
use App\Enums\SchemeType;
use App\Models\Product;
use App\Models\Rack;

/**
 * Satu baris penerimaan barang.
 *
 * Satu baris = satu kombinasi (produk, kondisi, qty, rak, harga jual, skema). Gugus
 * SKU unik dihasilkan per baris, sehingga dua baris dengan produk sama tetap
 * mendapat SKU terpisah pada dua baris grid. Untuk stok toko, `costPrice` wajib
 * diisi; untuk titipan ia diabaikan karena fee penitip ditentukan oleh kontrak.
 *
 * Syarat skema pada baris titipan boleh `null` -- artinya "pakai default profil
 * penitip". Yang tidak boleh `null` adalah hasilnya: `withConsignmentTerms()`
 * mengisinya dari profil penitip sebelum baris sampai ke lot, sehingga tidak ada
 * jalur di mana lot tersimpan tanpa skema, dan grid tidak perlu tahu apa pun
 * tentang default itu.
 */
final readonly class InboundLine
{
    public function __construct(
        public Product $product,
        public int $qty,
        public ?Rack $rack = null,
        public ?CardCondition $cardCondition = null,
        public ?BlisterCondition $blisterCondition = null,
        public ?int $costPrice = null,
        public ?int $listPrice = null,
        public ?SchemeType $schemeType = null,
        public ?float $schemeRate = null,
        public ?int $schemeAmount = null,
        public ?DiscountPolicy $discountPolicy = null,
    ) {}

    /**
     * Isi syarat skema dari profil penitip untuk semua yang masih kosong.
     *
     * Hanya mengisi yang `null`, jadi perubahan yang disengaja Staff tetap
     * bertahan; `withConsignmentTerms()` tidak pernah menimpa pilihan Staff.
     * Harganya tetap opsional: default lot adalah harga list produk, dan baris
     * yang tidak menyebut harga berarti tidak berubah dari default itu.
     */
    public function withConsignmentTerms(
        ?SchemeType $defaultType,
        ?float $defaultRate,
        ?int $defaultAmount,
        DiscountPolicy $defaultPolicy,
    ): self {
        return new self(
            product: $this->product,
            qty: $this->qty,
            rack: $this->rack,
            cardCondition: $this->cardCondition,
            blisterCondition: $this->blisterCondition,
            costPrice: $this->costPrice,
            listPrice: $this->listPrice,
            schemeType: $this->schemeType ?? $defaultType,
            schemeRate: $this->schemeRate ?? $defaultRate,
            schemeAmount: $this->schemeAmount ?? $defaultAmount,
            discountPolicy: $this->discountPolicy ?? $defaultPolicy,
        );
    }
}
