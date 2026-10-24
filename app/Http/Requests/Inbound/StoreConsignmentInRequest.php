<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbound;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\DiscountPolicy;
use App\Enums\SchemeType;
use App\Support\Enums;
use App\Support\Numbers;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Penerimaan barang titipan, satu dokumen banyak baris.
 *
 * Setiap baris membawa syarat skemanya sendiri, karena satu baris adalah satu
 * kombinasi (produk, kondisi, harga, skema) dan dua baris dengan produk yang sama
 * boleh berbeda skema. Baris yang tidak menyebut apa pun memakai default profil
 * penitip -- jadi form yang diisi seperlunya tetap bisa commit seperti sebelumnya.
 */
class StoreConsignmentInRequest extends FormRequest
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
            $row['list_price'] = $this->normalizePositive($row['list_price'] ?? null);

            // Hanya parameter skema terpilih yang dinormalisasi. Baris PERCENTAGE
            // yang ikut mengirim `scheme_amount` tidak ditolak karena field itu, tapi
            // juga tidak dipakai -- jadi tidak ada angka-half yang ikut terpotong.
            $scheme = $row['scheme_type'] ?? null;

            if ($scheme === SchemeType::Percentage->value) {
                $row['scheme_rate'] = Numbers::rate($row['scheme_rate'] ?? null);
                $row['scheme_amount'] = null;
            }

            if ($scheme === SchemeType::Nett->value || $scheme === SchemeType::Flat->value) {
                $row['scheme_amount'] = $this->normalizePositive($row['scheme_amount'] ?? null);
                $row['scheme_rate'] = null;
            }

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
            'consignor_id' => ['required', 'integer', 'exists:consignors,id'],
            'consignment_date' => ['required', 'date', 'before_or_equal:today'],
            'source' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'verified' => ['required', 'accepted'],

            // Dikirim hanya kalau form ini melanjutkan draft. Tidak divalidasi
            // sebagai `exists` karena owning dicek ulang di service: draft milik
            // orang lain diperlakukan sebagai "tidak ada draft", bukan error.
            'draft_id' => ['nullable', 'uuid'],

            // `qty_claimed` adalah angka yang diklaim penitip, bukan yang Staff
            // hitung. Kalau kosong, commit memakai `qty_received` supaya dokumen
            // lama yang tidak punya kolom ini tidak terlihat punya selisih palsu.
            'qty_claimed' => ['nullable', 'integer', 'between:0,99999'],
            'variance_note' => ['nullable', 'string', 'max:1000'],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'between:1,999'],
            'items.*.rack_id' => ['nullable', 'integer', 'exists:racks,id'],
            'items.*.card_condition' => ['nullable', Rule::in(Enums::values(CardCondition::class))],
            'items.*.blister_condition' => ['nullable', Rule::in(Enums::values(BlisterCondition::class))],

            ...$this->schemeRules(),
        ];
    }

    /**
     * Aturan syarat skema untuk `items.*`.
     *
     * Parameter skema dipilih lewat `SchemeType::parameterField()`, bukan ditulis
     * di sini sebagai pasangan nama, supaya "PERCENTAGE berarti rate, NETT dan
     * FLAT berarti amount" hanya punya satu definisi. Field parameter yang tidak
     * dipakai skema baris itu tidak divalidasi, karena `prepareForValidation()`
     * sudah mengosongkannya -- sisa kolom dari skema sebelumnya bukan kesalahan.
     *
     * Rentang terhadap harga jual ikut di sini, sebagai aturan, bukan sebagai
     * pesan yang ditambahkan di `withValidator()`: `Validator::passes()` mengosongkan
     * message bag sebelum aturan dievaluasi, sehingga error yang ditambahkan lebih
     * awal hilang tanpa pernah menggagalkan request. Aturan yang ditambahkan lewat
     * `after()` tetap bertahan, tapi bentuk itu membuat penolakan terasa seperti
     * efek samping, bukan bagian dari skema.
     *
     * @return array<string, list<mixed>>
     */
    private function schemeRules(): array
    {
        $rules = [
            'items.*.list_price' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'items.*.scheme_type' => ['nullable', Rule::in(Enums::values(SchemeType::class))],
            'items.*.discount_policy' => ['nullable', Rule::in(Enums::values(DiscountPolicy::class))],
        ];

        foreach (SchemeType::cases() as $type) {
            // `required_if` mengikuti skema yang benar-benar dipilih di baris itu:
            // memilih skema tanpa mengisi parameternya adalah setengah skema, dan
            // lot yang begitu tidak bisa ditagih. Rules di `items.*.scheme_type`
            // juga sudah menolak nilai yang bukan enum, jadi `from()` di sini
            // tidak pernah menerima apa pun selain hasil `in`.
            $rules["items.*.{$type->parameterField()}"] = match ($type) {
                SchemeType::Percentage => [
                    'nullable',
                    'required_if:items.*.scheme_type,PERCENTAGE',
                    'numeric',
                    'gt:0',
                    'lte:100',
                ],
                SchemeType::Nett, SchemeType::Flat => [
                    'nullable',
                    'required_if:items.*.scheme_type,NETT',
                    'required_if:items.*.scheme_type,FLAT',
                    'integer',
                    'min:1',
                    'max:100000000',
                    $this->parameterFitsPrice($type),
                ],
            };
        }

        return $rules;
    }

    /**
     * Nett dan flat harus lebih kecil dari harga jual baris itu.
     *
     * BR-06 memblokir margin negatif. Yang boleh melewati blokir itu adalah
     * Owner yang melakukan aksinya sendiri: aksi ini memang miliknya, jadi
     * meminta otorisasi tambahan darinya hanya menambah langkah tanpa menambah
     * lapis pengaman.
     *
     * Hanya berlaku kalau harga baris dikirim: kalau tidak, harga ikut default
     * produk dan rentangnya baru bisa dinilai setelah baris benar-benar dibangun,
     * yang dikerjakan `TermsCalculator` -- yang menandainya dengan
     * `negative_margin_flag`.
     */
    private function parameterFitsPrice(SchemeType $type): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($type): void {
            if ($this->user()?->isOwner()) {
                return;
            }

            $row = $this->rowAt($attribute);

            if ($row === null) {
                return;
            }

            $price = $row['list_price'] ?? null;

            if ($price === null || $price === '') {
                return;
            }

            if ((int) $value >= (int) $price) {
                $fail($type === SchemeType::Nett
                    ? 'Harga nett harus lebih kecil dari harga jual baris ini.'
                    : 'Fee flat harus lebih kecil dari harga jual baris ini.');
            }
        };
    }

    /**
     * Baris form yang memuat sebuah `items.{index}.*`.
     *
     * @return array<string, mixed>|null
     */
    private function rowAt(string $attribute): ?array
    {
        if (preg_match('/^items\.(\d+)\./', $attribute, $matches) !== 1) {
            return null;
        }

        $row = $this->input("items.{$matches[1]}");

        return is_array($row) ? $row : null;
    }
}
