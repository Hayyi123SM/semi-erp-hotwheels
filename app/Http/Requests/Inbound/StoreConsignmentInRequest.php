<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbound;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\DiscountPolicy;
use App\Enums\SchemeType;
use App\Http\Requests\Concerns\RequiresOwnerPin;
use App\Models\Consignor;
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
    use RequiresOwnerPin;

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

            ...$this->ownerPinRules(),
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
     * BR-06 memblokir margin negatif "kecuali dengan PIN Owner". Yang boleh
     * melewati blokir itu adalah Owner yang melakukan aksinya sendiri -- PIN Owner
     * di commit ini berutang atas penyimpangan skema, bukan atas lot yang rugi,
     * jadi membiarkan Staff melewati blokir hanya karena kebetulan membawa token
     * yang sah akan membuka jalan ke lot negatif tanpa pernah ditandai sebagai
     * keputusan Owner.
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

    /**
     * Konteks token: aksi ini menimpa skema bawaan kontrak penitip.
     */
    protected function ownerPinContext(): string
    {
        return 'consignment.scheme-override';
    }

    /**
     * PIN Owner hanya diminta kalau ada baris yang benar-benar menyimpang.
     *
     * Dokumen menyatakan ini sebagai aturan per-aksi, bukan per-orang: Staff
     * memakai skema default penitip, dan mengubahnya di luar default butuh PIN
     * Owner. Jadi kalau semua baris memakai default, tidak ada yang berubah dan
     * tidak ada yang perlu diotorisasi -- dan PIN Owner tidak diminta untuk
     * sesuatu yang tidak terjadi.
     *
     * Owner sendiri tidak diminta, baik saat menyimpang maupun tidak: aksi ini
     * memang miliknya.
     */
    protected function requiresOwnerPin(): bool
    {
        if ($this->user()?->isOwner()) {
            return false;
        }

        return $this->hasSchemeDeviation();
    }

    /**
     * Apakah ada baris yang skema atau harganya berbeda dari kontrak penitip.
     *
     * Perbandingan dilakukan terhadap profil penitip yang dipilih, bukan terhadap
     * apa yang tidak dikirim form: baris yang.price-nya tidak diisi berarti
     * "default produk", dan itu default penitip -- jadi termasuk tidak menyimpang.
     */
    public function hasSchemeDeviation(): bool
    {
        $consignor = $this->selectedConsignor();

        if ($consignor === null) {
            // Tanpa penitip tidak ada default untuk dibandingkan. Form sudah akan
            // ditolak oleh rule `consignor_id`, dan membiarkan aturan PIN ikut
            // menolak akan menampilkan dua kesalahan untuk satu sebab.
            return false;
        }

        foreach ($this->input('items', []) as $row) {
            if ($this->rowDeviates($row, $consignor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowDeviates(array $row, Consignor $consignor): bool
    {
        // `list_price` yang tidak dikirim berarti harga list produk, yang tidak
        // tersedia di sini. Baris seperti itu tidak menyimpang dari kontrak skema.
        if (isset($row['list_price']) && $row['list_price'] !== '') {
            return true;
        }

        $scheme = $row['scheme_type'] ?? null;

        if (! is_string($scheme) || $scheme === '') {
            return false;
        }

        if ($scheme !== $consignor->scheme_type?->value) {
            return true;
        }

        $parameterField = SchemeType::from($scheme)->parameterField();
        $submitted = $row[$parameterField] ?? null;

        if ($submitted === null || $submitted === '') {
            // Tidak mengirim parameter berarti memakai default, jadi tidak
            // menyimpang -- kecuali defaultnya memang tidak ada, yang ditangani
            // guard skema di service.
            return false;
        }

        $default = $consignor->{$parameterField};

        // Persentase dibandingkan sebagai float supaya "20" dari form dan 20.0
        // dari database tidak dibaca sebagai dua skema berbeda.
        if ($parameterField === 'scheme_rate') {
            return abs((float) $submitted - (float) $default) > 0.0001;
        }

        return (int) $submitted !== (int) $default;
    }

    private function selectedConsignor(): ?Consignor
    {
        $id = $this->input('consignor_id');

        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        return Consignor::query()->find($id);
    }
}
