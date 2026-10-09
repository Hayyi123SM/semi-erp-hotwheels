/**
 * The one way to ask for an Owner PIN, shared by every action that carries an
 * Owner's name: overwriting a consignor's commission terms, printing more labels
 * than a lot holds, voiding a paid sale, approving a settlement.
 *
 * What the dialog gets back is a token, not the PIN. The PIN goes to the server
 * once, here, and what travels on the form from then on is a token that is
 * opaque, scoped to one action, bound to the cashier who asked for it, and good
 * for five minutes. A PIN typed into every form would be readable in an access
 * log, replayable out of a cached page, and would never expire on its own.
 *
 * The dialog is the shared one rather than a Blade component with its own panel
 * and focus rule, for the same reason the row confirmation and the unsaved
 * changes warning are: there is one overlay, one body lock, one focus return,
 * and one place to fix them.
 *
 * `pinDialogForm` is the scope inside the dialog body. The markup lives in a
 * `<template>` in the layout, so Blade resolves the components and the CSRF
 * token, and Alpine sees the directives once `notify.modal` walks the subtree on
 * open -- and tears it down on close, so opening and closing in a loop does not
 * stack listeners.
 */

import Swal from 'sweetalert2';

const TEMPLATE_ID = 'pin-dialog';
const VERIFY_URL = '/pin/verify';
const PIN_LENGTH = 6;

/**
 * The action a token is scoped to when the caller did not name one.
 *
 * Mirrors `VerifyPinRequest::DEFAULT_CONTEXT` on the server. Both sides default,
 * because a token scoped to a caller-chosen action and a token scoped to
 * `undefined` are two different scopes and only one of them can be checked: the
 * server would have been handed the literal string "undefined" and would have
 * bound the grant to it happily, and the form would then present a context no
 * request rule can ever match.
 */
const DEFAULT_CONTEXT = 'global';

/** A PIN is digits and is not a number, so the dash is stripped, not the point. */
const DIGITS_ONLY = /\D/g;
const WELL_FORMED = new RegExp(`^\\d{${PIN_LENGTH}}$`);

/**
 * The dialog body: the PIN field, and the reason the last attempt was refused.
 *
 * Holds nothing the server has not already said. The failure text is written
 * from the response rather than decided here, so a PIN refused because no Owner
 * has set one reads differently from one that is simply wrong -- the cashier
 * needs to know which, because only one of them is worth trying again.
 */
export function pinDialogForm() {
    return {
        pin: '',
        error: '',

        get isComplete() {
            return WELL_FORMED.test(this.pin);
        },

        init() {
            this.$nextTick(() => this.$refs.input?.focus());
        },

        onInput(event) {
            const cleaned = event.target.value.replace(DIGITS_ONLY, '');

            // Corrected in the DOM and not only in state, because the browser's
            // own value is what the next keystroke appends to.
            if (cleaned !== event.target.value) {
                event.target.value = cleaned;
            }

            this.pin = cleaned;

            // A reason on screen for a field that has since changed is a reason
            // for a field nobody made any more.
            if (this.error !== '') {
                this.error = '';
            }
        },

        /** Called once a token has been issued, so no PIN is left on screen. */
        reset() {
            this.pin = '';
            this.error = '';
        },
    };
}

/**
 * @param {object} [options]
 * @param {object} [options.notify] the shared dialog helper
 * @param {object} [options.Alpine]   for reading the body scope off the popup
 * @param {string} [options.url]      override for the verify endpoint
 * @param {Function} [options.getPopup]
 *        returns the current SweetAlert2 popup, for reading the form scope off
 *        it; defaults to `Swal.getPopup()`
 */
export function pinDialog(options = {}) {
    return new PinDialog(
        options.notify ?? window.notify,
        options.Alpine ?? window.Alpine,
        options.url ?? VERIFY_URL,
        options.getPopup ?? (() => Swal.getPopup()),
    );
}

/**
 * A class rather than an object literal, for the private methods: the exchange
 * below is not part of what a caller may do, and a method with a `#` is the one
 * way to say that in an object whose keys are read off the markup.
 */
class PinDialog {
    #notify;
    #Alpine;
    #url;
    #getPopup;

    constructor(notify, Alpine, url, getPopup) {
        this.#notify = notify;
        this.#Alpine = Alpine;
        this.#url = url;
        this.#getPopup = getPopup;
    }

    /**
     * Ask for a PIN, and return a grant the caller may act on.
     *
     * @param {object} params
     * @param {string} params.context     the action this token is bound to
     * @param {string} [params.title]
     * @param {string} [params.description] shown under the title
     * @param {string} [params.confirmText]
     * @returns {Promise<{token: string, expiresAt: string, owner: string, context: string}|null>}
     *          null when the cashier cancelled, which is not an error
     */
    async request({ context, title, description, confirmText } = {}) {
        if (this.#notify === undefined) {
            // Nothing this could do would be worth opening a dialog for.
            console.error('pinDialog: the shared dialog helper is not on the page');

            return null;
        }

        const action = this.#scopedTo(context);
        const dialog = this;
        const result = await this.#notify.templateModal(TEMPLATE_ID, {
            title: title ?? 'Verifikasi PIN Owner',
            description: description ?? 'Minta PIN Owner untuk melanjutkan aksi ini.',
            size: 'sm',
            showConfirmButton: true,
            confirmText: confirmText ?? 'Konfirmasi',
            cancelText: 'Batal',
            // SweetAlert2 invokes `preConfirm` unbound, so `this` inside a
            // method here is never this instance. The current popup is fetched
            // through an injected `getPopup` instead of assuming a receiver.
            onConfirm() {
                return dialog.exchange(action, dialog.#getPopup());
            },
        });

        // Dismissal resolves to something that is not a grant. Reading the token
        // off the result rather than trusting a truthy check means a dialog that
        // closed without ever running `preConfirm` cannot be mistaken for one
        // that was authorised.
        return typeof result?.token === 'string' ? result : null;
    }

    /**
     * Decide which action this grant is being asked for.
     *
     * A grant is only as good as the scope it was issued under, so a caller that
     * forgets to name an action gets `global` -- the same scope the server falls
     * back to -- rather than a scope no request rule would ever check. Anything
     * that is not a usable string is a caller's bug, and is reported as one
     * instead of being sent to the server as `undefined`.
     *
     * @returns {string}
     */
    #scopedTo(context) {
        if (typeof context === 'string' && context.trim() !== '') {
            return context;
        }

        if (context !== undefined) {
            console.error('pinDialog: context must be a non-empty string', context);
        }

        return DEFAULT_CONTEXT;
    }

    /**
     * Trade the typed PIN for a token, or report why it was refused.
     *
     * Returns `false` to leave the dialog open, which is the shared helper's
     * established contract for "the work did not happen" -- so a wrong PIN gets
     * a second attempt in the same dialog instead of closing and making the
     * cashier start over.
     *
     * @returns {Promise<object|false>}
     */
    async exchange(context, popup) {
        const form = this.#form(popup);

        if (form === null) {
            // The layout lost its template. Saying "wrong PIN" here would send
            // the cashier to type a different one forever.
            console.warn(`pinDialog: no [data-pin-form] inside #${TEMPLATE_ID}`);

            return false;
        }

        form.error = '';

        if (!WELL_FORMED.test(form.pin)) {
            form.error = `PIN Owner harus ${PIN_LENGTH} digit angka.`;

            return false;
        }

        const body = new URLSearchParams();

        body.append('pin', form.pin);
        body.append('context', context);

        let response;

        try {
            response = await window.fetch(this.#url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body,
            });
        } catch (error) {
            // A dead network and a wrong PIN send the cashier to two different
            // places to fix something, so the message has to say which.
            form.error = 'Tidak bisa menghubungi server. Periksa koneksi lalu ulangi.';

            return false;
        }

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            form.error = reasonFor(data, response);

            return false;
        }

        form.reset();

        return {
            token: data.token,
            expiresAt: data.expires_at,
            owner: data.owner,
            context: data.context ?? context,
        };
    }

    #form(popup) {
        const el = popup?.querySelector('[data-pin-form]');

        if (el === null || el === undefined) {
            return null;
        }

        return this.#Alpine?.$data(el) ?? null;
    }
}

/**
 * What the server said, in the order that is useful to the reader.
 *
 * A validation error is already a sentence written for a human, so it is passed
 * through as it is rather than replaced with something generic that would throw
 * away the one detail that distinguishes "no Owner has set a PIN" from "that PIN
 * is wrong".
 */
function reasonFor(data, response) {
    if (response.status === 422 && data?.errors !== undefined) {
        const reasons = Object.values(data.errors).flat().filter(Boolean);

        if (reasons.length > 0) {
            return reasons.join(' ');
        }
    }

    if (response.status === 429) {
        return 'Terlalu banyak percobaan. Tunggu satu menit lalu minta PIN Owner lagi.';
    }

    return data?.message ?? 'PIN Owner ditolak.';
}
