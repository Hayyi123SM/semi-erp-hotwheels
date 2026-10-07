<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\OpnameScope;
use App\Models\Rack;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mulai satu sesi opname (FR-IC-20).
 *
 * Cakupan menentukan dua kolom lain: RACK butuh rak, SKU butuh SKU. Keduanya
 * ditulis sebagai `required_if` supaya form yang sedang dipakai orang tidak
 * menuntut rak saat ia sedang memilih "seluruh gudang" -- galat yang tidak
 * pernah bisa dijawab karena field-nya memang disembunyikan oleh pilihan itu
 * sendiri.
 *
 * SKU diverifikasi terhadap `stock_lots`, bukan terhadap katalog: sesi yang
 * cakupannya satu SKU namun tidak punya lot apa pun akan ditolak service
 * sesaat kemudian, dan menolaknya di form berarti orang melihat "SKU tidak
 * ditemukan" pada saat ia mengetik, bukan setelah menekan tombol.
 */
class MulaiOpnameRequest extends FormRequest
{
    /**
     * Dinormalisasi sebelum aturan dijalankan, bukan sesudahnya: aturan
     * `exists` membaca nilai mentah, jadi SKU yang diketik huruf kecil dengan
     * spasi ujung akan ditolak sebagai "tidak ada" padahal barisnya ada.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => strtoupper(trim($this->input('sku')))]);
        }
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::enum(OpnameScope::class)],
            'rack_id' => [
                Rule::requiredIf(fn () => $this->input('scope') === OpnameScope::Rack->value),
                'nullable',
                'integer',
                Rule::exists('racks', 'id'),
            ],
            'sku' => [
                Rule::requiredIf(fn () => $this->input('scope') === OpnameScope::Sku->value),
                'nullable',
                'string',
                'max:30',
                Rule::exists('stock_lots', 'sku'),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'scope' => 'cakupan',
            'rack_id' => 'rak',
            'sku' => 'SKU',
        ];
    }

    public function messages(): array
    {
        return [
            'scope.required' => 'Pilih cakupan opname.',
            'scope.enum' => 'Cakupan opname tidak dikenal.',
            'rack_id.required' => 'Pilih rak yang akan dihitung.',
            'rack_id.exists' => 'Rak itu tidak ditemukan.',
            'sku.required' => 'Isi SKU yang akan dihitung.',
            'sku.exists' => 'SKU itu tidak ada di stok.',
        ];
    }

    public function scope(): OpnameScope
    {
        return OpnameScope::from((string) $this->validated('scope'));
    }

    /**
     * Rak tujuan, atau null untuk cakupan lain. Dipanggil hanya setelah
     * validasi, sehingga `exists` sudah menjamin barisnya ada.
     */
    public function rack(): ?Rack
    {
        $id = (int) $this->input('rack_id');

        return $id > 0 ? Rack::query()->findOrFail($id) : null;
    }

    public function sku(): ?string
    {
        $sku = (string) $this->input('sku', '');

        return $sku === '' ? null : $sku;
    }
}
