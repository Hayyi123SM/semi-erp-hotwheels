<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbound;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Support\Enums;
use App\Support\Numbers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockInPribadiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $items = [];

        foreach ($this->input('items', []) as $index => $row) {
            $row['qty'] = $this->normalizePositive($row['qty'] ?? null);
            $row['cost_price'] = $this->normalizePositive($row['cost_price'] ?? null);
            $items[$index] = $row;
        }

        if ($items !== []) {
            $this->merge(['items' => $items]);
        }
    }

    /**
     * Angka positif boleh berkelompok ribuan (1.200 -> 1200).
     * Angka negatif dipertahankan apa adanya supaya rule `between` menolaknya.
     */
    private function normalizePositive(int|float|string|null $value): ?string
    {
        if (is_string($value) && preg_match('/^\s*-/', $value) === 1) {
            return $value;
        }

        return Numbers::integer($value);
    }

    public function rules(): array
    {
        return [
            'source' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'verified' => ['required', 'accepted'],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'between:1,999'],
            'items.*.cost_price' => ['required', 'integer', 'between:0,4294967295'],
            'items.*.rack_id' => ['nullable', 'integer', 'exists:racks,id'],
            'items.*.card_condition' => ['nullable', Rule::in(Enums::values(CardCondition::class))],
            'items.*.blister_condition' => ['nullable', Rule::in(Enums::values(BlisterCondition::class))],
        ];
    }
}
