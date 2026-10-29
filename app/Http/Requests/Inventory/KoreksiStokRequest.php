<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Services\Inventory\OpnameService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Koreksi stok karena salah input atau salah hitung (FR-IC-25).
 *
 * `direction` menentukan tanda movement di server; peramban tidak pernah
 * mengirim angka negatif. `expected_qty` adalah angka stok yang dilihat saat
 * dialog dibuka. Angka itu ikut dikirim supaya service bisa menolak kiriman
 * kedua dari halaman yang sudah kedaluwarsa: tanpa itu, dua klik cepat pada
 * tombol yang sama akan mengubah stok dua kali, padahal yang dimaksud hanya
 * sekali.
 */
class KoreksiStokRequest extends FormRequest
{
    public const string DIRECTION_ADD = 'tambah';

    public const string DIRECTION_REDUCE = 'kurangi';

    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in([self::DIRECTION_ADD, self::DIRECTION_REDUCE])],
            'qty' => ['required', 'integer', 'min:1', 'max:'.OpnameService::MAX_COUNTED_QTY],
            'expected_qty' => ['required', 'integer', 'min:0', 'max:'.OpnameService::MAX_COUNTED_QTY],
        ];
    }

    public function attributes(): array
    {
        return [
            'direction' => 'arah koreksi',
            'qty' => 'jumlah koreksi',
            'expected_qty' => 'stok saat dialog dibuka',
        ];
    }

    public function messages(): array
    {
        return [
            'direction.required' => 'Pilih arah koreksi lalu coba lagi.',
            'direction.in' => 'Arah koreksi tidak valid. Muat ulang halaman.',
            'qty.required' => 'Masukkan jumlah unit yang ingin dikoreksi.',
            'qty.integer' => 'Jumlah koreksi harus berupa bilangan bulat.',
            'qty.min' => 'Jumlah koreksi minimal 1 unit.',
            'qty.max' => 'Jumlah koreksi terlalu besar; periksa lagi.',
            'expected_qty.required' => 'Muat ulang halaman lalu buka dialog Koreksi Stok lagi.',
            'expected_qty.integer' => 'Stok saat dialog dibuka tidak valid. Muat ulang halaman.',
            'expected_qty.min' => 'Stok saat dialog dibuka tidak valid. Muat ulang halaman.',
            'expected_qty.max' => 'Stok saat dialog dibuka tidak valid. Muat ulang halaman.',
        ];
    }

    public function isAddition(): bool
    {
        return $this->validated('direction') === self::DIRECTION_ADD;
    }

    public function qty(): int
    {
        return (int) $this->validated('qty');
    }

    public function expectedQty(): int
    {
        return (int) $this->validated('expected_qty');
    }
}
