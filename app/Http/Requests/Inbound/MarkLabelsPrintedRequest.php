<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbound;

use App\Enums\LabelStatus;

/**
 * Job dikirim ke printer: QUEUED -> SENT.
 *
 * Belum ada yang terukur di aksi ini. Operator baru menyatakan "sudah
 * dikirim ke printer"; `labels_printed` tetap menunggu konfirmasi.
 */
class MarkLabelsPrintedRequest extends LabelJobsRequest
{
    protected function expectedStatus(): LabelStatus
    {
        return LabelStatus::Queued;
    }
}
