<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\InputMethod;

/**
 * Satu baris keranjang yang akan dijual.
 *
 * `lotId`, bukan SKU: SKU menjawab "produk apa", sedangkan yang dipotong
 * stoknya adalah lot yang spesifik. Harga dan ketentuan dihitung ulang dari
 * database, namun diskon per-item boleh diberikan oleh kasir yang berwenang.
 */
final readonly class CheckoutLine
{
    public function __construct(
        public int $lotId,
        public int $qty,
        public InputMethod $inputMethod = InputMethod::Scan,
        public ?string $discountType = null, // 'percent' | 'amount'
        public ?int $discountValue = null,
    ) {}

    public function hasDiscount(): bool
    {
        return $this->discountType !== null && $this->discountValue !== null && $this->discountValue > 0;
    }
}
