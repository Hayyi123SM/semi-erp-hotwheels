<?php

namespace App\Http\Requests\ConsignorRequest;

use App\Rules\UniqueWhatsappNumber;
use App\Rules\WhatsappNumberFormat;
use Illuminate\Validation\Rule;

/**
 * Jalur edit, bukan salinan yang berdiri sendiri.
 *
 * Dua kelas ini pernah kembar, dan yang berbeda hanya `UniqueWhatsappNumber`
 * yang harus melompati baris yang sedang diedit. Setelah persetujuan WhatsApp
 * ditambahkan hanya ke kelas `Store`, kedua jalur itu melenceng: dicatat saat
 * membuat, diam-diam tidak bisa dicabut saat mengedit. `extends` membuat
 * `rules()` harus menyalin apa yang sudah benar, jadi satu-satunya yang bisa
 * tersisa hanyalah perbedaan yang memang ada.
 */
class UpdateConsignorRequest extends StoreConsignorRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['wa_number'] = [
            Rule::requiredIf($this->boolean('wa_opt_in')),
            'nullable',
            'string',
            'max:30',
            new WhatsappNumberFormat,
            new UniqueWhatsappNumber($this->route('consignor')),
        ];

        return $rules;
    }
}
