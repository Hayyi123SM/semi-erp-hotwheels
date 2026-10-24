<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos;

use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Owner mengesahkan tutup shift yang menunggu keputusan.
 *
 * Tidak ada isian: angka laci dan selisihnya sudah dikirim kasir dan sudah
 * tersimpan. Yang diminta dari Owner hanyalah keputusan, dan itu diwakili oleh
 * route yang dipanggil -- bukan oleh field yang bisa dimanipulasi.
 */
class ApproveShiftClosingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isOwner() === true && $this->shift() !== null;
    }

    public function shift(): ?Shift
    {
        $shift = $this->route('shift');

        return $shift instanceof Shift ? $shift : null;
    }

    public function owner(): User
    {
        /** @var User $owner */
        $owner = $this->user();

        return $owner;
    }
}
