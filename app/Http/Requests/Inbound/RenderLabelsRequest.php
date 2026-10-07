<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbound;

use Illuminate\Foundation\Http\FormRequest;

class RenderLabelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Hanya menampilkan label yang sudah ada di antrean. Job label dibuat
        // oleh proses inbound, bukan oleh pengguna, jadi halaman ini tidak
        // pernah membuat data baru. Seluruh aplikasi memang hanya dilindungi
        // middleware `auth`, jadi ini konsisten dengan request lain.
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['required', 'integer', 'distinct', 'exists:label_print_jobs,id'],
        ];
    }
}
