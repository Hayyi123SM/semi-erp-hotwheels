<?php

namespace App\Support;

use Illuminate\Support\Str;

class Enums
{
    /**
     * Kelas enum menjadi daftar value (untuk dropdown & validasi In).
     *
     * @param  class-string<\BackedEnum>  $enum
     * @return string[]
     */
    public static function values(string $enum): array
    {
        return array_column($enum::cases(), 'value');
    }

    /**
     * Kelas enum menjadi daftar [value => label human friendly].
     *
     * @param  class-string<\BackedEnum>  $enum
     * @return array<string, string>
     */
    public static function options(string $enum): array
    {
        $options = [];
        foreach ($enum::cases() as $case) {
            $options[$case->value] = Str::headline($case->name);
        }

        return $options;
    }

    /**
     * Cocokkan teks bebas (value / nama case / label) ke nilai enum.
     * Kembalikan null bila tidak mengenali.
     *
     * @param  class-string<\BackedEnum>  $enum
     */
    public static function fromAny(string $enum, string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach ($enum::cases() as $case) {
            if (strcasecmp($value, (string) $case->value) === 0
                || strcasecmp($value, $case->name) === 0
                || strcasecmp($value, Str::headline($case->name)) === 0) {
                return (string) $case->value;
            }
        }

        return null;
    }
}
