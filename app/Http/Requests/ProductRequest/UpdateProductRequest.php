<?php

namespace App\Http\Requests\ProductRequest;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\PackagingType;
use App\Enums\ProductStatus;
use App\Http\Requests\Concerns\NormalizesNumbers;
use App\Support\Enums;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
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
            'default_list_price' => 'integer',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'series_id' => ['nullable', 'integer', Rule::exists('product_series', 'id')],
            'casting_code' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'between:1900,2100'],
            'color' => ['nullable', 'string', 'max:191'],
            'packaging_type' => [Rule::in(Enums::values(PackagingType::class))],
            'card_condition' => [Rule::in(Enums::values(CardCondition::class))],
            'blister_condition' => [Rule::in(Enums::values(BlisterCondition::class))],
            'factory_barcode_ref' => ['nullable', 'string', 'max:30'],
            'default_list_price' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'tags' => ['nullable', 'string', 'max:255'],
            'status' => [Rule::in(Enums::values(ProductStatus::class))],
        ];
    }
}
