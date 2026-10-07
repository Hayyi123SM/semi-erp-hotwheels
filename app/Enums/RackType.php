<?php

namespace App\Enums;

enum RackType: string
{
    case Display = 'DISPLAY';
    case Storage = 'STORAGE';
    case Quarantine = 'QUARANTINE';
    case RtvStaging = 'RTV_STAGING';
}
