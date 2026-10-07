<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'OWNER';
    case Staff = 'STAFF';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Staff => 'Staff',
        };
    }
}
