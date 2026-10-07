<?php

declare(strict_types=1);

namespace App\Enums;

enum SchemeType: string
{
    case Percentage = 'PERCENTAGE';
    case Nett = 'NETT';
    case Flat = 'FLAT';

    /**
     * Field yang menyimpan parameter skema ini.
     *
     * Satu skema punya satu parameter: persen menyimpan `rate`, nett dan flat
     * menyimpan `amount`. Menanyakan ini ke enum, bukan menulisnya berulang di
     * request, form, dan test, adalah yang membuat "baris PERCENTAGE dengan
     * param" tidak pernah ambigu.
     */
    public function parameterField(): string
    {
        return match ($this) {
            self::Percentage => 'scheme_rate',
            self::Nett, self::Flat => 'scheme_amount',
        };
    }

    /**
     * Field yang harus ada di setiap baris inbound untuk skema ini.
     *
     * @return list<string>
     */
    public function requiredFields(): array
    {
        return match ($this) {
            self::Percentage => ['scheme_type', 'scheme_rate'],
            self::Nett, self::Flat => ['scheme_type', 'scheme_amount'],
        };
    }
}
