import { describe, it, expect } from 'vitest';

import { EMPTY, number, rupiah, text } from './format';

/**
 * These are the browser half of `app/Support/Format.php`, and the point of the
 * module is that the two agree. Each expectation below is the string PHP's
 * `Format::number()` was verified to return for the same input, so a change to
 * one side that is not made to the other fails here rather than showing up as a
 * table that says `1234` and a total that says `1.234`.
 */
describe('format: a number, the way this project writes it', () => {
    it.each([
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
    ])('groups %i as %s', (input, expected) => {
        expect(number(input)).toBe(expected);
    });

    it('rounds halves away from zero, as PHP does', () => {
        // The three cases where a naive `Math.round` or a truncation would
        // disagree with `number_format`, and where a cell and its own row total
        // would stop adding up.
        expect(number(0.5)).toBe('1');
        expect(number(1.5)).toBe('2');
        expect(number(2.5)).toBe('3');
        expect(number(1234.567)).toBe('1.235');
    });

    it('keeps the decimals it is asked for', () => {
        expect(number(1234567.891, 2)).toBe('1.234.567,89');
        expect(number(1234.25, 1)).toBe('1.234,3');
    });

    it('reads a number that arrived as a string', () => {
        // Blade hands values over as strings often enough that this is the
        // normal case, not an edge case.
        expect(number('275000')).toBe('275.000');
    });

    it('shows an em dash for nothing rather than a zero', () => {
        // A count that came out zero is a fact worth stating; a count that is
        // absent is not, and reading it as "none" is how a broken query gets
        // signed off.
        expect(number(null)).toBe(EMPTY);
        expect(number(undefined)).toBe(EMPTY);
        expect(number('')).toBe(EMPTY);
    });

    it('shows an em dash for something that is not a number at all', () => {
        expect(number('abc')).toBe(EMPTY);
        expect(number(Number.POSITIVE_INFINITY)).toBe(EMPTY);
        expect(number(Number.NaN)).toBe(EMPTY);
    });
});

describe('format: an amount of money', () => {
    it('prefixes the currency', () => {
        expect(rupiah(1500)).toBe('Rp1.500');
        expect(rupiah(1234567)).toBe('Rp1.234.567');
        expect(rupiah(0)).toBe('Rp0');
    });

    it('still shows a zero for an absent amount, because a total is never missing', () => {
        // Deliberately not the em dash a count would get: a receipt that reads
        // "Rp—" is broken, and a receipt reading "Rp0" is a real, if unusual,
        // answer.
        expect(rupiah(null)).toBe('Rp0');
        expect(rupiah(undefined)).toBe('Rp0');
        expect(rupiah('')).toBe('Rp0');
    });

    it('counts anything unreadable as zero, as the PHP cast does', () => {
        expect(rupiah('abc')).toBe('Rp0');
        expect(rupiah(Number.NaN)).toBe('Rp0');
    });
});

describe('format: a string that may be missing', () => {
    it('stands in for blanks', () => {
        expect(text(null)).toBe(EMPTY);
        expect(text('')).toBe(EMPTY);
        expect(text('   ')).toBe(EMPTY);
    });

    it('leaves a real value alone', () => {
        expect(text('Hot Wheels')).toBe('Hot Wheels');
        expect(text('  Hot Wheels  ')).toBe('  Hot Wheels  ');
    });

    it('takes the stand-in to use', () => {
        expect(text('', 'Tidak ada')).toBe('Tidak ada');
    });
});

describe('format: reuse', () => {
    it('does not rebuild the formatter on every cell', () => {
        // A table renders hundreds of these per page. `Intl.NumberFormat` is one
        // of the more expensive objects to construct, so they are cached per
        // decimal count, and this is what keeps that cache honest.
        number(1); // Warm the cache first, so the count below is about the loop.

        const before = Intl.NumberFormat;
        let built = 0;

        Intl.NumberFormat = function (...args) {
            built += 1;

            return new before(...args);
        };

        try {
            for (let i = 0; i < 500; i += 1) {
                number(i);
            }

            expect(built).toBe(0);
        } finally {
            Intl.NumberFormat = before;
        }

        expect(number(1000)).toBe('1.000');
    });
});
