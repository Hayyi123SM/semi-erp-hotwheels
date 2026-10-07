<?php

namespace App\Enums;

enum LotStatus: string
{
    case Available = 'AVAILABLE';
    case SoldOut = 'SOLD_OUT';
    case Returned = 'RETURNED';
    case WrittenOff = 'WRITTEN_OFF';
    case Void = 'VOID';
}
