<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\CardCondition;
use App\Support\Enums;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pencarian kandidat SKU untuk satu kasus karantina (BR-11).
 *
 * Dipakai lewat GET ke halaman Karantina (`?case=&q=&seri=&warna=&kondisi=`),
 * jadi semua kolom bersifat opsional: pencarian kosong tetap sah dan cukup
 * kembali dengan daftar stok yang cocok dengan atribut kasus.
 */
class LookupQuarantineRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'seri' => ['nullable', 'string', 'max:120'],
            'warna' => ['nullable', 'string', 'max:80'],
            'kondisi' => ['nullable', Rule::in(Enums::values(CardCondition::class))],
        ];
    }

    public function keyword(): ?string
    {
        $q = trim((string) $this->input('q', ''));

        return $q === '' ? null : $q;
    }

    public function series(): ?string
    {
        $series = trim((string) $this->input('seri', ''));

        return $series === '' ? null : $series;
    }

    public function color(): ?string
    {
        $color = trim((string) $this->input('warna', ''));

        return $color === '' ? null : $color;
    }

    public function condition(): ?CardCondition
    {
        return CardCondition::tryFrom((string) $this->input('kondisi', ''));
    }
}
