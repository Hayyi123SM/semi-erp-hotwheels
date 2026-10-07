<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbound;

use App\Enums\LabelStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Basis untuk semua aksi atas job label yang dipilih operator.
 *
 * Semua aksi ini bekerja pada sekumpulan id dari checkbox, jadi aturan
 * validasinya sama. Yang membedakan hanya status yang harus dimiliki job
 * supaya aksi itu masuk akal, dan fields tambahan tertentu.
 */
abstract class LabelJobsRequest extends FormRequest
{
    /**
     * Otorisasi ditangani middleware route, jadi di sini tidak perlu.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Status yang harus dimiliki job agar aksi ini tidak berlebihan.
     *
     * Job yang tidak sesuai di-error oleh validasi, bukan dilewati diam-diam.
     * Bedanya dengan service: di sini operator_batch yang salah pilih checkbox
     * perlu diberi tahu, sedangkan di service perubahan status yang bentrok
     * dianggap request yang sudah terpenuhi.
     */
    abstract protected function expectedStatus(): LabelStatus;

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('label_print_jobs', 'id')
                    ->where('status', $this->expectedStatus()->value),
            ],
        ];
    }

    /**
     * Id job yang sudah tervalidasi, apa adanya urutannya.
     *
     * @return array<int, int>
     */
    public function jobIds(): array
    {
        return $this->validated('ids');
    }
}
