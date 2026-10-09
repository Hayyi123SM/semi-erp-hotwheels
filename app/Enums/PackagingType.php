<?php

namespace App\Enums;

enum PackagingType: string
{
    case Carded = 'CARDED';
    case Boxed = 'BOXED';
    case Loose = 'LOOSE';

    public function label(): string
    {
        return match ($this) {
            self::Carded => 'Carded',
            self::Boxed => 'Boxed',
            self::Loose => 'Loose',
        };
    }
}
