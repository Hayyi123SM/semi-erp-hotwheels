/**
 * Where the cashier deliberately put the cursor.
 *
 * `x-scan` takes the keyboard back after every blur, because the common case at a
 * register is a gap between scans rather than the end of them -- a receipt printer
 * that stalls mid-sale should not cost the cashier their place. That instinct is
 * wrong twice over, though, and both times it is the same mistake: treating any
 * focus it does not own as focus it should reclaim.
 *
 * Once, when a person is deliberately typing elsewhere. `[data-allow-focus]` is
 * how a control says so, and it is opt-in because the alternative -- guessing --
 * cannot tell a search box from a button the click just landed on.
 *
 * And once when an overlay is up. SweetAlert2 is the overlay that matters here:
 * the product picker moves the search field into a popup and focuses it, and this
 * directive was pulling focus straight back out roughly 100 ms later. The picker
 * was opening a search box and then typing into the scanner instead, so a search
 * either went nowhere or reached the cart as a scanned code. Asking whether focus
 * sits inside an open dialog is not a guess -- the dialog is a real container with
 * a real class, and focus inside it is the definition of "meant to be here".
 *
 * Both checks are made at refocus time rather than at blur time. The 100 ms delay
 * exists so a blur caused by opening something has landed, which means the answer
 * to "where is focus now" is only known afterwards.
 */
const DIALOG_SELECTOR = ".swal2-container";

function focusBelongsToSomeoneElse(active, el) {
    if (!active || active === el) {
        return false;
    }

    return (
        active.closest("[data-allow-focus]") !== null ||
        active.closest(DIALOG_SELECTOR) !== null
    );
}

export function registerScanDirective(Alpine) {
    Alpine.directive("scan", (el, { expression }, { evaluateLater }) => {
        const evaluate = evaluateLater(expression);

        el.tabIndex = 0;
        el.classList.add("scan-input");

        const refocus = () => {
            setTimeout(() => {
                if (focusBelongsToSomeoneElse(document.activeElement, el)) {
                    return;
                }

                el.focus();
            }, 100);
        };

        let buffer = "";
        let lastKeyTime = 0;

        /**
         * Hand the scanned code to the expression as an argument AND as `$event`.
         *
         * Alpine's own `x-on` passes both (`{ scope: { $event: e }, params: [e] }`),
         * so a directive that collects its own input owes its expressions the same
         * two things. Passing only the receiver left `params` empty, which means
         * `x-scan="addBySku($event)"` silently evaluated `addBySku(undefined)` --
         * the one expression form that looks right and cannot be.
         *
         * The field is cleared after the expression runs, not before: an
         * expression reading `$el.value` is the common case here, and emptying
         * the input first would hand it an empty string.
         */
        const flush = () => {
            if (!buffer) return;
            const value = buffer.trim();
            buffer = "";
            if (!value) return;

            // The receiver is a no-op. Alpine hands it whatever the expression
            // returned, and a return value here means nothing -- the expression's
            // job is the lookup, not the answer. Chaining the old
            // `(next) => next(value)` style made it call the *result* with the
            // code as an argument, which throws on any expression that returns
            // nothing.
            evaluate(() => {}, { scope: { $event: value }, params: [value] });

            el.value = "";
        };

        el.addEventListener("keydown", (event) => {
            if (event.key === "Enter") {
                event.preventDefault();
                flush();
                return;
            }

            if (
                event.key.length === 1 &&
                !event.ctrlKey &&
                !event.metaKey &&
                !event.altKey
            ) {
                buffer += event.key;
            } else if (event.key === "Backspace") {
                buffer = buffer.slice(0, -1);
            }
        });

        el.addEventListener("blur", refocus);
        el.focus();
    });
}
