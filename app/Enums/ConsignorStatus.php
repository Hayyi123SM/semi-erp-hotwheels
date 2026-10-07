<?php

namespace App\Enums;

enum ConsignorStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Archived = 'ARCHIVED';
}
