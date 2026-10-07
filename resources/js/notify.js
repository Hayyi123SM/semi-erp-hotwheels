import Swal from "sweetalert2";
import { acquireBodyLock, releaseBodyLock } from "./alpine/scroll-lock";

/**
 * The one place a page raises a message or asks a question.
 *
 * Two mechanisms live here on purpose, and only two.
 *
 * Messages stay ours. A SweetAlert2 popup occupies the single container the
 * library keeps in module state, so firing a second popup while one is open
 * replaces it outright, even with a `target` of its own. A page that raises a
 * toast from inside a dialog would therefore close the dialog it is standing
 * in, so the toast is a small list rendered by the layout and this module only
 * feeds it. That also keeps the toast out of the body scroll lock, which is
 * the right behaviour for something nobody has to dismiss.
 *
 * Dialogs are SweetAlert2's. They are what the library is actually for: the
 * focus trap, the ARIA wiring, the escape rule and a button layout that
 * behaves, none of which is worth rebuilding.
 *
 * The object returned by {@link createNotify} doubles as the Alpine `toast`
 * store, so `$store.toast.push(...)` keeps working and there is no second path
 * to the same banner.
 */

/**
 * Long enough to read a sentence, short enough not to need dismissing.
 *
 * Not one number, because the four kinds are not equally worth reading. A save
 * that worked is finished news the moment it is seen; a failure is the only
 * record anyone has of what went wrong and often carries the reason in the same
 * breath, and two seconds is not long enough to read a sentence and act on it.
 */
const DURATIONS = {
    success: 3000,
    info: 3000,
    warning: 6000,
    error: 6000,
};

/**
 * A cap, because a bulk action can raise one message per row and a stack tall
 * enough to reach the top of the viewport hides the thing the reader just did.
 * The oldest goes first, because it is the one they have already read.
 */
const TOAST_LIMIT = 5;

const ICONS = {
    success: "✓",
    error: "✕",
    warning: "!",
    info: "i",
};

const TYPES = Object.keys(ICONS);

/**
 * Shared by every dialog.
 *
 * `scrollbarPadding: false` because the body lock already compensates for the
 * scrollbar; letting both write `padding-right` leaves the page off-centre by
 * one scrollbar width as soon as the two disagree.
 *
 * `buttonsStyling` is left on and the per-call button colours are deliberately
 * never set. With them unset, SweetAlert2 hands the colour decisions to the
 * `--swal2-*` custom properties in our stylesheet, and the two places the
 * library derives a colour from the computed button style -- the focus ring and
 * the tick inside the success icon -- follow the theme instead of drifting off
 * it.
 */
const DIALOG_BASE = {
    scrollbarPadding: false,
    returnFocus: true,
    /**
     * Keystrokes inside a dialog stop here.
     *
     * SweetAlert2 listens on the popup, but a `keydown` there goes on to bubble up
     * to `window` unless something stops it -- and the register page has window
     * shortcuts on it. Escape being the sharpest example: the cashier opens the
     * product picker, decides against it, and presses Escape, which closes the
     * picker *and* empties the cart underneath it. Two keys, one keystroke, and
     * the sale is gone with no confirmation and nothing to undo it from.
     *
     * It is set on every dialog rather than only the POS one because the rule is
     * the same everywhere: while something is open on top, the page underneath has
     * no business hearing about the keyboard.
     */
    stopKeydownPropagation: true,
};

/**
 * Put the message helper where the layout can see it, and hand back the one
 * object anything else is allowed to touch.
 *
 * This is a function rather than three lines in the entry point because the
 * line that was wrong is invisible from the outside. Alpine's `store()` keeps
 * the object it is given in raw form and only wraps it in a reactive proxy when
 * it is *read*, so a write to the raw object never reaches the template. A flash
 * raised on the raw object still painted, because the layout's first render
 * simply read the array it found -- and then the clock that removes it fired
 * three seconds later on the same blind object, produced no update, and the row
 * stayed until a reload forced one. Every create, update and delete goes through
 * that path, so every one of them left a toast behind.
 *
 * @param {object} Alpine the Alpine instance
 * @param {object} notify what {@link createNotify} returned
 * @returns {object} the reactive store, not the object that was registered
 */
export function registerToast(Alpine, notify) {
    Alpine.store("toast", notify);

    // Read back out on purpose. This is the proxy, and it is the only handle
    // that is wired to the layout.
    const toast = Alpine.store("toast");

    // Also on the window, so a template can say `notify.confirm(...)` in an
    // Alpine expression. A store would have worked too, but a second name for
    // the same object reads as a second thing -- so it is the *same* object,
    // proxy and all, rather than a second handle onto the raw one.
    window.notify = toast;

    return toast;
}

export function createNotify(options = {}) {
    const initTree =
        options.initTree ?? ((element) => window.Alpine.initTree(element));
    const destroyTree =
        options.destroyTree ??
        ((element) => window.Alpine.destroyTree(element));
    const fire = options.fire ?? ((params) => Swal.fire(params));
    const doc = options.document ?? document;

    /**
     * Monotonic, so two messages raised in the same millisecond still get
     * distinct keys. A toast keyed by its own text would collide and Alpine
     * would reuse the row instead of adding one.
     */
    let sequence = 0;

    /**
     * One clock per live toast, held in a closure rather than on the returned
     * object, so the bookkeeping is not something a page can render and not
     * something a page can corrupt.
     *
     * `remaining` is what makes pausing possible. A timer cannot be paused, so
     * it is cancelled and the time left on the clock is measured off `dismissAt`
     * and handed to the next one. Resuming with a fresh full duration instead
     * would mean a pointer travelling across a stack holds every toast it
     * touches open for its full life, which is the opposite of the point.
     */
    const clocks = new Map();

    /** Stop a toast's clock. Shared by every path that takes a toast off screen. */
    const forget = (id) => {
        const clock = clocks.get(id);

        if (clock) {
            clearTimeout(clock.timer);
            clocks.delete(id);
        }
    };

    /**
     * Run a toast's clock down, and take it away when it reaches zero.
     *
     * `host` is the caller's `this`, which is the reactive store whenever the
     * call came through one. Routing the removal back through it -- rather than
     * closing over a plain object -- is what lets the layout hear about it.
     */
    const arm = (host, id, ms) => {
        forget(id);

        const clock = {
            dismissAt: Date.now() + ms,
            remaining: ms,
            timer: setTimeout(() => {
                clocks.delete(id);
                host.dismiss(id);
            }, ms),
        };

        clocks.set(id, clock);
    };

    return {
        /** Read by the layout, which renders the stack. */
        items: [],

        /**
         * Raise a message. This is the shape the pages already call.
         *
         * @param {string} message
         * @param {string} [type] one of success, error, warning, info
         * @returns {number|null} the id, or null when there was nothing to say
         */
        push(message, type = "success") {
            const text =
                message === null || message === undefined
                    ? ""
                    : String(message);

            if (text.trim() === "") {
                return null;
            }

            const kind = TYPES.includes(type) ? type : "info";
            const id = (sequence += 1);
            const next = [
                ...this.items,
                { id, message: text, type: kind, icon: ICONS[kind] },
            ];

            // Whatever the limit pushes out is already gone from the screen, so
            // its clock is stopped here rather than left running down to an id
            // nothing is watching for. `slice(0, -TOAST_LIMIT)` is exactly the
            // set `slice(-TOAST_LIMIT)` below discards, and it is empty while
            // the stack is under the limit.
            for (const dropped of next.slice(0, -TOAST_LIMIT)) {
                forget(dropped.id);
            }

            this.items = next.slice(-TOAST_LIMIT);

            arm(this, id, DURATIONS[kind]);

            return id;
        },

        success(message) {
            return this.push(message, "success");
        },

        error(message) {
            return this.push(message, "error");
        },

        warning(message) {
            return this.push(message, "warning");
        },

        info(message) {
            return this.push(message, "info");
        },

        /**
         * Take one toast off the screen, and stop its clock.
         *
         * The clock is cleared on every route out, not only on this one, so a
         * toast dismissed by hand leaves nothing behind to fire a second
         * removal against an id that is already gone.
         *
         * @param {number} id
         */
        dismiss(id) {
            forget(id);
            this.items = this.items.filter((item) => item.id !== id);
        },

        /** Take the whole stack down, clocks and all. */
        dismissAll() {
            for (const item of this.items) {
                forget(item.id);
            }

            this.items = [];
        },

        /**
         * Hold a toast still while it is being read.
         *
         * A no-op for a toast whose clock has already run out, which is what
         * happens when the pointer arrives in the same tick the timer fires: the
         * row is already leaving, and pausing it would strand it on screen with
         * nothing left to take it away.
         *
         * @param {number} id
         */
        pause(id) {
            const clock = clocks.get(id);

            if (!clock) {
                return;
            }

            clearTimeout(clock.timer);
            clock.remaining = Math.max(0, clock.dismissAt - Date.now());
            clock.timer = null;
        },

        /**
         * Let a paused toast go again, with the time it had left rather than a
         * fresh full duration.
         *
         * @param {number} id
         */
        resume(id) {
            const clock = clocks.get(id);

            if (!clock || clock.timer !== null) {
                return;
            }

            arm(this, id, clock.remaining);
        },

        /**
         * Take what a controller left in the session and raise it.
         *
         * The session payload is whatever a redirect happened to put there, so
         * it is read defensively rather than trusted: a bare string, an object
         * with a message, a list of either, or something missing entirely. An
         * unrecognised type falls back to info instead of success, because
         * claiming a save worked is worse than saying something happened.
         *
         * @param {unknown} payload
         * @returns {number} how many messages were raised
         */
        ingest(payload) {
            const entries = Array.isArray(payload) ? payload : [payload];
            let raised = 0;

            for (const entry of entries) {
                if (typeof entry === "string") {
                    raised += this.push(entry, "info") === null ? 0 : 1;
                } else if (entry && typeof entry === "object") {
                    const message = entry.message ?? entry.text ?? "";
                    const type = TYPES.includes(entry.type)
                        ? entry.type
                        : "info";

                    raised += this.push(message, type) === null ? 0 : 1;
                }
            }

            return raised;
        },

        /**
         * Raise what a redirect left behind, then take the node away.
         *
         * The layout writes the session payload into a JSON script tag rather
         * than into an attribute or an inline call, so nothing here has to be
         * escaped a second time and the payload cannot execute on the way in.
         * The node is removed because a back/forward cache restore would
         * otherwise replay the same message.
         *
         * @param {ParentNode} root
         * @returns {number} how many messages were raised
         */
        drainFlash(root = document) {
            const node = root.querySelector("#flash-toast");

            if (!node) {
                return 0;
            }

            let raised = 0;

            try {
                raised = this.ingest(JSON.parse(node.textContent));
            } catch {
                // A payload we cannot read is not worth breaking the page over.
                raised = 0;
            }

            node.remove();

            return raised;
        },

        /**
         * Ask a question and wait for the answer.
         *
         * @param {?function(): (Promise<*>|*)} [options.onConfirm] work to do
         *   once the answer is yes. A promise here holds the dialog open behind
         *   a spinner, which is what stops a reader pressing the button again
         *   while the first press is still being carried out. Returning `false`
         *   reports that the work did not happen and leaves the dialog up, so
         *   the reader can try again instead of being told it worked.
         * @returns {Promise<boolean>} true only when confirmed
         */
        async ask({
            title = "",
            description = "",
            confirmText = "Ya, lanjutkan",
            cancelText = "Batal",
            icon = undefined,
            danger = false,
            onConfirm = null,
        } = {}) {
            // The lock is taken on open and released on close, guarded by a flag
            // so a dialog that never finished opening cannot release a lock it
            // never took. Held open, this counter is shared with the drawer, so
            // a dialog closing over an open drawer leaves the page locked.
            let held = false;

            const result = await fire({
                ...DIALOG_BASE,
                title,
                icon,
                // `text`, not `html`: SweetAlert2 assigns it with textContent,
                // and these descriptions carry record names that are not ours to
                // escape by hand.
                text: description,
                showConfirmButton: true,
                // Stated rather than left to the library's default, because the
                // rest of this dialog is closed off on purpose and this is the
                // one way out that is left. A change in DIALOG_BASE should not
                // be able to turn a danger dialog into a trap.
                showCancelButton: true,
                confirmButtonText: confirmText,
                cancelButtonText: cancelText,
                customClass: danger
                    ? { confirmButton: "swal-confirm-danger" }
                    : undefined,
                // A destructive answer is the one that destroys something, so
                // focus lands on the way out rather than on the way through.
                focusCancel: danger,
                // Clicking the backdrop is the reflex people have for a modal,
                // and on a warning about losing input it is the reflex that
                // would lose it: the click dismisses, the answer reads as no,
                // and the row the reader meant to archive is still sitting there
                // looking unarchived. An ordinary question keeps it, because
                // there dismissing and answering no are the same thing anyway.
                allowOutsideClick: !danger,
                // Esc is the other reflex, and it reaches the same place: a
                // destructive question that a stray keypress can wave through
                // reads as no and the row is still sitting there looking
                // unarchived. Closing one is not possible by accident once the
                // backdrop is closed off, so a danger dialog keeps its two
                // buttons and its Escape -- that is the same answer, reached
                // deliberately.
                allowEscapeKey: !danger,
                // The X, likewise, only where dismissing and answering no are
                // the same thing. On a warning about a row about to be
                // archived it is one more place to lose the answer to.
                showCloseButton: !danger,
                // Only a dialog with work behind it gets a spinner. Wiring the
                // loader without a promise to wait on shows a busy button for a
                // frame on every question, including the ones that are only
                // asking.
                ...(onConfirm
                    ? {
                          showLoaderOnConfirm: true,
                          preConfirm: async () => {
                              const outcome = await onConfirm();

                              // SweetAlert2 reads anything but `false` as done.
                              return outcome !== false;
                          },
                      }
                    : {}),
                didOpen: () => {
                    held = true;
                    acquireBodyLock();
                },
                didClose: () => {
                    if (held) {
                        held = false;
                        releaseBodyLock();
                    }
                },
            });

            return result?.isConfirmed === true;
        },

        confirm(options = {}) {
            return this.ask(options);
        },

        dangerConfirm(options = {}) {
            return this.ask({ ...options, danger: true });
        },

        /**
         * A dialog with a body of the caller's own markup.
         *
         * SweetAlert2 injects its popup after Alpine has booted, so anything
         * built with directives arrives dead unless the subtree is walked on
         * open and torn down on close. Without the teardown, a dialog opened
         * and closed in a loop re-registers its listeners and its state each
         * time round.
         *
         * @param {object} params
         * @param {string} params.html server-rendered markup, trusted
         */
        async modal({
            title = "",
            description = "",
            html = "",
            size = "md",
            showConfirmButton = false,
            confirmText = "Simpan",
            cancelText = "Batal",
            onConfirm = null,
        } = {}) {
            let held = false;

            return fire({
                ...DIALOG_BASE,
                title,
                description,
                html,
                width: MODAL_WIDTHS[size] ?? MODAL_WIDTHS.md,
                showConfirmButton,
                showCancelButton: showConfirmButton,
                confirmButtonText: confirmText,
                cancelButtonText: cancelText,
                customClass: { popup: `swal-modal-${size}` },
                didOpen: (popup) => {
                    held = true;
                    acquireBodyLock();
                    initTree(popup);
                },
                didClose: (popup) => {
                    if (held) {
                        held = false;
                        releaseBodyLock();
                    }

                    destroyTree(popup);
                },
                preConfirm: onConfirm ?? undefined,
            });
        },

        /**
         * A dialog whose body is a template sitting on the page.
         *
         * Leaves the markup where Blade can resolve it -- components, CSRF,
         * translations -- instead of inlining a string inside a click handler,
         * and keeps the trigger down to one expression without needing the
         * button and the template to share an Alpine scope. They are usually
         * hundreds of lines and several components apart.
         *
         * @param {string} templateId id of the `<template>` to lift the body from
         * @param {object} options as `modal`, minus `html`
         */
        async templateModal(templateId, options = {}) {
            const template = doc.getElementById(templateId);

            if (!template) {
                // An empty dialog reads as a broken page. Say which one is
                // missing instead, and open nothing.
                console.warn(
                    `notify.templateModal: no #${templateId} on the page`,
                );

                return null;
            }

            return this.modal({ ...options, html: template.innerHTML });
        },

        /** Close whatever dialog is open, for callers that give up on an answer. */
        close() {
            Swal.close();
        },
    };
}

/**
 * Carried over from the `x-ui.modal` sizes it replaces, so the two dialogs that
 * used it keep their footprint.
 */
const MODAL_WIDTHS = {
    sm: "28rem",
    md: "32rem",
    lg: "42rem",
    xl: "56rem",
    full: "72rem",
};
