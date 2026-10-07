<?php

namespace App\Enums;

enum PackagingType: string
{
    case Carded = 'CARDED';
    case Boxed = 'BOXED';
    case Loose = 'LOOSE';
}
