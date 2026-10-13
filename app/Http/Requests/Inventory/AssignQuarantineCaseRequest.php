<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Models\StockLot;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Assign satu kandidat lot ke kasus karantina (BR-11).
 *
 * `lot_id` harus mengarah ke lot yang masih tersedia: menautkan kasus ke lot
 * yang sudah habis/sold out berarti meng-identifikasi unit ke barang yang
 * tidak lagi dijual, dan memutus kebenaran kolom `assigned_lot_id`.
 */
class AssignQuarantineCaseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'lot_id' => [
                'required',
                'integer',
                Rule::exists('stock_lots', 'id')->where(fn ($query) => $query->where('status', 'AVAILABLE')),
            ],
            'counted_c' => ['required', 'integer', 'min:0'],
            'confirm' => ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'lot_id.required' => 'Pilih kandidat SKU dulu.',
            'lot_id.exists' => 'Kandidat SKU tidak ditemukan atau tidak lagi tersedia.',
            'counted_c.required' => 'Tulis jumlah fisik berlabel (C).',
            'counted_c.min' => 'Jumlah fisik berlabel tidak boleh negatif.',
        ];
    }

    /**
     * @throws ModelNotFoundException
     */
    public function lot(): StockLot
    {
        return StockLot::query()->findOrFail((int) $this->input('lot_id'));
    }

    public function countedC(): int
    {
        return (int) $this->input('counted_c');
    }

    public function confirm(): bool
    {
        return $this->boolean('confirm');
    }
}
