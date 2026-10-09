<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\AdjustmentReason;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Putuskan satu baris selisih: setujui (dan ubah stok) atau tolak (FR-IC-23).
 *
 * Keputusan selisih adalah pekerjaan Owner. Seluruh modul Inventory berada di
 * dalam grup `['auth', 'owner']` (`routes/web.php`), jadi request yang sampai
 * ke class ini selalu dibawa Owner; Staff ditolak 403 sebelum aturan validasi
 * sempat dibaca. Tidak ada
 * pintu PIN di sini: PIN Owner tetap dipakai aksi-aksi lain yang mengatasnamakan
 * Owner (mis. menutup shift), tetapi memutuskan selisih bukan salah satunya --
 * memintanya lagi di layar yang hanya muncul untuk Owner hanya menambah satu
 * langkah tanpa menambah satu lapis pengaman pun.
 *
 * Alasan hanya diwajibkan untuk persetujuan, dan itu bukan kelalaian: alasan
 * menjadi bagian dari gerakan stok (`stock_movements.reason`) untuk
 * `ADJ_PLUS`/`ADJ_MINUS`, sementara penolakan tidak menghasilkan gerakan apa
 * pun sehingga tidak ada tempat alasan itu akan dibaca.
 */
class ReviewBarisRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['APPROVE', 'REJECT'])],
            'reason' => [
                Rule::requiredIf(fn () => $this->input('decision') === 'APPROVE'),
                'nullable',
                Rule::enum(AdjustmentReason::class),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'decision' => 'keputusan',
            'reason' => 'alasan selisih',
        ];
    }

    public function messages(): array
    {
        return [
            'decision.required' => 'Pilih setujui atau tolak baris ini.',
            'decision.in' => 'Keputusan tidak dikenal.',
            'reason.required' => 'Pilih alasan selisih sebelum menyetujui.',
            'reason.enum' => 'Alasan selisih tidak dikenal.',
        ];
    }

    public function decision(): string
    {
        return (string) $this->validated('decision');
    }

    public function reason(): ?AdjustmentReason
    {
        $reason = $this->input('reason');

        return is_string($reason) && $reason !== ''
            ? AdjustmentReason::from($reason)
            : null;
    }

    public function approver(): User
    {
        return $this->user();
    }
}
