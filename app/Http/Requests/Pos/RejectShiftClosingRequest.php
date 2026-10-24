<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Owner menolak tutup shift yang menunggu keputusan.
 *
 * Alasannya wajib. Penolakan mengembalikan shift ke kasir untuk dihitung ulang,
 * dan kasir yang tidak tahu apa yang salah hanya akan mengirim angka yang sama
 * lagi -- lalu shift itu bolak-balik tanpa pernah selesai.
 */
class RejectShiftClosingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() === true && $this->shift() !== null;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Tulis alasan penolakan supaya kasir tahu apa yang harus dihitung ulang.',
            'reason.max' => 'Alasan penolakan maksimal 500 karakter.',
        ];
    }

    public function shift(): ?Shift
    {
        $shift = $this->route('shift');

        return $shift instanceof Shift ? $shift : null;
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }

    public function owner(): User
    {
        /** @var User $owner */
        $owner = $this->user();

        return $owner;
    }
}
