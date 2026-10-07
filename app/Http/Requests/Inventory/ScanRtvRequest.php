<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pindai satu unit ke rak staging (FR-IC-32).
 *
 * Pemindaian adalah satu-satunya input di alur ini yang datang dari mesin
 * (scanner barcode), bukan dari jari: nilai dinormalisasi lebih dulu supaya
 * label yang dicetak dengan huruf kecil atau berSpasi ujung tetap cocok dengan
 * SKU yang ada di database. Menolaknya sebagai "bukan bagian sesi" akan
 * terdengar seperti barang yang salah, padahal yang salah hanya kapitalisasi.
 */
class ScanRtvRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('sku'))) {
            $this->merge(['sku' => strtoupper(trim($this->input('sku')))]);
        }
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:30'],
        ];
    }

    public function attributes(): array
    {
        return [
            'sku' => 'SKU',
        ];
    }

    public function messages(): array
    {
        return [
            'sku.required' => 'Pindai atau ketik SKU unit yang dipindahkan.',
            'sku.max' => 'SKU terlalu panjang.',
        ];
    }

    public function sku(): string
    {
        return (string) $this->validated('sku');
    }
}
