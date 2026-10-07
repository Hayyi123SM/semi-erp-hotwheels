<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos;

use App\Http\Requests\Concerns\NormalizesNumbers;
use App\Services\Pos\PosSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Buka shift kasir: berapa uang yang ada di laci sebelum penjualan pertama.
 *
 * `device_id` sengaja tidak ada di sini. Perangkat dibaca oleh `DeviceId::current()`
 * dari session, header, atau cookie otomatisnya, bukan dari form, karena form yang
 * boleh menamai perangkat sendiri membuat aturan "satu perangkat satu shift
 * terbuka" bisa dilewati dengan mengetik id yang berbeda. Formnya hanya
 * menampilkan perangkat yang sedang dipakai sebagai teks yang tidak bisa diedit.
 */
class OpenShiftRequest extends FormRequest
{
    use NormalizesNumbers;

    public function rules(): array
    {
        return [
            'opening_cash' => ['required', 'integer', 'min:0', 'max:'.PosSettings::MAX_AMOUNT],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'opening_cash.required' => 'Hitung uang di laci dulu sebelum membuka shift.',
            'opening_cash.integer' => 'Uang pembuka harus angka bulat rupiah.',
            'opening_cash.min' => 'Uang pembuka tidak bisa negatif. Kalau uang kurang, catat di catatan.',
            'opening_cash.max' => 'Uang pembuka terlalu besar untuk dicatat.',
            'notes.max' => 'Catatan maksimal 500 karakter.',
        ];
    }

    /**
     * @return array<string, 'integer'>
     */
    protected function normalizableNumbers(): array
    {
        return ['opening_cash' => 'integer'];
    }

    public function openingCash(): int
    {
        return (int) $this->validated('opening_cash');
    }

    public function notes(): ?string
    {
        $notes = $this->validated('notes');

        return is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
    }
}
