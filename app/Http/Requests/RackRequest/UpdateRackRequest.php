<?php

namespace App\Http\Requests\RackRequest;

use App\Enums\RackType;
use App\Http\Requests\Concerns\NormalizesNumbers;
use App\Support\Enums;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRackRequest extends FormRequest
{
    use NormalizesNumbers;

    /**
     * Which of these fields are read as numbers, and how.
     *
     * @return array<string, 'integer'|'rate'>
     */
    protected function normalizableNumbers(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]*$/', Rule::unique('racks', 'code')->ignore($this->route('rack'))],
            'zone' => ['nullable', 'string', 'max:10'],
            'type' => [Rule::in(Enums::values(RackType::class))],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
