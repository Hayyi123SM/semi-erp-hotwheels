<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbound;

use App\Enums\LabelStatus;

/**
 * Kembalikan job yang gagal ke antrean: FAILED -> QUEUED.
 */
class RetryLabelsRequest extends LabelJobsRequest
{
    protected function expectedStatus(): LabelStatus
    {
        return LabelStatus::Failed;
    }
}
