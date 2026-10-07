<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\InputMethod;

/**
 * Satu baris keranjang yang akan dijual.
 *
 * `lotId`, bukan SKU: SKU menjawab "produk apa", sedangkan yang dipotong
 * stoknya adalah lot yang spesifik. Keduanya satu-satunya di tabel ini karena
 * harga dan ketentuan sengaja tidak ikut -- angka itu diambil ulang dari
 * database saat penjualan disimpan, sehingga nilai di layar yang sudah basi
 * tidak pernah menjadi harga yang tercatat.
 */
final readonly class CheckoutLine
{
    public function __construct(
        public int $lotId,
        public int $qty,
        public InputMethod $inputMethod = InputMethod::Scan,
    ) {}
}
