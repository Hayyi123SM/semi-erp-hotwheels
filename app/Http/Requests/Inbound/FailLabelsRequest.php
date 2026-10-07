<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbound;

use App\Enums\LabelStatus;

/**
 * Tandai cetakan gagal: SENT -> FAILED.
 *
 * Alasan wajib, bukan opsional. Kalau percetakan dicoba ulang tanpa catatan
 * apa yang salah, kesalahan yang sama akan terulang -- kertas habis, head
 * overheating, dan label yang salah ukuran gejalanya berbeda.
 */
class FailLabelsRequest extends LabelJobsRequest
{
    protected function expectedStatus(): LabelStatus
    {
        return LabelStatus::Sent;
    }

    public function rules(): array
    {
        return parent::rules() + [
            'message' => ['required', 'string', 'max:500'],
        ];
    }

    public function failureMessage(): string
    {
        return trim((string) $this->validated('message'));
    }
}
