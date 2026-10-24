<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos;

use App\Http\Requests\Concerns\NormalizesNumbers;
use App\Models\Shift;
use App\Services\Pos\PosSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Tutup shift kasir: berapa uang yang benar-benar ada di laci setelah penjualan.
 *
 * Selisih di luar ambang tidak ditolak di sini. Angka yang dikirim
 * disimpan apa adanya, lalu `ShiftService::requestClose()` memutuskan apakah
 * shift langsung ditutup atau berhenti di `PENDING_APPROVAL` untuk diputuskan
 * Owner. Validasi tidak perlu tahu siapa yang menutup: keputusan itu bergantung
 * pada peran aktor dan rekap dari database, dan keduanya adalah urusan service.
 *
 * Yang diotorisasi di `authorize()` adalah shift mana yang boleh ditutup, bukan
 * siapa yang menutupnya -- kasir menutup shiftnya sendiri, bukan shift orang
 * lain.
 */
class CloseShiftRequest extends FormRequest
{
    use NormalizesNumbers;

    /**
     * Shift milik sendiri boleh ditutup; shift orang lain milik laporan.
     *
     * Owner boleh menutup shift mana pun, karena closing shift oleh kasir yang
     * sedang tugas adalah hal yang wajar saat kasirnya hilang di tengah shift dan
     * tidak pernah datang untuk menutup shiftnya.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $shift = $this->shift();

        if ($user === null || $shift === null) {
            return false;
        }

        return $user->isOwner() || $shift->user_id === $user->getKey();
    }

    public function rules(): array
    {
        return [
            'closing_cash' => ['required', 'integer', 'min:0', 'max:'.PosSettings::MAX_AMOUNT],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'closing_cash.required' => 'Hitung uang di laci dulu sebelum menutup shift.',
            'closing_cash.integer' => 'Uang penutup harus angka bulat rupiah.',
            'closing_cash.min' => 'Uang penutup tidak bisa negatif.',
            'closing_cash.max' => 'Uang penutup terlalu besar untuk dicatat.',
            'notes.max' => 'Catatan maksimal 500 karakter.',
        ];
    }

    /**
     * @return array<string, 'integer'>
     */
    protected function normalizableNumbers(): array
    {
        return ['closing_cash' => 'integer'];
    }

    /**
     * Shift yang sedang ditutup, dari route model binding.
     *
     * Diambil dari route, bukan dicari ulang, supaya yang divalidasi adalah baris
     * yang sama persis dengan yang ditutup controller.
     */
    public function shift(): ?Shift
    {
        $shift = $this->route('shift');

        return $shift instanceof Shift ? $shift : null;
    }

    public function closingCash(): int
    {
        return (int) $this->validated('closing_cash');
    }

    public function notes(): ?string
    {
        $notes = $this->validated('notes');

        return is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
    }
}
