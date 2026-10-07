/**
 * The browser half of `app/Support/Format.php`.
 *
 * Both sides exist because the same numbers are written in two places: pages
 * rendered on the server, and islands re-rendered in the browser as the reader
 * works. A count shown as `1234` on load and `1.234` after an edit, or `Rp1.500`
 * in a table and `Rp1500` in a total, reads as two different numbers.
 *
 * `id-ID` is the locale that produces the Indonesian separators, and the tests
 * pin this against the PHP helper on the values both are actually given.
 */

export const EMPTY = '—';

/**
 * `Intl.NumberFormat` is expensive to build, and the number of distinct decimal
 * counts a session uses is tiny, so they are kept rather than rebuilt per cell.
 */
const formatters = new Map();

function formatter(decimals) {
    let cached = formatters.get(decimals);

    if (!cached) {
        cached = new Intl.NumberFormat('id-ID', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        });
        formatters.set(decimals, cached);
    }

    return cached;
}

/** A count, a total, a sum: `1234` becomes `1.234`. */
export function number(value, decimals = 0) {
    if (value === null || value === undefined || value === '') {
        return EMPTY;
    }

    const parsed = Number(value);

    // Not something a page means to show. PHP's cast would quietly make it a
    // zero; here it is left visibly absent so the mistake gets looked at.
    if (! Number.isFinite(parsed)) {
        return EMPTY;
    }

    return formatter(decimals).format(parsed);
}

/** An amount of money: `1500` becomes `Rp1.500`. */
export function rupiah(value) {
    // PHP's `Format::rupiah()` casts through to float and never comes back
    // empty, because a total is never missing: a receipt reading `Rp—` is
    // broken, and one reading `Rp0` is a real, if unusual, answer. So this does
    // not go through `number()`, whose stand-in is the em dash, and anything
    // that is not a number is zero here exactly as it is there.
    const parsed = Number(value);

    return `Rp${formatter(0).format(Number.isFinite(parsed) ? parsed : 0)}`;
}

/** A string that may be missing: `''` and blanks become an em dash. */
export function text(value, empty = EMPTY) {
    return value === null || value === undefined || String(value).trim() === '' ? empty : String(value);
}
