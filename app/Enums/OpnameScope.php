<?php

namespace App\Enums;

enum OpnameScope: string
{
    case All = 'ALL';
    case Rack = 'RACK';
    case Sku = 'SKU';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Seluruh gudang',
            self::Rack => 'Per rak',
            self::Sku => 'Per SKU',
        };
    }
}
