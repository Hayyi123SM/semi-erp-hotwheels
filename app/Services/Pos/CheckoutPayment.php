<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\PaymentMethod;

/**
 * Satu baris pembayaran.
 *
 * Satu nota boleh dibayar beberapa metode, jadi daftar ini bukan sekadar
 * "metode pembayarannya apa". `amount` adalah bagian yang dibayar lewat metode
 * ini dan jumlahnya harus persis sama dengan total belanja -- bukan uang yang
 * dipegang kasir, karena uang yang dipegang bukan uang yang diterima toko.
 */
final readonly class CheckoutPayment
{
    public function __construct(
        public PaymentMethod $method,
        public int $amount,
        public ?string $reference = null,
    ) {}
}
