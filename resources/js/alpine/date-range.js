/**
 * Date-range filter for the report pages.
 *
 * Two native `<input type="date">` elements carry `from`/`to` into the query
 * string exactly as before, so nothing on the server changes. What this adds is
 * a "Cepat pilih" menu: "7 hari terakhir" is a question about *today*, not a
 * pair of dates the reader should have to work out and type by hand.
 *
 * The menu remembers what was picked (`preset`) and, on load, reads it back off
 * the dates it was given -- so the range the page is actually showing is also
 * the option that is showing, without a second copy of it in the URL. A custom
 * range matches no preset and honestly reads as none.
 *
 * `from` and `to` stay strings in local calendar time (`YYYY-MM-DD`), never
 * `Date` objects. The value the server parses is a date, and a `Date` crossing
 * `toISOString()` shifts a day for anyone west of UTC -- which is how "today"
 * quietly becomes "yesterday" for a shop in Jakarta.
 */

/** The menu's named ranges, in the order the dropdown lists them. */
const PRESETS = ['today', 'last7', 'last30', 'month'];

export function dateRange({ from = '', to = '' } = {}) {
    return {
        from,
        to,

        /**
         * The option the menu shows. Derived from the dates at construction
         * rather than defaulted to empty, because the page already prefills the
         * effective range: the default 30-day window therefore reads back as
         * "30 hari terakhir" instead of a menu that looks untouched.
         */
        preset: matchPreset(from, to),

        /**
         * Fill both ends from a menu choice.
         *
         * An unknown value is ignored on purpose: the menu is a list of presets,
         * not user input, and a stray key should leave the reader's own dates
         * alone.
         */
        applyPreset(preset) {
            const range = presetRange(preset, new Date());

            if (range === null) {
                return;
            }

            [this.from, this.to] = range;
            this.preset = preset;
        },
    };
}

/**
 * The first preset whose range equals the dates it was given, or `''` for a
 * custom range. Compared as `YYYY-MM-DD` strings so the answer does not depend
 * on a `Date`'s time of day.
 */
function matchPreset(from, to) {
    for (const preset of PRESETS) {
        const [start, end] = presetRange(preset, new Date());

        if (start === from && end === to) {
            return preset;
        }
    }

    return '';
}

/**
 * The `[from, to]` a preset names, or `null` for a name the menu does not offer.
 * `now` is injected so the caller -- and the tests -- can pin the clock.
 */
function presetRange(preset, now) {
    switch (preset) {
        case 'today':
            return [inputDate(now), inputDate(now)];
        case 'last7':
            return daysBack(now, 7);
        case 'last30':
            return daysBack(now, 30);
        case 'month':
            return [inputDate(new Date(now.getFullYear(), now.getMonth(), 1)), inputDate(now)];
        default:
            return null;
    }
}

/**
 * A window of `days` days ending today, counting today as one of them -- so
 * "7" reaches back six days, the span a reader means by "seminggu terakhir".
 */
function daysBack(now, days) {
    const start = new Date(now);
    start.setDate(start.getDate() - (days - 1));

    return [inputDate(start), inputDate(now)];
}

/**
 * A `Date` as the `YYYY-MM-DD` an `<input type="date">` expects, in local time.
 *
 * Built from the local getters rather than `toISOString()`, whose UTC output is
 * the source of the off-by-one-day bug this helper exists to avoid.
 */
function inputDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}
