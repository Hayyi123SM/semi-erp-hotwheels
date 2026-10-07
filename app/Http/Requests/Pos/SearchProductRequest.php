<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pencarian produk untuk kasir.
 *
 * Satu request untuk dua bentuk pencarian karena keduanya berakhir di tempat yang
 * sama di layar -- panel picker -- dan bentuk kuerinya memang berbeda: `q` untuk
 * teks yang diketik, `barcode` untuk hasil pindai.
 *
 * Batas panjangnya longgar, bukan angka yang terlihat meyakinkan. Nilai yang
 * kepanjangan tidak akan menghasilkan apa-apa, sedangkan memotong nilai yang sah
 * secara diam-diam berarti kasir mengetik SKU yang ada dan mendengar "tidak
 * ditemukan" karena ujungnya sudah terpotong. Batasnya hanya menahan satu
 * request yang jelas bukan pencarian, bukan mengatur isinya.
 */
class SearchProductRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.max' => 'Kata kunci pencarian terlalu panjang.',
            'barcode.max' => 'Kode barcode terlalu panjang.',
        ];
    }

    /**
     * Teks yang diketik, sudah dirapikan.
     *
     * String kosong, bukan `null`: pemanggilnya memakai nilai ini untuk memutuskan
     * apakah perlu query, dan `''` sudah menjawab itu tanpa perlu memeriksa panjang
     * string di dua tempat.
     */
    public function term(): string
    {
        return trim((string) $this->validated('q'));
    }

    /**
     * Kode hasil pindai, sudah dirapikan, atau `null` kalau permintaannya bukan
     * pencarian barcode.
     *
     * Bedanya dari {@see self::term()} disengaja. Kode yang kosong berarti "kolomnya
     * tidak ada isinya", yang bukan pencarian dan tidak boleh membangkitkan query;
     * teks yang kosong berarti "kasir masih mengetik", yang memang belum bisa
     * dijawab.
     */
    public function barcode(): ?string
    {
        $barcode = $this->validated('barcode');

        if (! is_string($barcode) || trim($barcode) === '') {
            return null;
        }

        return trim($barcode);
    }
}
