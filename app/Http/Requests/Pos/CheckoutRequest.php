<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos;

use App\Enums\InputMethod;
use App\Enums\PaymentMethod;
use App\Services\Pos\CheckoutPayment;
use App\Services\Pos\PosSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Permintaan "bayar" dari layar kasir.
 *
 * Tidak ada satu pun harga di sini, dan itu bukan kelalaian: keranjang mengirim
 * `lot_id` dan `qty` saja, sedangkan harga, ketentuan skema, dan ketersediaan
 * diambil ulang dari `stock_lots` pada saat nota dibuat. Angka yang tampil di
 * layar bisa basi -- harga diubah di Master Data, unit terakhir diambil kasir
 * lain -- dan permintaan yang membawa harga sendiri akan membuat nota yang
 * tidak cocok dengan barang yang keluar dari rak.
 *
 * `tender` memang ikut, karena uang yang dipegang kasir tidak ada di database
 * mana pun: dialah satu-satunya cara server memastikan uang yang diterima
 * mencukupi dan menghitung kembalian. Tapi `tender` tidak pernah menjadi jumlah
 * yang diterima toko -- lihat {@see CheckoutPayment}.
 */
class CheckoutRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Kunci idempotensi. Layar kasir membuatnya sekali per upaya bayar
            // dan memakai ulang untuk percobaan ulang, supaya timeout yang
            // dibalas dua kali tidak menghasilkan dua nota untuk satu keranjang.
            'client_sale_id' => ['required', 'string', 'max:40', 'alpha_dash'],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.lot_id' => ['required', 'integer', 'min:1'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.input_method' => ['nullable', Rule::in(self::inputMethods())],

            'payments' => ['required', 'array', 'min:1', 'max:5'],
            'payments.*.method' => ['required', Rule::in(self::paymentMethods())],
            'payments.*.amount' => ['required', 'integer', 'min:1', 'max:'.PosSettings::MAX_AMOUNT],
            'payments.*.reference' => ['nullable', 'string', 'max:60'],

            'tender' => ['nullable', 'integer', 'min:0', 'max:'.PosSettings::MAX_AMOUNT],
        ];
    }

    /**
     * Pemeriksaan yang tidak bisa dinyatakan sebagai satu aturan per field.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $ids = array_column($this->input('items', []), 'lot_id');

                if (count($ids) !== count(array_unique($ids))) {
                    $validator->errors()->add(
                        'items',
                        'Satu lot tidak boleh muncul dua kali dalam satu nota. Muat ulang halaman bila ini terjadi.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_sale_id.required' => 'Kunci transaksi tidak terkirim. Muat ulang halaman kasir.',
            'client_sale_id.alpha_dash' => 'Kunci transaksi tidak valid. Muat ulang halaman kasir.',
            'items.required' => 'Keranjang kosong.',
            'items.min' => 'Keranjang kosong.',
            'payments.required' => 'Pilih metode pembayaran.',
            'payments.min' => 'Pilih metode pembayaran.',
            'tender.min' => 'Uang diterima tidak boleh negatif.',
        ];
    }

    /**
     * Metode pembayaran yang bisa dipakai kasir.
     *
     * `PaymentMethod::pos()`, bukan `cases()`: `Transfer` hanya untuk membayar
     * penitip dan tidak pernah terjadi di laci kasir.
     *
     * @return list<string>
     */
    private static function paymentMethods(): array
    {
        return array_map(
            static fn (PaymentMethod $method): string => $method->value,
            PaymentMethod::pos(),
        );
    }

    /**
     * @return list<string>
     */
    private static function inputMethods(): array
    {
        return array_map(
            static fn (InputMethod $method): string => $method->value,
            InputMethod::cases(),
        );
    }
}
