<?php

namespace Tests\Unit;

use App\Enums\ConsignorStatus;
use App\Enums\LotStatus;
use App\Enums\OwnerType;
use App\Support\Format;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    #[Test]
    public function it_formats_rupiah(): void
    {
        $this->assertSame('Rp45.000', Format::rupiah(45000));
        $this->assertSame('Rp1.234.567', Format::rupiah(1234567));
        $this->assertSame('Rp0', Format::rupiah(null));
        $this->assertSame('Rp0', Format::rupiah(0));
    }

    #[Test]
    public function it_formats_numbers_and_keeps_zero(): void
    {
        $this->assertSame('1.234', Format::number(1234));
        $this->assertSame('0', Format::number(0));
        $this->assertSame('12,50', Format::number(12.5, 2));
        $this->assertSame(Format::EMPTY, Format::number(null));
    }

    /**
     * The same table `resources/js/format.test.js` pins, in the same order.
     *
     * The two helpers format the same counts in two languages, on either side of
     * a page load, and nothing else in the project compares them. Without this
     * one could move and a table would say `1234` while the total beside it
     * said `1.234`, with both test suites green.
     */
    #[Test]
    public function it_agrees_with_the_browser_helper_on_every_value_both_are_given(): void
    {
        foreach ([
            [0, '0'],
            [1, '1'],
            [10, '10'],
            [999, '999'],
            [1000, '1.000'],
            [1500, '1.500'],
            [10000, '10.000'],
            [123456, '123.456'],
            [1000000, '1.000.000'],
            [10000000, '10.000.000'],
            [1234567, '1.234.567'],
            [-1234, '-1.234'],
        ] as [$input, $expected]) {
            $this->assertSame($expected, Format::number($input), 'number('.$input.')');
        }

        // Where a naive round or a truncation would disagree with number_format.
        $this->assertSame('1', Format::number(0.5));
        $this->assertSame('2', Format::number(1.5));
        $this->assertSame('3', Format::number(2.5));
        $this->assertSame('1.235', Format::number(1234.567));
        $this->assertSame('1.234.567,89', Format::number(1234567.891, 2));
        $this->assertSame('1.234,3', Format::number(1234.25, 1));

        // A number that crossed the wire from Blade is a string by the time it
        // gets here, so that is the ordinary case rather than an edge one.
        $this->assertSame('275.000', Format::number('275000'));
    }

    /**
     * A share, written the way the reader wrote it, and never as a group of digits.
     *
     * The string case is the whole reason this exists. `scheme_rate` is a
     * `decimal(5,2)` column, so a stored `20.00` comes back out of the database
     * as the string `'20.00'`. Treating that dot as a thousands separator --
     * which is what every other amount in the project does, correctly -- turns a
     * twenty percent share into `2000`, and the form then repopulates a figure
     * the row was never holding.
     */
    #[Test]
    public function it_formats_a_rate_as_a_decimal_and_never_as_a_group(): void
    {
        $this->assertSame('20', Format::rate(20));
        $this->assertSame('20', Format::rate(20.00));
        $this->assertSame('12,5', Format::rate(12.5));
        $this->assertSame('0', Format::rate(0));
        $this->assertSame('100', Format::rate(100));
    }

    /**
     * A padded column, and a figure that arrives as a string.
     *
     * Both are the ordinary case for this column rather than an edge one, and
     * both fail in the same direction: `12,50` is what the reader typed, `12,5`
     * is what they meant, and a form that repopulates with three characters the
     * column cannot hold invites the next save to be refused.
     */
    #[Test]
    public function it_trims_the_padding_a_decimal_column_carries(): void
    {
        $this->assertSame('20', Format::rate('20.00'));
        $this->assertSame('12,5', Format::rate('12.50'));
        $this->assertSame('12,5', Format::rate('12,5'));
        $this->assertSame('7', Format::rate('7.00'));
    }

    #[Test]
    public function it_leaves_a_rate_alone_when_there_is_none(): void
    {
        // Null stays null: a consignor on a flat fee has no rate, and the field
        // has to come back empty rather than reading `0`, which would offer a
        // percentage nobody agreed to.
        $this->assertNull(Format::rate(null));
        $this->assertNull(Format::rate(''));
    }

    /**
     * A count may be absent; an amount of money may not.
     *
     * Pinned because the two helpers disagree on purpose here, and the browser
     * one is the newer of the pair: it is easy to "fix" one side to match the
     * other and leave a receipt reading `Rp—`.
     */
    #[Test]
    public function it_never_reports_a_missing_amount_as_missing(): void
    {
        $this->assertSame('Rp0', Format::rupiah(null));
        $this->assertSame('Rp0', Format::rupiah(''));
        $this->assertSame('Rp0', Format::rupiah('abc'));
        $this->assertSame('Rp0', Format::rupiah(0));

        $this->assertSame(Format::EMPTY, Format::number(null));
        $this->assertSame(Format::EMPTY, Format::number(''));
    }

    #[Test]
    public function it_maps_status_values_to_labels_and_tones(): void
    {
        $this->assertSame('Tersedia', Format::statusLabel(LotStatus::Available));
        $this->assertSame('success', Format::statusType(LotStatus::Available));
        $this->assertSame('Arsip', Format::statusLabel(ConsignorStatus::Archived));
        $this->assertSame('error', Format::statusType(ConsignorStatus::Archived));
        $this->assertSame('Habis', Format::statusLabel('SOLD_OUT'));
        $this->assertSame('warning', Format::statusType('SOLD_OUT'));
    }

    #[Test]
    public function it_falls_back_for_unknown_statuses(): void
    {
        $this->assertSame('Custom Status', Format::statusLabel('CUSTOM_STATUS'));
        $this->assertSame('info', Format::statusType('CUSTOM_STATUS'));
        $this->assertSame(Format::EMPTY, Format::statusLabel(null));
        $this->assertSame('info', Format::statusType(null));
    }

    #[Test]
    public function it_maps_ownership_types(): void
    {
        $this->assertSame('TITIP', Format::ownershipType(OwnerType::Consign));
        $this->assertSame('PRIBADI', Format::ownershipType(OwnerType::Own));
        $this->assertSame('KARANTINA', Format::ownershipType('QUARANTINE'));
        $this->assertSame('PRIBADI', Format::ownershipType(null));
    }

    #[Test]
    public function it_formats_dates(): void
    {
        $this->assertSame('26 Sep 2026', Format::date('2026-09-26 08:00:00'));
        $this->assertSame('26 Sep 2026 08:00 WIB', Format::datetime(Carbon::parse('2026-09-26 08:00:00')));
        $this->assertSame(Format::EMPTY, Format::date(null));
        $this->assertSame(Format::EMPTY, Format::datetime(''));
    }

    #[Test]
    public function it_headlines_enums_and_text(): void
    {
        $this->assertSame('Sold Out', Format::enum(LotStatus::SoldOut));
        $this->assertSame('Sold Out', Format::enum('SOLD_OUT'));
        $this->assertSame(Format::EMPTY, Format::enum(null));
        $this->assertSame('Halo', Format::text('Halo'));
        $this->assertSame(Format::EMPTY, Format::text('   '));
        $this->assertSame('-', Format::text(null, '-'));
    }
}
