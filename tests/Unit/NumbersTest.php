<?php

namespace Tests\Unit;

use App\Support\Numbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a number means when it arrives wearing the separators a reader gave it.
 *
 * Everything in this project writes an amount the Indonesian way -- `1.000.000`
 * -- and everything in PHP reads `.` as a decimal point. The two disagree about
 * the same three characters, and the disagreement is silent: the value that
 * reaches the database is a perfectly good number, just not the one that was
 * typed, and no rule in the application will ever object to it.
 */
class NumbersTest extends TestCase
{
    #[Test]
    #[DataProvider('amounts')]
    public function it_reads_an_amount_as_digits(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, Numbers::integer($input));
    }

    public static function amounts(): array
    {
        return [
            'plain' => ['65000', '65000'],
            'grouped' => ['65.000', '65000'],
            'grouped, millions' => ['1.000.000', '1000000'],
            'out of a spreadsheet' => ['Rp1.500.000', '1500000'],
            'written for another country' => ['1,500,000', '1500000'],
            'a number already' => [1500000, '1500000'],
            // A leading zero says nothing about the amount, and someone who
            // typed `007` was counting digits rather than counting thousands.
            'leading zeros' => ['007', '7'],
            'all zeros is still a figure' => ['000', '0'],
            'zero' => ['0', '0'],
            // No field behind this mask is a signed column -- which is exactly
            // why the sign is kept rather than dropped. Turning `-5000` into
            // `5000` does not protect the unsigned column, it invents five
            // thousand units that were never received; carrying the minus lets
            // `min:0` say so out loud.
            'negative' => ['-5000', '-5000'],
            'negative, grouped' => ['-5.000', '-5000'],
            'negative zero' => ['-0', '0'],
            'letters' => ['abc', '0'],
            'nothing' => ['', null],
            'null' => [null, null],
        ];
    }

    #[Test]
    public function it_returns_null_for_nothing_rather_than_a_zero(): void
    {
        // A blank that became `0` on the way through would take `nullable` with
        // it, and the reader would have no way left to empty the field.
        $this->assertNull(Numbers::integer(''));
        $this->assertNull(Numbers::integer(null));
        $this->assertNull(Numbers::integer('   '));
    }

    #[Test]
    #[DataProvider('rates')]
    public function it_reads_a_percentage_as_a_decimal(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, Numbers::rate($input));
    }

    public static function rates(): array
    {
        return [
            'whole' => ['20', '20'],
            'indonesian decimal' => ['12,5', '12.5'],
            'two places' => ['12,50', '12.50'],
            'international decimal' => ['12.5', '12.5'],
            'a padded column' => ['20.00', '20.00'],
            'zero' => ['0', '0'],
            // The whole reason a percentage is read separately. A bounded-at-100
            // figure cannot have a thousands separator, so `1.000` is a
            // thousand -- and reading it any other way is the bug that used to
            // store `1.00` for `1.000` with a green tick beside it.
            'a thousand, not a decimal' => ['1.000', '1000'],
            'millions' => ['1.000.000', '1000000'],
            'a pasted figure' => ['Rp12,50', '12.50'],
            // Half-typed, and not a value worth handing to a `numeric` rule.
            'trailing comma' => ['12,', '12'],
            'trailing dot' => ['12.', '12'],
        ];
    }

    #[Test]
    public function it_reads_a_third_decimal_place_as_a_thousands_separator(): void
    {
        // `12.567` cannot be a share of somebody's money and is well past 100,
        // so the dot is grouping and the figure is twelve thousand five hundred
        // and sixty-seven. That is the deliberate cost of the ambiguity: the
        // rules see a number they can refuse, rather than one they will store.
        $this->assertSame('12567', Numbers::rate('12.567'));
    }

    #[Test]
    public function it_never_returns_a_broken_decimal_for_a_figure_with_no_whole_part(): void
    {
        $this->assertSame('0.5', Numbers::rate(',5'));
    }
}
