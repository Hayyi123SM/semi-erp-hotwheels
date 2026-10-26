<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Services\Inventory\OpnameService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Koreksi stok karena salah input atau salah hitung (FR-IC-25).
 *
 * `expected_qty` adalah angka stok yang dilihat saat dialog dibuka. Angka itu
 * ikut dikirim supaya service bisa menolak kiriman kedua dari halaman yang
 * sudah kedaluwarsa: tanpa itu, dua klik cepat pada tombol yang sama akan
 * mengurangi stok dua kali, padahal yang dimaksud hanya sekali.
 */
class KurangiStokRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'qty' => ['required', 'integer', 'min:1', 'max:'.OpnameService::MAX_COUNTED_QTY],
            'expected_qty' => ['required', 'integer', 'min:0', 'max:'.OpnameService::MAX_COUNTED_QTY],
        ];
    }

    public function attributes(): array
    {
        return [
            'qty' => 'jumlah dikurangi',
            'expected_qty' => 'stok saat dialog dibuka',
        ];
    }

    public function messages(): array
    {
        return [
            'qty.required' => 'Masukkan jumlah unit yang ingin dikurangi.',
            'qty.integer' => 'Jumlah yang dikurangi harus berupa bilangan bulat.',
            'qty.min' => 'Jumlah yang dikurangi minimal 1 unit.',
            'qty.max' => 'Jumlah yang dikurangi terlalu besar; periksa lagi.',
            'expected_qty.required' => 'Muat ulang halaman lalu buka dialog Kurangi Stok lagi.',
            'expected_qty.integer' => 'Stok saat dialog dibuka tidak valid. Muat ulang halaman.',
            'expected_qty.min' => 'Stok saat dialog dibuka tidak valid. Muat ulang halaman.',
            'expected_qty.max' => 'Stok saat dialog dibuka tidak valid. Muat ulang halaman.',
        ];
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
