<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExpenseImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ];
    }

    public function attributes(): array
    {
        return [
            'file' => 'file CSV',
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'File harus berformat CSV (.csv) atau TXT (.txt).',
        ];
    }
}
