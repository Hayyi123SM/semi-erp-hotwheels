<?php

namespace Tests\Feature\Ui;

use App\Support\Format;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StatCardValueTest extends TestCase
{
    private function render(mixed $value): string
    {
        return trim((string) Blade::render(
            '<x-ui.stat-card label="Label" :value="$value" />',
            ['value' => $value],
        ));
    }

    /** The value as the reader receives it, with the markup around it removed. */
    private function shown(mixed $value): string
    {
        preg_match('/tabular-nums">([^<]*)</', $this->render($value), $match);

        return trim(html_entity_decode($match[1] ?? ''));
    }

    #[Test]
    #[DataProvider('numbers')]
    public function it_gives_a_bare_number_its_thousand_separators(int $value, string $expected): void
    {
        // The counts that came out as `1234` are the reason this is in the
        // component: thirteen call sites were each responsible for remembering,
        // and the ones that mattered were the numbers.
        $this->assertSame($expected, $this->shown($value));
    }

    public static function numbers(): array
    {
        return [
            'a count under a thousand needs no separator' => [7, '7'],
            'zero is a fact worth stating' => [0, '0'],
            'exactly a thousand' => [1000, '1.000'],
            'four digits' => [1234, '1.234'],
            'seven digits' => [1234567, '1.234.567'],
        ];
    }

    #[Test]
    public function it_reads_a_number_that_blade_passed_as_a_string(): void
    {
        // `value="{{ count($x) }}"` arrives as a string, so this is the ordinary
        // case for the pages that were not converted to a bound prop.
        $this->assertSame('1.234', $this->shown('1234'));
    }

    #[Test]
    public function it_leaves_an_amount_already_composed_exactly_as_written(): void
    {
        // Not a number in the arithmetic sense, only in the loose sense of
        // "reads like a figure". Reformatting these would corrupt them.
        $this->assertSame('Rp4.215.000', $this->shown('Rp4.215.000'));
        $this->assertSame('Rp74,6jt', $this->shown('Rp74,6jt'));
        $this->assertSame('Rp12,48jt', $this->shown('Rp12,48jt'));
    }

    #[Test]
    public function it_leaves_text_that_only_looks_like_a_figure(): void
    {
        $this->assertSame('OK', $this->shown('OK'));
        $this->assertSame('PERCENTAGE', $this->shown('PERCENTAGE'));
        $this->assertSame('Dewi Lestari', $this->shown('Dewi Lestari'));
        // A "Q-00-01" is not a number, and must not be rounded into one.
        $this->assertSame('Q-00-01', $this->shown('Q-00-01'));
    }

    #[Test]
    public function it_agrees_with_the_php_helper_it_delegates_to(): void
    {
        $this->assertSame(Format::number(1234567), $this->shown(1234567));
    }

    #[Test]
    public function it_does_not_reformat_a_value_that_is_already_grouped(): void
    {
        // `is_numeric("1.234")` is true, because PHP reads the dot as a decimal
        // point, and formatting that would quietly round 1.234 down to 1. A
        // caller who formats before passing the value in gets it back as they
        // sent it.
        $this->assertSame('1.234', $this->shown('1.234'));
        $this->assertSame('10.000.000', $this->shown('10.000.000'));
        $this->assertSame(Format::number(1234567), $this->shown(Format::number(1234567)));
    }

    #[Test]
    public function it_leaves_nothing_to_show_as_nothing(): void
    {
        // Unchanged from before, and deliberately: whether a card with nothing in
        // it should show an em dash is a question about missing values, not about
        // number formatting, and no caller passes an empty value today.
        $this->assertSame('', $this->shown(''));
    }
}
