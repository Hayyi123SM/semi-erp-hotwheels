<?php

namespace App\Enums;

enum SettlementCycle: string
{
    case Weekly = 'WEEKLY';
    case Biweekly = 'BIWEEKLY';
    case Monthly = 'MONTHLY';
}
