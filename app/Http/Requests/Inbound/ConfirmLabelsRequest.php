<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbound;

use App\Enums\LabelStatus;

/**
 * Operator mengonfirmasi label keluar dari printer: SENT -> CONFIRMED.
 *
 * Hanya di aksi ini `labels_printed` bertambah, jadi form konfirmasi adalah
 * tempat operator menyatakan "labelnya benar-benar menempel pada unit".
 */
class ConfirmLabelsRequest extends LabelJobsRequest
{
    protected function expectedStatus(): LabelStatus
    {
        return LabelStatus::Sent;
    }
}
