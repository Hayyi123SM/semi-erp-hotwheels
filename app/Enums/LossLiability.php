<?php

namespace App\Enums;

enum LossLiability: string
{
    case Store = 'STORE';
    case Consignor = 'CONSIGNOR';
    case Shared = 'SHARED';
}
