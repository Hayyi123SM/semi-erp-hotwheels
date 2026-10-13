<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Catatan saat kasus dieskalasi atau ditulis-hapus.
 *
 * Catatan ini disimpan ke `quarantine_cases.evidence` dalam bentuk entri
 * ber-waktu, supaya keputusan atas kasus yang membuntu bisa dipertang-
 * gungjawabkan siapa memutuskan kapan dan dengan alasan apa.
 */
class ResolveQuarantineRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'note.max' => 'Catatan maksimal 200 karakter.',
        ];
    }

    public function note(): ?string
    {
        $note = trim((string) $this->input('note', ''));

        return $note === '' ? null : $note;
    }
}
