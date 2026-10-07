<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\PaymentMethod;
use App\Models\User;

/**
 * Satu pembayaran yang sedang diminta kasir, dalam bentuk yang sudah bebas HTTP.
 *
 * Dipisah dari `CheckoutRequest` supaya logika penjualan bisa diuji tanpa
 * melewati router: yang dibutuhkan layar kasir hanya enam nilai ini, dan
 * menyimpannya di dalam request berarti setiap uji unit yang ingin memeriksa
 * "apakah stok dipotong" harus terlebih dahulu membuat sebuah HTTP request.
 *
 * `tender` -- uang yang dipegang kasir -- sengaja bukan bagian dari pembayaran.
 * Ia tidak pernah menjadi jumlah yang diterima toko; ia hanya penentu
 * kembalian, dan mencampur keduanya membuat kas laci terhitung lebih besar
 * daripada uang yang sebenarnya masuk.
 */
final readonly class Checkout
{
    /**
     * @param  list<CheckoutLine>  $lines
     * @param  list<CheckoutPayment>  $payments
     */
    public function __construct(
        public string $clientSaleId,
        public User $cashier,
        public ?string $deviceId,
        public array $lines,
        public array $payments,
        public ?int $tender = null,
    ) {}

    /**
     * Kunci idempotensi: nota dengan `client_sale_id` ini sudah pernah dibuat.
     *
     * @return list<int>
     */
    public function lotIds(): array
    {
        $ids = [];

        foreach ($this->lines as $line) {
            $ids[] = $line->lotId;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Apakah sebagian uang diterima lewat tunai.
     *
     * Kalau iya, uang yang dipegang kasir harus ada dan mencukupi: kembalian
     * tidak bisa dihitung dari uang yang tidak pernah disebutkan.
     */
    public function paysWithCash(): bool
    {
        foreach ($this->payments as $payment) {
            if ($payment->method === PaymentMethod::Cash) {
                return true;
            }
        }

        return false;
    }
}
