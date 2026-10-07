<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Enums\AdjustmentReason;
use App\Http\Requests\Concerns\RequiresOwnerPin;
use App\Models\User;
use App\Services\Auth\PinService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Putuskan satu baris selisih: setujui (dan ubah stok) atau tolak (FR-IC-23).
 *
 * PIN Owner berlaku untuk kedua keputusan, bukan hanya persetujuan. Penolakan
 * memang tidak menyentuh stok, tetapi ia menutup baris: setelah ditolak,
 * hitungan fisik itu tidak bisa dipakai lagi dan sesi dianggap selesai. Dua
 * keputusan yang sama-sama menentukan apakah selisih itu hidup atau mati tidak
 * boleh punya pintu yang berbeda lebarnya.
 *
 * Alasan hanya diwajibkan untuk persetujuan, dan itu bukan kelalaian: alasan
 * menjadi bagian dari gerakan stok (`stock_movements.reason`) untuk
 * `ADJ_PLUS`/`ADJ_MINUS`, sementara penolakan tidak menghasilkan gerakan apa
 * pun sehingga tidak ada tempat alasan itu akan dibaca.
 */
class ReviewBarisRequest extends FormRequest
{
    use RequiresOwnerPin;

    protected function ownerPinContext(): string
    {
        return 'inventory.opname-approve';
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['APPROVE', 'REJECT'])],
            'reason' => [
                Rule::requiredIf(fn () => $this->input('decision') === 'APPROVE'),
                'nullable',
                Rule::enum(AdjustmentReason::class),
            ],
            ...$this->ownerPinRules(),
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
            'pin_token.required' => 'Verifikasi PIN Owner diperlukan untuk memutuskan baris selisih.',
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

    /**
     * Siapa yang menyetujui: Owner lewat token PIN, atau Owner sendiri yang
     * mengklik. Staff tanpa token tidak akan sampai ke sini -- aturan PIN di
     * atas menahannya -- tetapi `null` tetap dikembalikan sebagai bentuk paling
     * jujur dari "tidak ada yang menotorisasi", bukan jatuh ke pemilik baris.
     */
    public function approver(): ?User
    {
        $user = $this->user();

        if ($user?->isOwner()) {
            return $user;
        }

        $approver = app(PinService::class)->approverFor(
            $user,
            $this->input($this->ownerPinField()),
            $this->ownerPinContext(),
        );

        return $approver instanceof User ? $approver : null;
    }
}
