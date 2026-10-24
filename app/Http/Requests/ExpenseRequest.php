<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expense_date' => ['required', 'date'],
            'expense_category_id' => ['nullable', 'integer', Rule::exists('expense_categories', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'description' => ['required', 'string', 'max:1000'],
            'vendor_name' => ['nullable', 'string', 'max:150'],
            'payment_method' => ['nullable', 'in:TUNAI,TRANSFER,QRIS,EDC'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'expense_date' => 'tanggal pengeluaran',
            'expense_category_id' => 'kategori pengeluaran',
            'amount' => 'jumlah',
            'description' => 'keterangan',
            'vendor_name' => 'penerima/vendor',
            'payment_method' => 'metode pembayaran',
            'reference_no' => 'no. referensi',
            'notes' => 'catatan',
        ];
    }
}
