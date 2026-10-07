import { describe, it, expect, afterEach } from "vitest";

import {
    attachMoney,
    attachMoneyModel,
    caretAfterDigits,
    extractDigits,
    extractRate,
    formatMoney,
} from "./money";

/**
 * A field the way Blade renders one, with a parent to hang the hidden mirror on.
 */
function field({ name = "amount", value = "", type = "text" } = {}) {
    const wrap = document.createElement("div");
    const el = document.createElement("input");

    if (name !== null) {
        el.setAttribute("name", name);
    }

    el.type = type;
    el.value = value;
    wrap.append(el);
    document.body.append(wrap);

    return {
        el,
        wrap,
        mirror: () => wrap.querySelector('input[type="hidden"]'),
    };
}

/** What a keystroke does to a field, caret and all. */
function type(el, value, caret = value.length) {
    el.value = value;
    el.setSelectionRange(caret, caret);
    el.dispatchEvent(new Event("input"));

    return el;
}

afterEach(() => {
    document.body.innerHTML = "";
});

describe("money: the digits worth keeping", () => {
    it.each([
        ["1000", "1000"],
        ["1.000", "1000"],
        ["1.000.000", "1000000"],
        ["Rp1.500.000", "1500000"],
        // Where this actually comes from: a spreadsheet cell, a chat message, a
        // column copied out of a report that was written for another country.
        ["1,500,000", "1500000"],
        ["1 500 000", "1500000"],
        ["abc", ""],
        ["-", ""],
        ["", ""],
    ])("reads %j as %j", (input, expected) => {
        expect(extractDigits(input)).toBe(expected);
    });

    it("reads nothing as no digits at all", () => {
        expect(extractDigits(null)).toBe("");
        expect(extractDigits(undefined)).toBe("");
    });

    it("drops leading zeros, which were never part of the amount", () => {
        // A reader typing `007` is counting digits, not counting thousands, and
        // keeping the zeros would store a number they did not mean.
        expect(extractDigits("007")).toBe("7");
        expect(extractDigits("000")).toBe("0");
    });

    it("refuses a negative, because no field here stores one", () => {
        // Every amount in this schema is an unsignedInteger except a handful of
        // signed ledger columns, and none of those is behind this mask. Letting
        // the sign through would offer a value the column cannot hold.
        expect(extractDigits("-5000")).toBe("5000");
    });
});

describe("money: an amount as it should look", () => {
    it.each([
        ["0", "0"],
        ["1000", "1.000"],
        ["10000", "10.000"],
        ["100000", "100.000"],
        ["1000000", "1.000.000"],
        // A zero is a fact, not an absence: an amount of nothing is a real
        // answer, and one that reads as a dash looks like a missing field.
        ["0", "0"],
    ])("shows %j as %j", (input, expected) => {
        expect(formatMoney(input)).toBe(expected);
    });

    it("leaves an empty field empty rather than filling it with a zero", () => {
        // `min_payout` is nullable, and a blank that became `0` on the way out
        // would leave the reader with no way to clear the field at all.
        expect(formatMoney("")).toBe("");
    });

    it("cuts at ten digits, which is all an unsignedInteger can hold", () => {
        // The digit refused is the one just typed, so it goes at the end.
        expect(formatMoney("12345678901")).toBe("1.234.567.890");
    });
});

describe("money: a percentage, which is a different number", () => {
    it("keeps the comma the reader typed, with nothing after it yet", () => {
        // Dropping it would move the caret out from under someone mid-number.
        expect(extractRate("12,")).toBe("12,");
    });

    it.each([
        ["12", "12"],
        ["12,5", "12,5"],
        ["0,25", "0,25"],
        ["100", "100"],
        ["1.000", "1000"],
    ])("reads %j as %j", (input, expected) => {
        expect(extractRate(input)).toBe(expected);
    });

    it("keeps two decimals at most", () => {
        expect(extractRate("12,567")).toBe("12,56");
    });

    it("takes the first comma as the decimal point and drops the rest", () => {
        expect(extractRate("12,5,5")).toBe("12,55");
    });

    it("will not hang a decimal off an empty field", () => {
        expect(extractRate(",5")).toBe("");
    });

    it("reads a grouped figure as the thousand it is, not as a decimal", () => {
        // This is the whole reason a percentage is masked separately. `1.000` as
        // a decimal is `1.00`, which passes every rule on `scheme_rate` and
        // stores a hundredth of what was typed. Read as a thousand it becomes
        // `1000`, which `between:0,100` refuses out loud.
        expect(extractRate("1.000")).toBe("1000");
    });

    it("does not pad a decimal out to two places", () => {
        // `12,5` is what the reader typed; `12,50` is a different string for the
        // same value, and a field that rewrites what you wrote is a field you
        // stop trusting.
        expect(extractRate("12,5")).toBe("12,5");
    });
});

describe("money: where the caret belongs", () => {
    it.each([
        ["1.234", 0, 0],
        // Straight after the digit itself, which is before the separator rather
        // than after it: the separator is a character the reader never typed, so
        // counting it as one would put the caret a position along every time.
        ["1.234", 1, 1],
        ["1.234", 3, 4],
        ["1.234", 4, 5],
        ["1.234.567", 7, 9],
        // A decimal comma is not a digit, so it does not push the caret along.
        ["12,5", 3, 4],
    ])(
        "puts the caret at %i for %j after %i digits",
        (value, digits, expected) => {
            expect(caretAfterDigits(value, digits)).toBe(expected);
        },
    );

    it("clamps to the end when the digits the reader aimed past are gone", () => {
        expect(caretAfterDigits("1.234", 99)).toBe(5);
    });
});

describe("money: a field with the mask on it", () => {
    it("groups the value the server rendered", () => {
        const { el } = field({ value: "65000" });

        attachMoney(el);

        expect(el.value).toBe("65.000");
    });

    it("reads a number input as text, because a number input cannot be grouped", () => {
        // The browser refuses any value it cannot parse as a number, so writing
        // `1.000` into one leaves it empty and the mask looks like a no-op.
        const { el } = field({ type: "number" });

        attachMoney(el);

        expect(el.type).toBe("text");
    });

    it("keeps the name and puts the plain digits behind it", () => {
        const { el, mirror } = field({
            name: "default_list_price",
            value: "65000",
        });

        attachMoney(el);

        // This is the whole point: a grouped figure must never be the string
        // that reaches validation, where `1.000` is either refused outright or
        // -- on the one field validated as a decimal -- stored as `1.00`.
        expect(el.hasAttribute("name")).toBe(false);
        expect(mirror().name).toBe("default_list_price");
        expect(mirror().value).toBe("65000");
    });

    it("groups what the reader types and keeps the mirror in step", () => {
        const { el, mirror } = field({ name: "amount" });

        attachMoney(el);
        type(el, "1000000");

        expect(el.value).toBe("1.000.000");
        expect(mirror().value).toBe("1000000");
    });

    it("keeps the name it is given, and formats it, when there is no mirror to make", () => {
        const { el, mirror } = field({ name: null, value: "1000" });

        attachMoney(el);

        expect(el.value).toBe("1.000");
        expect(el.getAttribute("name")).toBeNull();
        expect(mirror()).toBeNull();
    });

    it("throws away a letter rather than storing it", () => {
        const { el, mirror } = field({ name: "amount" });

        attachMoney(el);
        type(el, "1a2b3");

        expect(el.value).toBe("123");
        expect(mirror().value).toBe("123");
    });

    it("leaves a cleared field cleared", () => {
        const { el, mirror } = field({ name: "min_payout", value: "100000" });

        attachMoney(el);
        type(el, "");

        // A blank that turned into `0` on the way out would take `nullable` with
        // it and leave the reader unable to empty the field.
        expect(el.value).toBe("");
        expect(mirror().value).toBe("");
    });

    it("refuses an eleventh digit rather than storing a number too big to hold", () => {
        const { el } = field({ name: "amount" });

        attachMoney(el);
        type(el, "12345678901");

        expect(el.value).toBe("1.234.567.890");
    });

    it("keeps the caret with the digit the reader was on", () => {
        const { el } = field({ name: "amount" });

        attachMoney(el);
        type(el, "1234", 4);

        // The separator inserted above the caret is a character the reader never
        // typed, and a caret held to a character index would jump a position to
        // the right on every keystroke past a thousand.
        expect(el.value).toBe("1.234");
        expect(el.selectionStart).toBe(5);
    });

    it("does not move the caret when the keystroke changed nothing", () => {
        const { el } = field({ name: "amount", value: "1000" });

        attachMoney(el);
        type(el, "1.000", 5);

        // The value was already grouped, so the reader is left exactly where
        // they were. Re-placing the caret on a no-op would fight the browser's
        // own cursor handling for no reason.
        expect(el.value).toBe("1.000");
        expect(el.selectionStart).toBe(5);
    });

    it("holds the caret to the same digit when a stray character is refused", () => {
        const { el } = field({ name: "amount", value: "1000" });

        attachMoney(el);
        // Caret at 2, with the letter typed at the end: one digit sits before
        // it, so dropping the letter must leave the caret just after that `1`.
        type(el, "1.000a", 2);

        expect(el.value).toBe("1.000");
        expect(el.selectionStart).toBe(1);
    });

    it("sends a percentage as a decimal the server can read", () => {
        const { el, mirror } = field({ name: "scheme_rate" });

        attachMoney(el, "rate");
        type(el, "12,5");

        // The comma is for the reader. The rule on the other end is `numeric`,
        // which wants `12.5` -- the one place in this app where a comma in a
        // submitted value has to be a point rather than a pause.
        expect(el.value).toBe("12,5");
        expect(mirror().value).toBe("12.5");
    });

    it("does not send a half-typed comma on as a decimal point", () => {
        const { el, mirror } = field({ name: "scheme_rate" });

        attachMoney(el, "rate");
        type(el, "12,");

        expect(mirror().value).toBe("12");
    });

    it("follows a value the browser filled in without a keystroke", () => {
        const { el, mirror } = field({ name: "amount" });

        attachMoney(el);
        el.value = "7500";
        el.dispatchEvent(new Event("change"));

        // Autofill and a restore-from-history both land this way, and the mirror
        // would otherwise go on sending the value the server rendered.
        expect(mirror().value).toBe("7500");
    });

    it("gives the name back and takes the mirror away when torn down", () => {
        const { el, mirror } = field({
            name: "default_list_price",
            value: "65000",
        });

        const teardown = attachMoney(el);

        expect(mirror()).not.toBeNull();

        teardown();

        // The reverse order of how it was attached, so the form is never caught
        // carrying the name zero times either.
        expect(el.getAttribute("name")).toBe("default_list_price");
        expect(mirror()).toBeNull();
    });

    it("stops listening once torn down", () => {
        const { el, mirror } = field({ name: "amount", value: "1000" });

        const teardown = attachMoney(el);

        teardown();
        type(el, "9999");

        // A tree that is initialised twice -- which this app does whenever a
        // dialog is opened over markup Alpine has already walked -- would
        // otherwise leave the first mask running against the field forever.
        expect(el.value).toBe("9999");
        expect(mirror()).toBeNull();
    });
});

/**
 * A field bound to a property, the way `x-money-model` binds one.
 *
 * `get` and `set` are plain closures over a variable rather than an Alpine scope,
 * because what is under test here is the two-way dance between the mask and the
 * property -- not Alpine. `sync` stands in for the `effect` the directive runs.
 */
function modelField({ value = "" } = {}) {
    const { el } = field({ name: null, value });
    let property = value;

    const model = attachMoneyModel(el, {
        get: (callback) => callback(property),
        set: (raw) => {
            property = raw;
        },
    });

    // The initial read, exactly as the directive's `effect` does it.
    model.sync();

    return {
        el,
        model,
        read: () => property,
        writes: watchWrites(el),
        /** Assign and sync, the way the directive's own setter is followed. */
        write: (next) => {
            property = next;
            model.sync();
        },
        /** Assign without syncing, standing in for an assignment elsewhere. */
        assign: (next) => {
            property = next;
        },
    };
}

/**
 * Counts assignments to a field's `value`.
 *
 * Assigning a text input's value moves its caret to the end of the text, even when
 * the text is unchanged -- which is exactly why the directive must not assign a
 * value the field already shows. That difference is invisible to happy-dom, so a
 * test asserting the resulting string or caret position cannot tell a correct
 * implementation from a wrong one. Counting the writes can.
 */
function watchWrites(el) {
    const descriptor = Object.getOwnPropertyDescriptor(
        Object.getPrototypeOf(el),
        "value",
    );

    let writes = 0;

    Object.defineProperty(el, "value", {
        configurable: true,
        get() {
            return descriptor.get.call(this);
        },
        set(next) {
            writes += 1;
            descriptor.set.call(this, next);
        },
    });

    return () => writes;
}

describe("a grouped amount bound to a property", () => {
    it("groups what is typed and hands over plain digits", () => {
        const { el, read } = modelField();

        type(el, "1000");

        // The reader sees one figure; the property gets the number it means. A
        // property holding `1.000` would be a different number, not a prettier
        // spelling of the same one.
        expect(el.value).toBe("1.000");
        expect(read()).toBe("1000");
    });

    it("starts empty when the property is empty", () => {
        const { el } = modelField({ value: "" });

        expect(el.value).toBe("");
    });

    it("keeps an empty field empty", () => {
        const { el, read } = modelField({ value: "" });

        // `0` would be a zero the cashier typed. An empty field must not show one,
        // and the property must not acquire one either.
        type(el, "");

        expect(el.value).toBe("");
        expect(read()).toBe("");
    });

    it("shows a property that was set from code", () => {
        // The quick-cash buttons assign the property without touching the field.
        const { el, write } = modelField();

        write("50000");

        expect(el.value).toBe("50.000");
    });

    it("clears the field when the property is emptied", () => {
        // What a finished sale does. A leftover amount would be offered as tender
        // for the next customer.
        const { el, write } = modelField({ value: "50000" });

        write("");

        expect(el.value).toBe("");
    });

    it("leaves the caret alone when the field already agrees", () => {
        // The mask rewrites the text on every keystroke. If the directive then
        // wrote the value back from the property, the field would be repainted
        // under the caret -- and editing a number in the middle would shove the
        // cursor to the end on every keystroke.
        const { el, read } = modelField();

        type(el, "1.000.000", 3);

        expect(read()).toBe("1000000");
        expect(el.selectionStart).toBe(3);
    });

    it("does not repaint the field with what it just sent", () => {
        // The directive re-reads the property after every change, the way a
        // reactive `effect` does. Assigning unconditionally puts back the very
        // text the mask produced a tick earlier -- an identical string, so the
        // field still looks right, but the assignment itself moves the caret to
        // the end. Edit an amount in the middle and the cursor leaves you after
        // every keystroke.
        const { el, model, writes } = modelField();

        type(el, "250000");
        expect(el.value).toBe("250.000");

        const before = writes();

        model.sync();

        expect(writes()).toBe(before);
    });

    it("repaints when something else changed the property", () => {
        // The guard compares against the last agreed value, not against the DOM,
        // so a genuinely different figure still has to reach the field. The real
        // case is a quick-cash button, which assigns the property and has no idea
        // the field exists.
        const { el, model, assign } = modelField();

        type(el, "250000");
        expect(el.value).toBe("250.000");

        assign("75000");
        model.sync();

        expect(el.value).toBe("75.000");
    });

    it("ignores a non-digit paste entirely", () => {
        const { el, read } = modelField();

        type(el, "Rp1.500.000,-");

        expect(el.value).toBe("1.500.000");
        expect(read()).toBe("1500000");
    });

    it("numbers a field as text so grouping is possible at all", () => {
        const { el } = field({ name: null, type: "number" });

        attachMoneyModel(el, { get: (cb) => cb(""), set: () => {} });

        // A number input refuses any value it cannot parse, so writing `1.000`
        // into one leaves it empty and the mask appears to do nothing.
        expect(el.type).toBe("text");
        expect(el.getAttribute("inputmode")).toBe("numeric");
    });

    it("adds no hidden mirror, because there is no form to submit", () => {
        const { el } = modelField();

        expect(el.getAttribute("name")).toBe(null);
        expect(el.parentNode.querySelector('input[type="hidden"]')).toBe(null);
    });

    it("stops listening when it is torn down", () => {
        const { el, model, read } = modelField();

        model.destroy();
        type(el, "1000");

        expect(read()).toBe("");
    });
});
