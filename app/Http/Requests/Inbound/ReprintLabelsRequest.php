<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbound;

use App\Enums\LabelReason;
use App\Http\Requests\Concerns\RequiresOwnerPin;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Auth\PinService;
use App\Services\Inventory\ReprintLimit;
use App\Services\Inventory\ReprintVerdict;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Buat job cetak ulang untuk sekumpulan lot (FR-IB-21).
 *
 * Alasan WAJIB, dan `INITIAL` tidak boleh dipilih. Tanpa alasan, `label_print_jobs`
 * tidak bisa menjelaskan kenapa satu lot punya label lebih banyak dari barangnya,
 * dan laporan "stiker liar" yang jadi tujuan FR-IB-22 tidak akan bisa dipakai.
 *
 * PIN Owner di sini KONDISIONAL, dan itu tidak bisa ditulis sebagai aturan
 * `required` biasa. `ownerPinRules()` dari trait mengembalikan aturan token
 * hanya ketika `requiresOwnerPin()` bilang perlu -- jadi trait itu sendiri
 * yang bertanya pada `ReprintLimit`. Kalau tidak begitu, setiap Staff yang
 * mau cetak ulang satu label untuk barang yang labelnya sobek akan diminta
 * PIN Owner, yaitu membatalkan seluruh tujuan cetakan ulang.
 */
class ReprintLabelsRequest extends FormRequest
{
    use RequiresOwnerPin;

    /**
     * Nama aksi yang dicantumkan di dalam token.
     *
     * Sengaja bukan `global`: token yang bocor dari cetak ulang tidak boleh
     * berlaku untuk void transaksi atau override skema komisi.
     */
    protected function ownerPinContext(): string
    {
        return 'inventory.label-overprint';
    }

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
            // Disebar, bukan ditulis sebagai `'pin_token' => ...` sendiri: saat
            // batas tidak terlampaui, daftar ini kosong dan field-nya tidak
            // diperiksa sama sekali -- bukan sekadar opsional.
            ...$this->ownerPinRules(),
        ];
    }

    public function messages(): array
    {
        $messages = [
            'reason.required' => 'Cetak ulang harus punya alasan.',
            'reason.in' => 'Alasan cetak ulang tidak dikenal.',
            'copies.min' => 'Jumlah label minimal 1.',
            'copies.max' => 'Jumlah label per permintaan maksimal 200.',
        ];

        // "Verifikasi PIN Owner belum dilakukan" sendirian tidak menjelaskan
        // apa pun: operator sedang memegang barang yang labelnya hilang, dan
        // dia perlu tahu lot mana yang sudah penuh sebelum dia naik ke Owner.
        // Kalimatnya dipasang di sini, bukan ditambahkan belakangan lewat
        // `withValidator()`, karena error yang ditambahkan setelah aturan
        // dievaluasi tidak membuat validasi gagal.
        if ($this->verdict()->needsOwnerPin()) {
            $messages['pin_token.required'] = $this->verdict()->summary()
                .' Minta PIN Owner untuk melanjutkan.';
        }

        return $messages;
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
     * Hitung dari input mentah, bukan dari `validated()`: `requiresOwnerPin()`
     * dipanggil saat `rules()` disusun -- yaitu SEBELUM validasi selesai --
     * jadi membaca `validated()` di sini akan selalu mengembalikan kosong dan
     * PIN-nya tidak akan pernah diminta sama sekali.
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
     * Siapa yang menotorisasi cetakan yang melewati batas, kalau ada.
     *
     * Dikembalikan ke `LabelPrintService` untuk disimpan di
     * `label_print_jobs.approved_by` (FR-AUTH-01). Owner yang Cetak melebihi
     * batas tetap tercatat atas namanya sendiri: dia memang berwenang, tapi
     * kejadiannya harus muncul di laporan anomali (FR-RP-22) -- dan tidak ada
     * `approved_by` yang bisa diisi kalau Owner melewatinya tanpa dicatat.
     *
     * `null` berarti tidak ada yang perlu dinotorisasi: cetakan ini dalam batas,
     * dan pemanggilnya berwenang atas aksinya sendiri.
     */
    public function reprintApprover(): ?int
    {
        $user = $this->user();

        if (! $this->verdict()->isBreached()) {
            return null;
        }

        if ($user?->isOwner()) {
            return (int) $user->getKey();
        }

        $approver = app(PinService::class)->approverFor(
            $user,
            $this->input($this->ownerPinField()),
            $this->ownerPinContext(),
        );

        return $approver instanceof User ? (int) $approver->getKey() : null;
    }

    /**
     * Di sini pemeriksaan PIN bukan soal hak akses, tapi soal integritas data:
     * Owner tidak perlu mengotorisasi dirinya sendiri, sedangkan
     * `ReprintVerdict` yang memutuskan -- dia boleh melewati batas, dia tidak.
     */
    protected function requiresOwnerPin(): bool
    {
        return $this->verdict()->needsOwnerPin();
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
