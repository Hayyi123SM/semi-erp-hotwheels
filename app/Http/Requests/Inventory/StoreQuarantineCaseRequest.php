<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\CardCondition;
use App\Enums\QuarantineReason;
use App\Models\Rack;
use App\Support\Enums;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registrasi kasus karantina (Fase 2, BR-10).
 *
 * Hak akses tidak diperiksa di sini: seluruh modul Inventory sudah berada di
 * dalam grup `['auth', 'owner']`, jadi hanya Owner yang bisa sampai ke form.
 */
class StoreQuarantineCaseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'qty' => ['required', 'integer', 'min:1'],
            'rack_id' => [
                'required',
                'integer',
                Rule::exists('racks', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'keyword' => ['required', 'string', 'max:200'],
            'series' => ['nullable', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:80'],
            'condition' => ['nullable', Rule::in(Enums::values(CardCondition::class))],
            'reason' => ['nullable', Rule::in(Enums::values(QuarantineReason::class))],
        ];
    }

    public function messages(): array
    {
        return [
            'qty.required' => 'Tulis jumlah unit yang masuk karantina.',
            'qty.min' => 'Jumlah unit minimal 1.',
            'rack_id.required' => 'Pilih rak karantina dulu.',
            'rack_id.exists' => 'Rak karantina tidak ditemukan atau sedang nonaktif.',
            'keyword.required' => 'Tulis nama/kata kunci produk dari pembeli atau petugas.',
            'condition.in' => 'Kondisi kartu tidak dikenal.',
            'reason.in' => 'Alasan karantina tidak dikenal.',
        ];
    }

    public function qty(): int
    {
        return (int) $this->input('qty');
    }

    /**
     * @throws ModelNotFoundException
     */
    public function rack(): Rack
    {
        return Rack::query()
            ->where('is_active', true)
            ->findOrFail((int) $this->input('rack_id'));
    }

    public function keyword(): string
    {
        return trim((string) $this->input('keyword'));
    }

    public function series(): ?string
    {
        $series = trim((string) $this->input('series', ''));

        return $series === '' ? null : $series;
    }

    public function color(): ?string
    {
        $color = trim((string) $this->input('color', ''));

        return $color === '' ? null : $color;
    }

    public function condition(): ?CardCondition
    {
        return CardCondition::tryFrom((string) $this->input('condition', ''));
    }

    public function reason(): ?QuarantineReason
    {
        return QuarantineReason::tryFrom((string) $this->input('reason', ''));
    }
}
