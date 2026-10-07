<?php

namespace App\Enums;

enum CardCondition: string
{
    case Mint = 'MINT';
    case NearMint = 'NEAR_MINT';
    case SoftCorner = 'SOFT_CORNER';
    case Creased = 'CREASED';
    case Bent = 'BENT';
    case Damaged = 'DAMAGED';

    /**
     * Bentuk yang dicetak di label. Label thermal hanya muat satu atau dua
     * huruf per kondisi, jadi singkatan dipakai di sini -- bukan di template,
     * supaya `payload` menyimpan apa yang benar-benar keluar dari printer.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Mint => 'M',
            self::NearMint => 'NM',
            self::SoftCorner => 'SC',
            self::Creased => 'CR',
            self::Bent => 'BN',
            self::Damaged => 'DM',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Mint => 'Mint',
            self::NearMint => 'Near Mint',
            self::SoftCorner => 'Soft Corner',
            self::Creased => 'Creased',
            self::Bent => 'Bent',
            self::Damaged => 'Damaged',
        };
    }
}
