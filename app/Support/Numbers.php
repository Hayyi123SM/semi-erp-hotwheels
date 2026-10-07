<?php

namespace App\Support;

/**
 * Reads a number the way a reader wrote it, out of a string a computer cannot.
 *
 * A field that groups as it is typed holds `1.000.000` on screen, and that
 * string is the one thing this project cannot safely accept. PHP reads `.` as
 * the decimal point, so `1.000` is `1.0` -- and on a field validated with
 * `numeric` and cast to `decimal:2`, that is a hundredfold under-count which
 * passes every rule and reports success. Nothing downstream will catch it,
 * because the value it is given is a perfectly good number. It is just not the
 * number that was typed.
 *
 * Both halves of the project had a piece of this and neither was shared. The
 * importer had it in `ImportManager::normalizeMoney()` and it was never
 * connected to the request path; the request path had nothing at all. Both
 * answers are the same, so there is one answer here.
 *
 * Neither helper guesses. A dot that could be a thousands separator is treated
 * as one, which turns the ambiguous case into a number the rules can refuse out
 * loud rather than a number they will happily store.
 */
final class Numbers
{
    /**
     * An amount: digits, and nothing that is not a digit.
     *
     * The grouped figure and the plain one describe the same number, so
     * whichever arrives is reduced to the part both agree on. `Rp1.500.000` out
     * of a spreadsheet and `1500000` out of a keyboard end up as the same
     * string, which is the whole point of running it at all.
     *
     * A leading minus is kept, not stripped -- see `digits()` for why that one
     * character is the difference between a refusal and a wrong number.
     *
     * @return string|null null for nothing, so a nullable field stays nullable
     */
    public static function integer(int|float|string|null $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return self::digits((string) $value);
    }

    /**
     * The digits, always, and never nothing.
     *
     * A leading zero says nothing about the amount, and a reader who typed `007`
     * was counting digits rather than counting thousands. A figure of nothing is
     * still a figure, so it survives as `0` -- which is also what keeps a
     * half-typed `,5` from arriving as a decimal point with no whole part.
     *
     * A minus sign survives too, and this is the one place where keeping the sign
     * matters more than keeping the digits. Stripping it turns `-1` into `1`:
     * a request that said "this must not be negative" then sails through `min:0`
     * and stores the opposite of what was typed, with nothing anywhere reporting
     * that the number changed. Dropping the rest of the letters out of `Rp1.500`
     * is safe precisely because none of them contradict each other; `-` is the
     * single character that reverses the meaning of every digit after it, so it
     * is the one that has to reach the rule that knows how to refuse it.
     */
    private static function digits(string $value): string
    {
        $negative = str_starts_with(ltrim($value), '-');

        $digits = ltrim(preg_replace('/[^0-9]/', '', $value), '0') ?: '0';

        // `-0` is not a number anyone typed, and casting it to an int quietly
        // makes it positive anyway -- but the string form is what reaches the
        // rules, so it is the string form that has to read as the plain `0`.
        return $negative && $digits !== '0' ? '-'.$digits : $digits;
    }

    /**
     * A percentage: a decimal, and never a grouped figure.
     *
     * A comma is always the decimal point. A dot is only read as one where it
     * cannot be a thousands separator -- a single dot with at most two digits
     * behind it -- because a percentage is bounded at 100, and `1.000` cannot
     * mean one point zero zero of a hundred. It is a thousand, and reading it
     * that way is what lets `between:0,100` turn it away instead of `1.00`
     * being stored and the row looking saved.
     *
     * `12,5` and `12.5` therefore both arrive as `12.5`, and `1.000.000` arrives
     * as `1000000`.
     */
    public static function rate(int|float|string|null $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $cleaned = preg_replace('/[^0-9.,]/', '', (string) $value);

        if (str_contains($cleaned, ',')) {
            // A comma settles it, and anything else that looks like a separator
            // around it is noise: a pasted `Rp1.500,50` is one figure, not two.
            [$whole, $fraction] = explode(',', $cleaned, 2);

            return self::withFraction(self::digits($whole), $fraction);
        }

        $parts = explode('.', $cleaned);

        if (count($parts) === 2 && strlen($parts[1]) <= 2) {
            return self::withFraction(self::digits($parts[0]), $parts[1]);
        }

        return self::digits($cleaned);
    }

    /**
     * The two halves rejoined.
     *
     * A fraction of nothing is not a decimal: `12,` is a number still being
     * typed, and `12.` is not a value worth handing to a `numeric` rule.
     */
    private static function withFraction(string $whole, string $fraction): string
    {
        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }
}
