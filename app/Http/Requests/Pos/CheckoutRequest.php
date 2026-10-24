<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos;

use App\Enums\InputMethod;
use App\Enums\PaymentMethod;
use App\Models\StockLot;
use App\Services\Pos\CheckoutPayment;
use App\Services\Pos\CheckoutService;
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
            'items.*.discount_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0', 'max:999999999'],

            'order_discount_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'order_discount_value' => ['nullable', 'numeric', 'min:0', 'max:999999999'],

            'payments' => ['required', 'array', 'min:1', 'max:5'],
            'payments.*.method' => ['required', Rule::in(self::paymentMethods())],
            'payments.*.amount' => ['required', 'integer', 'min:1', 'max:'.PosSettings::MAX_AMOUNT],
            'payments.*.reference' => ['nullable', 'string', 'max:60'],

            'tender' => ['nullable', 'integer', 'min:0', 'max:'.PosSettings::MAX_AMOUNT],
        ];
    }

    /**
     * Menerima uang adalah pekerjaan kasir, dan rutenya sudah dijaga `auth`.
     *
     * Batas diskon tidak ditegakkan di sini: penolakan lewat `authorize()`
     * datang sebagai 403 tanpa pesan, dan kasir yang menekan tombol diskon
     * berhak tahu alasannya. Karena itu pemeriksaan peran dan batas nilainya
     * ada di {@see self::after()} sebagai galat validasi yang bisa dibaca.
     */
    public function authorize(): bool
    {
        return true;
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

                $this->guardDiscounts($validator);
                $this->guardOrderDiscount($validator);
            },
        ];
    }

    /**
     * Batas diskon per baris, diperiksa terhadap harga lot yang sebenarnya.
     *
     * Harga yang menentukan adalah `list_price * qty`, bukan angka yang dikirim
     * layar: layar bisa saja sudah basi, dan diskon yang sah menurutnya bisa
     * melebihi harga barang yang benar-benar keluar. Batas persen dijaga di 100%
     * dan nominal di harga barisnya, sehingga baris tidak pernah menjadi negatif.
     *
     * Diskon hanya untuk Staff dan Owner. Peran lain tidak punya alasan
     * memotong harga, dan pesannya ditulis per peran yang benar-benar ada.
     */
    private function guardDiscounts(Validator $validator): void
    {
        $items = $this->input('items', []);
        $lotIds = collect($items)->pluck('lot_id')->filter()->values()->all();

        if ($lotIds === []) {
            return;
        }

        $user = $this->user();
        $mayDiscount = $user !== null && ($user->isOwner() || $user->isStaff());
        $lots = StockLot::whereIn('id', $lotIds)->get()->keyBy('id');

        foreach ($items as $index => $item) {
            $value = (int) ($item['discount_value'] ?? 0);

            if ($value <= 0) {
                continue;
            }

            if (! $mayDiscount) {
                $validator->errors()->add(
                    "items.{$index}.discount_value",
                    'Hanya Staff atau Owner yang dapat memberikan diskon.',
                );

                continue;
            }

            $lot = $lots->get($item['lot_id'] ?? null);

            if ($lot === null) {
                continue;
            }

            $type = $item['discount_type'] ?? null;
            $lineAmount = (int) $lot->list_price * (int) ($item['qty'] ?? 1);

            if ($type === 'percent' && $value > 100) {
                $validator->errors()->add(
                    "items.{$index}.discount_value",
                    'Diskon persentase tidak boleh lebih dari 100%.',
                );

                continue;
            }

            if ($type === 'amount' && $value > $lineAmount) {
                $validator->errors()->add(
                    "items.{$index}.discount_value",
                    'Diskon tidak boleh melebihi harga per baris.',
                );
            }
        }
    }

    /**
     * Diskon total mengikuti aturan peran dan bentuk yang sama dengan diskon
     * per baris. Batas nominalnya terhadap total nota dihitung ulang di
     * {@see CheckoutService} setelah harga semua baris
     * diketahui; di sini yang bisa diperiksa tanpa harga hanyalah bentuknya.
     */
    private function guardOrderDiscount(Validator $validator): void
    {
        $value = (int) $this->input('order_discount_value', 0);

        if ($value <= 0) {
            return;
        }

        $user = $this->user();
        $mayDiscount = $user !== null && ($user->isOwner() || $user->isStaff());

        if (! $mayDiscount) {
            $validator->errors()->add(
                'order_discount_value',
                'Hanya Staff atau Owner yang dapat memberikan diskon.',
            );

            return;
        }

        $type = $this->input('order_discount_type');

        if (! in_array($type, ['percent', 'amount'], true)) {
            $validator->errors()->add(
                'order_discount_type',
                'Pilih tipe diskon total terlebih dahulu.',
            );

            return;
        }

        if ($type === 'percent' && $value > 100) {
            $validator->errors()->add(
                'order_discount_value',
                'Diskon persentase tidak boleh lebih dari 100%.',
            );
        }
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
