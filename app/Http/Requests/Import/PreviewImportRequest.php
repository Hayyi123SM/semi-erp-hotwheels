<?php

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

class PreviewImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Deliberately no number normalization here.
     *
     * The import path already reduces every column as it is read, in
     * `ImportManager::typedValue()`, against the same `Numbers` helper the forms
     * use. Normalizing again at the request would be the same answer twice, and
     * it would have to be derived from the schema to be right -- which means an
     * unknown module would throw out of `ImportSchemas::for()` here, before the
     * controller, where it is a 500 rather than whatever it was before.
     *
     * These rules have no numeric clause anyway: a grouped figure out of a
     * spreadsheet is a perfectly good string, and refusing it at the door would
     * turn the exact input the importer exists to accept into a validation
     * error.
     */
    public function rules(): array
    {
        return [
            'header_row' => ['required', 'integer', 'min:0', 'max:100'],
            'map' => ['required', 'array'],
            'map.*' => ['nullable', 'string', 'max:3'],
            'defaults' => ['nullable', 'array'],
            'defaults.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
