<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Models\Consignor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Buat satu dokumen RTV (FR-IC-30).
 *
 * Formulir mengirim `qty[lot_id]` untuk setiap baris tabel, termasuk yang
 * dibiarkan kosong. Entri kosong dibuang lebih dulu di `prepareForValidation`:
 * kolom yang tidak diisi berarti "SKU ini tidak ikut", bukan kesalahan ketik,
 * dan meneruskannya ke aturan `integer` akan menolak seluruh formulir dengan
 * pesan yang tidak menyebut satu pun kolomnya.
 *
 * Batas atas qty sengaja tidak ditulis di sini. Angka yang benar-benar
 * mengikat adalah stok yang ada di saat transaksi berjalan -- bukan stok yang
 * ada saat form dimuat -- sehingga pemeriksaannya dilakukan `RtvService`
 * terhadap baris yang dibaca ulang. Validasi di form hanya menangkap apa yang
 * tidak butuh bacaan database: bukan angka, bukan bilangan bulat, bukan
 * positif.
 */
class BuatRtvRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $qty = $this->input('qty');

        if (is_array($qty)) {
            $this->merge([
                'qty' => array_filter(
                    $qty,
                    fn ($value) => $value !== null && $value !== '' && $value !== '0' && $value !== 0,
                ),
            ]);
        }

        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'consignor_id' => ['required', 'integer', Rule::exists('consignors', 'id')],
            'qty' => ['required', 'array', 'min:1'],
            'qty.*' => ['integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'consignor_id' => 'penitip',
            'qty' => 'pilihan SKU',
            'qty.*' => 'qty kembali',
            'reason' => 'alasan retur',
        ];
    }

    public function messages(): array
    {
        return [
            'consignor_id.required' => 'Pilih penitip yang barangnya akan dikembalikan.',
            'consignor_id.exists' => 'Penitip itu tidak ditemukan.',
            'qty.required' => 'Pilih minimal satu SKU yang akan dikembalikan.',
            'qty.min' => 'Pilih minimal satu SKU yang akan dikembalikan.',
            'qty.*.integer' => 'Qty kembali harus berupa bilangan bulat.',
            'qty.*.min' => 'Qty kembali minimal satu unit; kosongkan kolomnya bila SKU itu tidak ikut.',
            'reason.max' => 'Alasan retur maksimal 255 karakter.',
        ];
    }

    public function consignor(): Consignor
    {
        return Consignor::query()->findOrFail((int) $this->validated('consignor_id'));
    }

    /**
     * Qty kembali per `lot_id`, hanya baris yang memang diisi.
     *
     * @return array<int, int>
     */
    public function quantities(): array
    {
        $quantities = [];

        foreach ((array) $this->validated('qty') as $lotId => $qty) {
            $quantities[(int) $lotId] = (int) $qty;
        }

        return $quantities;
    }

    public function reason(): ?string
    {
        $reason = $this->input('reason');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }
}
