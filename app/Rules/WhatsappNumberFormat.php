<?php

namespace App\Rules;

use App\Support\WhatsappNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Nomor WhatsApp harus bisa menjadi tujuan `wa.me`.
 *
 * Aturan `regex` biasa tidak bisa dipakai di sini karena yang diperiksa adalah
 * hasil normalisasi, bukan string yang diketik. `0812-3456-7890` gagal pola
 * apa pun yang menuntut awalan `62`, padahal dia nomor yang sah -- dan satu-
 * satunya cara mengetahuinya adalah dengan merapikannya lebih dulu, persis
 * seperti yang disimpan.
 *
 * Normalisasi ada di `App\Support\WhatsappNumber`, bukan di sini, supaya bentuk
 * yang divalidasi dan bentuk yang disimpan tidak pernah berbeda.
 */
class WhatsappNumberFormat implements ValidationRule
{
    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! WhatsappNumber::isValid($value)) {
            $fail('Nomor WhatsApp tidak valid. Gunakan format internasional seperti +62 812-3456-7890.');
        }
    }
}
