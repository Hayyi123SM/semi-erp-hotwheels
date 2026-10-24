<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbound;

use App\Enums\LabelReason;
use App\Models\StockLot;
use App\Services\Inventory\ReprintLimit;
use App\Services\Inventory\ReprintVerdict;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Buat job cetak ulang untuk sekumpulan lot (FR-IB-21).
 *
 * Alasan WAJIB, dan `INITIAL` tidak boleh dipilih. Tanpa alasan, `label_print_jobs`
 * tidak bisa menjelaskan kenapa satu lot punya label lebih banyak dari barangnya,
 * dan laporan "stiker liar" yang jadi tujuan FR-IB-22 tidak akan bisa dipakai.
 *
 * Cetakan yang melewati batas tetap boleh lewat, tapi tidak pernah diam-diam:
 * `LabelPrintService` mencatatnya sebagai anomali. Yang menjadi penandatangan
 * cetakan itu adalah orang yang memintanya -- modul Inbound Assurance hanya
 * bisa dibuka Owner, jadi penandatangan itu sudah otomatis orang yang
 * berwenang, tanpa perlu otorisasi tambahan.
 */
class ReprintLabelsRequest extends FormRequest
{
    private ?ReprintVerdict $verdict = null;

    public function rules(): array
    {
        return [
            'lot_ids' => ['required', 'array', 'min:1', 'max:200'],
            'lot_ids.*' => ['required', 'integer', 'distinct', 'exists:stock_lots,id'],
            'copies' => ['required', 'integer', 'min:1', 'max:200'],
            'reason' => [
                'required',
                Rule::in(LabelReason::reprints()),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Cetak ulang harus punya alasan.',
            'reason.in' => 'Alasan cetak ulang tidak dikenal.',
            'copies.min' => 'Jumlah label minimal 1.',
            'copies.max' => 'Jumlah label per permintaan maksimal 200.',
        ];
    }

    /**
     * @return array<int, int>
     */
    public function lotIds(): array
    {
        return $this->validated('lot_ids');
    }

    public function copies(): int
    {
        return (int) $this->validated('copies');
    }

    public function reason(): LabelReason
    {
        return LabelReason::from((string) $this->validated('reason'));
    }

    /**
     * Hasil pemeriksaan batas, dihitung satu kali per request.
     *
     * Hitung dari input mentah, bukan dari `validated()`: `verdict()` dipakai
     * oleh aturan validasi, jadi membaca `validated()` di sini akan selalu
     * mengembalikan kosong dan batasnya tidak akan pernah dinilai.
     */
    public function verdict(): ReprintVerdict
    {
        if ($this->verdict instanceof ReprintVerdict) {
            return $this->verdict;
        }

        $ids = $this->rawLotIds();

        $this->verdict = $ids->isEmpty()
            ? new ReprintVerdict
            : app(ReprintLimit::class)->evaluate(
                StockLot::query()->whereKey($ids)->get(),
                (int) $this->input('copies', 0),
                $this->user(),
            );

        return $this->verdict;
    }

    /**
     * Cetakan yang melewati batas dan dipesan oleh orang yang tidak berwenang
     * ditolak di sini.
     *
     * Modul Inbound Assurance hanya bisa dibuka Owner, jadi jalur web selalu
     * dibawa Owner dan aturan ini praktis tidak pernah menggigit. Aturan ini
     * tetap ada sebagai pagar pengaman: kalau modul ini suatu saat dibuka
     * untuk Staff, cetakan yang melewati batas harus berhenti di validasi --
     * bukan menunggu orang lain yang tidak pernah akan menyetujuinya.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->verdict()->isBreachedByNonOwner()) {
                    return;
                }

                $validator->errors()->add('copies', $this->verdict()->summary());
            },
        ];
    }

    /**
     * @return Collection<int, int>
     */
    private function rawLotIds(): Collection
    {
        $input = $this->input('lot_ids');

        if (! is_array($input)) {
            return new Collection;
        }

        return collect($input)
            ->filter(static fn ($id): bool => is_numeric($id))
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }
}
