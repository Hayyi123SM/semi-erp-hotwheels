<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Services\Inventory\OpnameService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Catat hitungan fisik satu baris (FR-IC-21).
 *
 * Tidak ada kolom lain di sini dengan sengaja. Blind count berarti form ini
 * tidak pernah menerima, mengembalikan, atau menyimpan angka sistem -- angka
 * sistem dibaca service dari snapshot sesi, dan perbandingannya dihitung di
 * sana. Form hanya membawa apa yang dilihat orang: jumlah yang ia hitung di
 * rak.
 */
class HitungBarisRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'counted_qty' => ['required', 'integer', 'min:0', 'max:'.OpnameService::MAX_COUNTED_QTY],
        ];
    }

    public function attributes(): array
    {
        return [
            'counted_qty' => 'jumlah terhitung',
        ];
    }

    public function messages(): array
    {
        return [
            'counted_qty.required' => 'Masukkan jumlah yang terhitung di rak, termasuk 0 bila memang kosong.',
            'counted_qty.integer' => 'Jumlah terhitung harus berupa bilangan bulat.',
            'counted_qty.min' => 'Jumlah terhitung tidak boleh negatif.',
            'counted_qty.max' => 'Jumlah terhitung terlalu besar; periksa lagi.',
        ];
    }

    public function countedQty(): int
    {
        return (int) $this->validated('counted_qty');
    }
}
