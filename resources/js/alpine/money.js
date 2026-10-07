import { number } from "../format";

/**
 * Live grouping for an amount being typed.
 *
 * A reader entering rupiah needs to see `1.000.000` while typing, because that
 * is how the same figure is written everywhere else in this app -- the tables,
 * the totals, the receipts. An ungrouped `1000000` in the one field where the
 * number is being decided is where a misplaced digit goes unnoticed.
 *
 * The field shows the grouped figure and a hidden input beside it carries the
 * plain digits, so what reaches validation is `1000000` and never `1.000.000`.
 * That split is also what keeps the unsaved-changes guard honest: it only tracks
 * fields that have a `name`, so it follows the hidden one -- which changes
 * exactly when the reader types -- and ignores the one being formatted.
 *
 * All of it is added by the browser, so the form without it still submits: the
 * server-rendered input keeps its own `name` and sends the raw value it was
 * given. Grouping is a courtesy to the reader, not a step the request depends
 * on, and the digit extraction on the other end of the wire is what actually
 * makes a formatted value safe to receive.
 */

/**
 * The widest an amount can be here, in digits.
 *
 * Every money column in this schema is an `unsignedInteger`, so 4.294.967.295 is
 * the largest value the database will hold. Capping the field at ten digits
 * refuses what could not be saved anyway. The `max` rule on the server is what
 * actually decides, because this is a keystroke filter and not a promise.
 */
const MAX_DIGITS = 10;

/** `12,5`: one comma, two decimals, no thousands separator. */
const MAX_RATE_DECIMALS = 2;

function countDigits(text) {
    let total = 0;

    for (let index = 0; index < text.length; index += 1) {
        if (text[index] >= "0" && text[index] <= "9") {
            total += 1;
        }
    }

    return total;
}

/**
 * `007` is `7`, and `Rp1.500.000` pasted out of a spreadsheet is `1500000`.
 *
 * Anything that is not a digit goes, which is the whole of what this mask is
 * for: a grouped figure and a plain one describe the same number, so whichever
 * arrives is reduced to the part both agree on. Leading zeros go as well, since
 * a reader who typed them was counting digits and not counting thousands.
 */
export function extractDigits(value) {
    return String(value ?? "")
        .replace(/\D/g, "")
        .replace(/^0+(?=\d)/, "");
}

/**
 * `125` becomes `1,25`.
 *
 * A percentage is not a grouped number -- `1.234,5` reads as a figure nobody
 * meant to type -- and it must not be padded either, because `12,5` is what the
 * reader entered and `12,50` is a different string for the same value.
 *
 * The comma is read as the reader's own, not as a separator to be re-derived
 * from the digits, so a comma typed with nothing after it yet survives. Losing
 * it would move the caret out from under someone mid-number, and the caret is
 * the one thing here a reader can actually feel.
 */
export function extractRate(value) {
    const cleaned = String(value ?? "").replace(/[^\d,]/g, "");
    const comma = cleaned.indexOf(",");

    if (comma === -1) {
        return extractDigits(cleaned);
    }

    const digits = extractDigits(cleaned.slice(0, comma));

    // A comma into an empty field has no number to belong to yet.
    if (digits === "") {
        return "";
    }

    const fraction = cleaned
        .slice(comma + 1)
        .replace(/\D/g, "")
        .slice(0, MAX_RATE_DECIMALS);

    return `${digits},${fraction}`;
}

/** An amount as the reader should see it, or `''` for a field left empty. */
export function formatMoney(value) {
    // Truncated from the right, because the digit that was refused is always the
    // one just typed, and a paste longer than the column can hold is cut at the
    // same end rather than silently losing the leading figures.
    const digits = extractDigits(value).slice(0, MAX_DIGITS);

    // Routed through the shared formatter, which caches one `Intl.NumberFormat`
    // per decimal count, so this stays consistent with every other figure in the
    // app instead of being a second opinion about Indonesian grouping.
    return digits === "" ? "" : number(digits, 0);
}

/**
 * Where the caret belongs so the same number of digits sits before it.
 *
 * Counted in digits rather than characters, because formatting changes the
 * character count underneath the reader: typing the fourth digit of `1234`
 * inserts a separator, and a caret restored to the same character index jumps a
 * position to the right on every keystroke past a thousand. By the sixth
 * keystroke it is stranded mid-field with the rest of the number typed behind
 * it, and the mask reads as broken.
 */
export function caretAfterDigits(value, digits) {
    if (digits <= 0) {
        return 0;
    }

    let seen = 0;

    for (let index = 0; index < value.length; index += 1) {
        if (value[index] >= "0" && value[index] <= "9") {
            seen += 1;

            if (seen === digits) {
                return index + 1;
            }
        }
    }

    // The digits the reader aimed past are gone -- cut off at the length cap, or
    // deleted -- so the caret belongs at the end of what is left.
    return value.length;
}

/** What the server is given, read back off the grouped figure the reader sees. */
function toRaw(display, mode) {
    if (mode === "rate") {
        // A comma with nothing after it is a half-typed number, not a decimal
        // with nothing after it, and `1.` is not a value anyone means to store.
        return display.replace(",", ".").replace(/\.$/, "");
    }

    return display.replace(/\D/g, "");
}

function placeCaret(el, position) {
    if (typeof el.setSelectionRange !== "function") {
        return;
    }

    el.setSelectionRange(position, position);
}

/**
 * Puts the number the server will receive behind the field.
 *
 * The visible input is left without a `name` and a hidden one carries the plain
 * digits, so a grouped figure is never the string that reaches validation --
 * where `1.000` is either rejected outright or, on the one field validated as
 * a decimal, quietly stored as `1.00`.
 *
 * Inserted before the name comes off the visible input, so the form is never
 * carrying the name twice, and the reverse order on teardown so it is never
 * carrying it zero times either.
 *
 * @returns {?HTMLInputElement} the mirror, or null when there was no name
 */
function attachMirror(el) {
    const name = el.getAttribute("name");

    if (!name) {
        return null;
    }

    const mirror = el.ownerDocument.createElement("input");

    mirror.type = "hidden";
    mirror.name = name;

    el.parentNode.insertBefore(mirror, el.nextSibling);
    el.removeAttribute("name");

    return mirror;
}

/**
 * Group one field, in place.
 *
 * Split out from the directive so it can be exercised against a real element
 * without booting Alpine, the same way the dialog helper is tested against an
 * injected `fire` rather than a real popup.
 *
 * @param {HTMLInputElement} el
 * @param {'money'|'rate'} [mode] `rate` is a percentage: comma decimals, no grouping
 * @param {{ onInput?: (raw: string) => void }} [hooks] `onInput` receives the plain
 *   value after every regroup, for a caller that keeps the number in its own state
 * @returns {function(): void} teardown
 */
export function attachMoney(el, mode = "money", { onInput = null } = {}) {
    // A number input cannot show grouping at all. The browser refuses any value
    // it cannot parse as a number, so writing `1.000` into one leaves it empty,
    // and the mask would appear to do nothing at all. Reading it as text is what
    // lets the field be numeric to a phone keyboard and grouped for a reader.
    if (el.type === "number") {
        el.type = "text";
    }

    if (!el.getAttribute("inputmode")) {
        el.setAttribute("inputmode", "numeric");
    }

    // Browsers offer to remember what was typed here, and restore a grouped
    // figure into a field that is about to be grouped again.
    el.setAttribute("autocomplete", "off");

    const display = mode === "rate" ? extractRate : formatMoney;

    el.value = display(el.value);

    const mirror = attachMirror(el);
    const sync = () => {
        if (mirror) {
            mirror.value = toRaw(el.value, mode);
        }
    };

    sync();

    const onInputEvent = () => {
        const before = el.value;
        const caret = el.selectionStart ?? before.length;
        const digits = countDigits(before.slice(0, caret));

        const next = display(before);

        // Only moved when the text actually changed, so a keystroke that turned
        // out to be nothing does not also move the caret.
        if (next !== before) {
            el.value = next;
            placeCaret(el, caretAfterDigits(el.value, digits));
        }

        sync();

        // Called after the regroup, so the callback reads the text the reader is
        // actually looking at rather than the one they overtyped. It is given the
        // plain value -- exactly what the mirror would submit -- so a caller never
        // has to strip separators a second time, with a second set of rules.
        onInput?.(toRaw(el.value, mode));
    };

    el.addEventListener("input", onInputEvent);

    // Autofill and a restore-from-history both land without a keystroke, and the
    // mirror would otherwise keep the value the server sent.
    el.addEventListener("change", sync);

    return () => {
        el.removeEventListener("input", onInputEvent);
        el.removeEventListener("change", sync);

        if (mirror) {
            el.setAttribute("name", mirror.name);
            mirror.remove();
        }
    };
}

export function registerMoneyDirective(Alpine) {
    Alpine.directive("money", (el, { value }, { cleanup }) => {
        cleanup(attachMoney(el, value === "rate" ? "rate" : "money"));
    });
}

/**
 * A grouped amount bound to a property, as two plain callbacks.
 *
 * `x-money` groups what a reader types and leaves it there: the value lives in the
 * input, and anything that needs the number -- a total, a change calculation, a
 * request body -- has to read it back out. That is the right shape for a form
 * field, where the field is the record. It is the wrong shape for a running
 * calculation like the money received at a register, where the subtotal is already
 * a live computed value and the tender has to sit beside it as one.
 *
 * Binding both at once with `x-model` and `x-money` does not work, and it fails in
 * the way that is hardest to notice. Two things would own the field: the mask,
 * which rewrites the text on every keystroke, and the model, which writes the text
 * back from the number it was handed. Typing `1000` makes the mask write `1.000`,
 * and the model then parses that as one and overwrites what the reader typed.
 * Both directions are silent.
 *
 * So the mask is the only writer. The reader's keystrokes go out through `set`, and
 * the property comes back in through `sync` only when it disagrees with what is
 * already on screen. An unchanged value means no write, and that is what keeps the
 * caret where the reader left it instead of jumping to the end of a number being
 * edited in the middle.
 *
 * `set` receives digits as a string, never a number. `''` and `'0'` are different
 * states -- nothing entered versus zero entered -- and a number cannot tell them
 * apart, so an empty cash field and a field showing `0` would be the same value to
 * anything comparing them.
 *
 * @param {HTMLInputElement} el
 * @param {{ get: (callback: (value: unknown) => void) => void, set: (raw: string) => void }} model
 * @returns {{ sync: () => void, destroy: () => void }}
 */
export function attachMoneyModel(el, { get, set }) {
    /**
     * The plain digits the field and the property last agreed on.
     *
     * `null` until the first read, so the initial sync always writes. What it is
     * for is telling the two writers apart: the property changing under us has to
     * repaint the field, while a change that came *from* the field must not, or
     * every keystroke repaints what was just typed and takes the caret with it.
     */
    let agreed = null;

    const teardown = attachMoney(el, "money", {
        onInput: (raw) => {
            agreed = raw;

            set(raw);
        },
    });

    const sync = () => {
        let value;

        get((model) => {
            value = model;
        });

        const raw = value === null || value === undefined ? "" : String(value);

        if (raw === agreed) {
            return;
        }

        agreed = raw;

        el.value = raw === "" ? "" : formatMoney(raw);
    };

    return { sync, destroy: teardown };
}

export function registerMoneyModelDirective(Alpine) {
    Alpine.directive(
        "money-model",
        (el, { expression }, { effect, cleanup, evaluateLater }) => {
            // The pair `x-model` itself builds: read the property, and assign it by
            // evaluating the same expression with a placeholder on the right.
            //
            // Only the expression, never the element as well. The `evaluateLater`
            // that comes with the directive utilities is already bound to this
            // element, so passing `el` again put the *element* where the
            // expression belongs, and Alpine threw on it before this directive
            // got as far as attaching the mask. Nothing about that throw points
            // at the directive: it surfaced in the console and the field simply
            // behaved like an unformatted input, letters and all.
            const evaluateGet = evaluateLater(expression);
            const evaluateSet = evaluateLater(`${expression} = __money`);

            const model = attachMoneyModel(el, {
                get: (callback) => evaluateGet(callback),
                set: (value) => {
                    evaluateSet(() => {}, { scope: { __money: value } });
                },
            });

            cleanup(model.destroy);

            // Runs on the property and on render, so a value set from code -- a
            // quick-cash button, a reset after a sale -- reaches the field without
            // needing an event to trigger it.
            effect(() => model.sync());
        },
    );
}
