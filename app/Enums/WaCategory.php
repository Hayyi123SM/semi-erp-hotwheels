<?php

namespace App\Enums;

enum WaCategory: string
{
    case ConsignmentReceipt = 'CONSIGNMENT_RECEIPT';
    case Receipt = 'RECEIPT';
    case Statement = 'STATEMENT';
    case Rtv = 'RTV';
}
