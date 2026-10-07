<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

class UploadImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'import_file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'import_file.required' => 'Pilih berkas Excel/Csv terlebih dahulu.',
            'import_file.mimes' => 'Berkas harus berjenis .xlsx, .xls, atau .csv.',
            'import_file.max' => 'Ukuran berkas maksimal 4 MB.',
        ];
    }
}
