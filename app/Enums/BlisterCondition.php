<?php

namespace App\Enums;

enum BlisterCondition: string
{
    case Clear = 'CLEAR';
    case Scuffed = 'SCUFFED';
    case Dented = 'DENTED';
    case Cracked = 'CRACKED';
    case Yellowed = 'YELLOWED';
    case NA = 'N/A';

    /**
     * @see CardCondition::shortLabel() untuk alasan singkat ini dipakai.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Clear => 'CL',
            self::Scuffed => 'SC',
            self::Dented => 'DN',
            self::Cracked => 'CK',
            self::Yellowed => 'YL',
            self::NA => '-',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Clear => 'Clear',
            self::Scuffed => 'Scuffed',
            self::Dented => 'Dented',
            self::Cracked => 'Cracked',
            self::Yellowed => 'Yellowed',
            self::NA => 'Tidak Berlaku',
        };
    }
}
