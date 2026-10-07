<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body dari `POST /pin/verify`.
 *
 * Sengaja TIDAK memakai `NormalizesNumbers`: trait itu membaca angka yang diketik
 * manusia dan membuang pemisah ribuan, sedangkan PIN bukan angka. Menyeragamkan
 * `12-34` menjadi `1234` akan mengubah entri yang mestinya ditolak jadi entri
 * yang diterima, dan `digits` sudah cukup untuk menyaringnya.
 */
class VerifyPinRequest extends FormRequest
{
    /** Nama aksi yang dicantumkan di dalam token, mis. `consignment.scheme-override`. */
    public const string DEFAULT_CONTEXT = 'global';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'digits:6'],
            'context' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'pin.required' => 'PIN Owner wajib diisi.',
            'pin.digits' => 'PIN Owner harus 6 digit angka.',
        ];
    }

    public function attributes(): array
    {
        return [
            'pin' => 'PIN Owner',
            'context' => 'konteks aksi',
        ];
    }

    /**
     * Konteks yang dicantumkan di dalam token.
     *
     * Token hanya sah untuk konteks yang sama saat diperiksa, jadi konteks yang
     * sampai ke sini ikut menentukan apakah token ini akan dipakai lagi atau
     * ditolak karena milik aksi lain.
     */
    public function pinContext(): string
    {
        $context = $this->validated('context');

        return is_string($context) && $context !== ''
            ? $context
            : self::DEFAULT_CONTEXT;
    }
}
