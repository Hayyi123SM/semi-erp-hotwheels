<?php

namespace App\Http\Requests\ConsignorRequest;

use App\Enums\ConsignorStatus;
use App\Enums\DiscountPolicy;
use App\Enums\LossLiability;
use App\Enums\SchemeType;
use App\Enums\SettlementCycle;
use App\Http\Requests\Concerns\NormalizesNumbers;
use App\Rules\UniqueWhatsappNumber;
use App\Rules\WhatsappNumberFormat;
use App\Support\Enums;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConsignorRequest extends FormRequest
{
    use NormalizesNumbers {
        // The trait owns the name this class now needs for the opt-in timestamp,
        // so the trait's version is kept reachable under a private name instead
        // of being silently replaced.
        prepareForValidation as private normalizeNumbers;
    }

    /**
     * Which of these fields are read as numbers, and how.
     *
     * @return array<string, 'integer'|'rate'>
     */
    protected function normalizableNumbers(): array
    {
        return [
            'scheme_rate' => 'rate',
            'scheme_amount' => 'integer',
            'min_payout' => 'integer',
        ];
    }

    /**
     * The checkbox becomes the timestamp the column holds.
     *
     * A box that was ticked but not sent still means "not ticked", so a plain
     * `merge()` on the stored value would drop every reader's choice. A missing
     * key is therefore false, not absent: unticking writes null so that a
     * revocation is a decision on record rather than a field left untouched.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeNumbers();

        $this->merge([
            'wa_opt_in' => $this->boolean('wa_opt_in'),
            'wa_opt_in_at' => $this->boolean('wa_opt_in') ? now() : null,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $owner = $this->user()?->isOwner();
        $optedIn = $this->boolean('wa_opt_in');

        return [
            'name' => ['required', 'string', 'max:255'],
            // An opt-in with nowhere to send the message is a contradiction, so
            // the number stops being optional the moment consent is claimed.
            'wa_number' => [Rule::requiredIf($optedIn), 'nullable', 'string', 'max:30', new WhatsappNumberFormat, new UniqueWhatsappNumber],
            'wa_opt_in' => ['boolean'],
            'wa_opt_in_at' => ['nullable', 'date'],
            'address' => ['nullable', 'string'],
            'agreement_date' => ['nullable', 'date'],
            'scheme_type' => array_merge($owner ? ['required'] : [], [Rule::in(Enums::values(SchemeType::class))]),
            'scheme_rate' => array_merge(
                $owner ? [Rule::requiredIf($this->input('scheme_type') === 'PERCENTAGE')] : [],
                ['nullable', 'numeric', 'between:0,100'],
            ),
            'scheme_amount' => array_merge(
                $owner ? [Rule::requiredIf(in_array($this->input('scheme_type'), ['NETT', 'FLAT']))] : [],
                ['nullable', 'integer', 'min:0', 'max:4294967295'],
            ),
            'discount_policy' => [Rule::in(Enums::values(DiscountPolicy::class))],
            'loss_liability' => [Rule::in(Enums::values(LossLiability::class))],
            'settlement_cycle' => [Rule::in(Enums::values(SettlementCycle::class))],
            'min_payout' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'bank_name' => ['nullable', 'string', 'max:191'],
            'bank_account' => ['nullable', 'string', 'max:191'],
            'bank_holder' => ['nullable', 'string', 'max:191'],
            'status' => [Rule::in(Enums::values(ConsignorStatus::class))],
            'notes' => ['nullable', 'string'],
        ];
    }
}
