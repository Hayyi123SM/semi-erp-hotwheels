<?php

declare(strict_types=1);

namespace App\Http\Requests\Master;

use App\Models\Rack;
use App\Services\Label\LabelGeometry;
use App\Services\Label\LabelTemplate;
use App\Services\Label\RackCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Cetak label rak untuk put-away dan opname (FR-MD-21).
 *
 * Yang divalidasi di sini bukan cuma bentuk input, tapi juga apakah kode rak
 * benar-benar muat di ukuran label yang dipilih. Label rak terpotong lebih
 * berbahaya daripada tidak ada labelnya: dua rak bersebelahan bisa tampil
 * sama persis, lalu isinya tertukar saat put-away. Jadi rak dengan kode
 * kepanjangan ditolak dan diberi tahu rak mana, bukan dicetak lebih kecil
 * supaya muat.
 *
 * Prefix `RK:` ikut dihitung karena itulah yang benar-benar keluar dari
 * printer dan itulah yang dibaca scanner.
 */
class PrintRackLabelsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rack_ids' => ['required', 'array', 'min:1', 'max:200'],
            'rack_ids.*' => ['required', 'integer', 'distinct', 'exists:racks,id'],
            'template' => ['required', Rule::in($this->templateValues())],
            'copies' => ['required', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'rack_ids.required' => 'Pilih minimal satu rak untuk dicetak labelnya.',
            'rack_ids.min' => 'Pilih minimal satu rak untuk dicetak labelnya.',
            'rack_ids.max' => 'Maksimal 200 rak per halaman cetak.',
            'copies.min' => 'Jumlah label minimal 1.',
            'copies.max' => 'Jumlah label per rak maksimal 200.',
            'template.in' => 'Ukuran label rak tidak dikenal.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->checkCodesFit($validator);
        });
    }

    /**
     * @return list<int>
     */
    public function rackIds(): array
    {
        return array_map('intval', (array) $this->validated('rack_ids'));
    }

    public function copies(): int
    {
        return (int) $this->validated('copies');
    }

    public function template(): LabelTemplate
    {
        return LabelTemplate::parse((string) $this->validated('template'));
    }

    /**
     * @return list<string>
     */
    private function templateValues(): array
    {
        return array_map(
            static fn (LabelTemplate $template): string => $template->value,
            LabelTemplate::cases(),
        );
    }

    private function checkCodesFit(Validator $validator): void
    {
        $template = $this->rawTemplate();

        if (! $template instanceof LabelTemplate) {
            return;
        }

        /*
         * Label rak QR-only tidak mencetak kode rak sama sekali -- kodenya cuma
         * ada di dalam QR, yang muat jauh lebih panjang dari kolom teks mana
         * pun. Jadi tidak ada yang bisa terpotong dan tidak ada yang perlu
         * diperiksa. Lewati di sini sekaligus mencegah akses
         * `$geometry->rows[0]` pada geometri yang memang tidak punya baris.
         */
        if ($template->isQrOnly()) {
            return;
        }

        $geometry = LabelGeometry::forRack($template);
        $capacity = $geometry->capacityFor($geometry->rows[0]);
        $tooLong = [];

        foreach ($this->rawRackCodes() as $code) {
            $printed = mb_strlen(RackCode::printable($code));

            if ($printed > $capacity) {
                $tooLong[] = $printed.' karakter ('.$code.')';
            }
        }

        if ($tooLong === []) {
            return;
        }

        $validator->errors()->add('rack_ids', sprintf(
            'Kode rak %s tidak muat di label %s yang hanya menampung %d karakter. '
            .'Perpendek kode rak, atau cetak dengan ukuran 4x3 cm.',
            implode(', ', $tooLong),
            $this->templateSize($template),
            $capacity,
        ));
    }

    /**
     * Kode rak dari database, persis seperti yang akan dirender.
     *
     * @return list<string>
     */
    private function rawRackCodes(): array
    {
        $ids = $this->input('rack_ids');

        if (! is_array($ids)) {
            return [];
        }

        return Rack::query()
            ->whereKey($ids)
            ->pluck('code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();
    }

    private function rawTemplate(): ?LabelTemplate
    {
        $value = $this->input('template');

        return is_string($value) ? LabelTemplate::tryFrom($value) : null;
    }

    private function templateSize(LabelTemplate $template): string
    {
        return sprintf('%.1f x %.1f cm', $template->widthCm(), $template->heightCm());
    }
}
