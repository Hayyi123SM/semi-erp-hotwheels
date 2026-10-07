<?php

namespace App\Enums;

enum SettlementStatus: string
{
    case Draft = 'DRAFT';
    case Approved = 'APPROVED';
    case Sent = 'SENT';
    case PartiallyPaid = 'PARTIALLY_PAID';
    case Paid = 'PAID';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';
}
