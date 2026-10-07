<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Http\Requests\Concerns\NormalizesNumbers;
use App\Services\Pos\PosSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Simpan pengaturan POS dari halaman Parameter Sistem.
 *
 * Dua nilai, keduanya dibaca server saat kasir bekerja: batas diskon saat kasir
 * memotong harga, ambang selisih kas saat shift ditutup. Karena itu tidak ada
 * satu pun yang boleh lolos sebagai teks bebas: form yang menyimpannya dan
 * pembacaan yang memakainya harus memakai representasi yang sama, supaya layar
 * dan server tidak pernah punya pendapat berbeda.
 *
 * Kertas struk POS tidak lagi masuk form ini -- kertas sudah jadi pengaturan
 * global di halaman Perangkat (`PrintSettings`), jadi tidak ada `paper` untuk
 * divalidasi di sini.
 */
class SavePosSettingsRequest extends FormRequest
{
    use NormalizesNumbers;

    public function rules(): array
    {
        return [
            'staff_discount_limit_percent' => ['required', 'integer', 'min:0', 'max:'.PosSettings::MAX_DISCOUNT_PERCENT],
            'cash_difference_threshold' => ['required', 'integer', 'min:0', 'max:'.PosSettings::MAX_AMOUNT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'staff_discount_limit_percent.required' => 'Isi batas diskon kasir, pakai 0 untuk melarang diskon.',
            'staff_discount_limit_percent.integer' => 'Batas diskon harus angka bulat.',
            'staff_discount_limit_percent.min' => 'Batas diskon tidak bisa negatif.',
            'staff_discount_limit_percent.max' => 'Batas diskon maksimal 100 persen.',
            'cash_difference_threshold.required' => 'Isi ambang selisih kas, pakai 0 untuk mewajibkan persetujuan Owner setiap tutup shift.',
            'cash_difference_threshold.integer' => 'Ambang selisih kas harus angka bulat.',
            'cash_difference_threshold.min' => 'Ambang selisih kas tidak bisa negatif.',
            'cash_difference_threshold.max' => 'Ambang selisih kas terlalu besar.',
        ];
    }

    /**
     * @return array<string, 'integer'>
     */
    protected function normalizableNumbers(): array
    {
        return [
            'staff_discount_limit_percent' => 'integer',
            'cash_difference_threshold' => 'integer',
        ];
    }

    /**
     * Nilai yang akan disimpan, dalam bentuk `PosSettings::toPersisted()`.
     *
     * Dipetakan lewat `PosSettings` supaya nama field form dan key yang tersimpan
     * hanya ditulis di satu tempat. Controller cukup menyimpan hasilnya dan tidak
     * perlu tahu nama key-nya.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return app(PosSettings::class)->toPersisted([
            'staff_discount_limit_percent' => $this->validated('staff_discount_limit_percent'),
            'cash_difference_threshold' => $this->validated('cash_difference_threshold'),
        ]);
    }
}
