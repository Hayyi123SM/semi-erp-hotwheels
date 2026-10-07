<?php

namespace App\Enums;

enum ConsignmentStatus: string
{
    case Draft = 'DRAFT';
    case Committed = 'COMMITTED';
    case Completed = 'COMPLETED';
    case Void = 'VOID';
}
