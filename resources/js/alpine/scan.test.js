import { describe, it, expect, vi, afterEach } from "vitest";
import { registerScanDirective } from "./scan";

/**
 * Direktif ini bukan Alpine: ia memasang `keydown` sendiri di elemen yang
 * dikembangkannya, lalu menyerahkan kodenya ke ekspresi Alpine.
 *
 * Yang diuji adalah kontrak yang dijanjikan ekspresi itu -- bukan kebetulan
 * bahwa sebuah fungsi dipanggil. Stub `evaluateLater` di sini benar-benar
 * mengeksekusi ekspresi terhadap scope dan params yang diberikan, seperti
 * Alpine. Stub yang hanya mencatat "dipanggil" akan lulus untuk
 * `addBySku($event)` sementara `$event`-nya `undefined` -- persis kegagalan
 * yang sebelumnya ada di file ini.
 */
function mount(expression) {
    const el = document.createElement("input");
    document.body.append(el);

    const addBySku = vi.fn();
    const push = vi.fn();
    const evaluations = [];

    registerScanDirective({
        directive(name, handler) {
            if (name !== "scan") return;

            handler(
                el,
                { expression },
                {
                    evaluateLater(expr) {
                        return (receiver, extras = {}) => {
                            const scope = {
                                ...(extras.scope ?? {}),
                                $el: el,
                                addBySku,
                                $store: { toast: { push } },
                            };

                            evaluations.push({ expression: expr, extras });

                            // Alpine yang memberikan `receiver`: hasil ekspresi, atau
                            // kembaliannya kalau hasilnya fungsi. Bukan kode yang
                            // dipindai -- itu masuk lewat `scope` dan `params`.
                            const result = new Function(
                                ...Object.keys(scope),
                                `return (${expr})`,
                            )(...Object.values(scope));

                            receiver(
                                typeof result === "function"
                                    ? result(...(extras.params ?? []))
                                    : result,
                            );
                        };
                    },
                },
            );
        },
    });

    return { el, addBySku, push, evaluations };
}

/** Ketik satu karakter, seperti scanner yang mengirimnya. */
function type(el, text) {
    for (const key of text) {
        el.dispatchEvent(new KeyboardEvent("keydown", { key, bubbles: true }));
        el.value += key;
    }
}

function pressEnter(el) {
    el.dispatchEvent(
        new KeyboardEvent("keydown", {
            key: "Enter",
            bubbles: true,
            cancelable: true,
        }),
    );
}

afterEach(() => {
    document.body.innerHTML = "";
});

describe("x-scan: handing the code over", () => {
    it("calls the expression with the scanned code, not with nothing", () => {
        const { el, addBySku } = mount("addBySku($event)");

        type(el, "HW-001");
        pressEnter(el);

        expect(addBySku).toHaveBeenCalledWith("HW-001");
    });

    it("still works for an expression that reads `$el.value`", () => {
        const { el, push } = mount('$store.toast.push("SKU " + $el.value)');

        type(el, "HW-002");
        pressEnter(el);

        expect(push).toHaveBeenCalledWith("SKU HW-002");
    });

    it("empties the field, so the next scan does not append to the last one", () => {
        const { el, addBySku } = mount("addBySku($event)");

        type(el, "HW-001");
        pressEnter(el);
        type(el, "HW-002");
        pressEnter(el);

        expect(addBySku.mock.calls).toEqual([["HW-001"], ["HW-002"]]);
    });

    it("says nothing for an empty Enter", () => {
        const { el, addBySku } = mount("addBySku($event)");

        pressEnter(el);

        expect(addBySku).not.toHaveBeenCalled();
    });

    it("trims, because a wedge can leave a stray character at either end", () => {
        const { el, addBySku } = mount("addBySku($event)");

        type(el, " HW-003 ");
        pressEnter(el);

        expect(addBySku).toHaveBeenCalledWith("HW-003");
    });

    it("swallows Enter instead of submitting the surrounding form", () => {
        const { el } = mount("addBySku($event)");

        const event = new KeyboardEvent("keydown", {
            key: "Enter",
            bubbles: true,
            cancelable: true,
        });
        el.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
    });

    it("lets Backspace fix a mistyped code before Enter", () => {
        const { el, addBySku } = mount("addBySku($event)");

        type(el, "HW-00X");
        el.dispatchEvent(
            new KeyboardEvent("keydown", { key: "Backspace", bubbles: true }),
        );
        pressEnter(el);

        expect(addBySku).toHaveBeenCalledWith("HW-00");
    });
});

describe("x-scan: taking the keyboard back", () => {
    it("refocuses after blur, because the cashier is not done scanning", async () => {
        const { el } = mount("addBySku($event)");

        el.dispatchEvent(new Event("blur"));
        await new Promise((resolve) => setTimeout(resolve, 120));

        expect(document.activeElement).toBe(el);
    });

    it("leaves focus alone where a person is deliberately typing", async () => {
        const { el } = mount("addBySku($event)");
        const other = document.createElement("input");
        other.setAttribute("data-allow-focus", "");
        document.body.append(other);
        other.focus();

        el.dispatchEvent(new Event("blur"));
        await new Promise((resolve) => setTimeout(resolve, 120));

        expect(document.activeElement).toBe(other);
    });

    it("leaves focus alone inside an open dialog", async () => {
        // The product picker moves its search field into a SweetAlert2 popup and
        // focuses it. `x-scan` used to pull focus straight back out of the popup
        // about 100 ms later, so the picker opened a search box and then typed
        // into the scanner: every keystroke went into the scan buffer, and Enter
        // sent it to the cart as though it had been a scanned code.
        //
        // A dialog is not marked with `[data-allow-focus]` because it is not
        // markup anyone wrote -- it is built by SweetAlert2 at open time. So this
        // is checked against the container the library actually creates.
        const { el } = mount("addBySku($event)");
        const dialog = document.createElement("div");

        dialog.className = "swal2-container";

        const search = document.createElement("input");

        dialog.append(search);
        document.body.append(dialog);
        search.focus();

        el.dispatchEvent(new Event("blur"));
        await new Promise((resolve) => setTimeout(resolve, 120));

        expect(document.activeElement).toBe(search);

        dialog.remove();
    });

    it("still takes focus back once the dialog is gone", async () => {
        // The guard is scoped to an open dialog, not to "a dialog was opened at
        // some point". Once the popup is removed the cashier is back at the
        // register, and the scan field is where they expect to be.
        const { el } = mount("addBySku($event)");
        const dialog = document.createElement("div");

        dialog.className = "swal2-container";
        document.body.append(dialog);

        const field = document.createElement("input");

        dialog.append(field);
        field.focus();

        dialog.remove();
        el.dispatchEvent(new Event("blur"));
        await new Promise((resolve) => setTimeout(resolve, 120));

        expect(document.activeElement).toBe(el);
    });
});
