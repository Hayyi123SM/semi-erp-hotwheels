<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbound;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\DiscountPolicy;
use App\Enums\SchemeType;
use App\Support\Enums;
use App\Support\Numbers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Auto-save draft.
 *
 * Aturannya sengaja jauh lebih longgar daripada `StoreConsignmentInRequest`: draft
 * ada justru supaya form boleh dalam keadaan setengah jadi, dan kunci commit sudah
 * dipegang request commit. Menolak draft setengah isi berarti Staff dipaksa memilih
 * antara kehilangan pekerjaan atau mengarang nilai supaya lolos.
 *
 * Yang tetap dijaga: `exists` untuk product dan rack, rentang qty, dan nilai
 * skema yang masuk kategori. Jadi draft tidak bisa dipakai untuk menyelundupkan SKU
 * fiktif yang nanti tidak ketahuan sampai label dicetak.
 */
class SaveConsignmentDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $items = [];

        foreach ($this->input('items', []) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $row['qty'] = Numbers::integer($row['qty'] ?? null);
            $row['list_price'] = Numbers::integer($row['list_price'] ?? null);
            $row['scheme_amount'] = Numbers::integer($row['scheme_amount'] ?? null);

            if (($row['scheme_type'] ?? null) === SchemeType::Percentage->value) {
                $row['scheme_rate'] = Numbers::rate($row['scheme_rate'] ?? null);
                $row['scheme_amount'] = null;
            }

            if (in_array($row['scheme_type'] ?? null, [SchemeType::Nett->value, SchemeType::Flat->value], true)) {
                $row['scheme_amount'] = Numbers::integer($row['scheme_amount'] ?? null);
                $row['scheme_rate'] = null;
            }

            $items[$index] = $row;
        }

        $this->merge(['items' => $items]);
    }

    public function rules(): array
    {
        return [
            'consignor_id' => ['nullable', 'integer', 'exists:consignors,id'],
            'consignment_date' => ['nullable', 'date', 'before_or_equal:today'],
            'source' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'qty_claimed' => ['nullable', 'integer', 'between:0,99999'],
            'variance_note' => ['nullable', 'string', 'max:1000'],

            'items' => ['nullable', 'array', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['nullable', 'integer', 'between:1,999'],
            'items.*.rack_id' => ['nullable', 'integer', 'exists:racks,id'],
            'items.*.card_condition' => ['nullable', Rule::in(Enums::values(CardCondition::class))],
            'items.*.blister_condition' => ['nullable', Rule::in(Enums::values(BlisterCondition::class))],
            'items.*.list_price' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'items.*.scheme_type' => ['nullable', Rule::in(Enums::values(SchemeType::class))],
            'items.*.discount_policy' => ['nullable', Rule::in(Enums::values(DiscountPolicy::class))],
            'items.*.scheme_rate' => ['nullable', 'numeric', 'between:0,100'],
            'items.*.scheme_amount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
