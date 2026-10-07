/**
 * Warns before a page is abandoned with form input still in it.
 *
 * The two exits need different machinery, and the difference is not a matter of
 * taste. A click on a link inside the app is ours to intercept, so it gets the
 * app's own dialog and a "stay" that simply cancels the click. A real unload —
 * a closed tab, a reload, a back button taken away from us — can only raise the
 * browser's own prompt, because `beforeunload` is the one event on which a page
 * is not allowed to draw anything of its own.
 *
 * Forms opt in through `data-guard` rather than the guard taking hold of every
 * form on the page. The list pages' search box is a form too, and a reader
 * halfway through typing a search has to be free to click away from it.
 *
 * The dialog is the shared one, the same helper the row actions ask through, so
 * there is no second opinion on what a warning looks like and no dialog markup
 * in the layout to fall out of step with this file.
 */

const GUARDED = 'form[data-guard]';

/** Matches the warning the reader is shown, word for word. */
const WARNING = {
    title: 'Perubahan belum disimpan',
    description: 'Isi form ini akan hilang. Kalau belum selesai, tinggalkan dulu halaman ini.',
    confirmText: 'Ya, tinggalkan',
    cancelText: 'Tetap di sini',
};

function isGuarded(form) {
    return form instanceof HTMLFormElement && form.matches(GUARDED);
}

function guardedForms() {
    return Array.from(document.querySelectorAll(GUARDED));
}

function trackedFields(form) {
    return Array.from(form.querySelectorAll('input, select, textarea')).filter(
        (field) => field.name && !field.disabled && !field.hasAttribute('data-guard-ignore')
    );
}

/**
 * What a field is worth right now.
 *
 * A file input clears its own `value` as soon as the picker closes, so a pending
 * upload can only be read off the FileList. A multi-select reports just its
 * first selection through `value`, which would hide a change to any later one.
 */
function readField(field) {
    const type = (field.type || '').toLowerCase();

    if (type === 'file') {
        return Array.from(field.files ?? []).map((file) => `${file.name}:${file.size}`).join('|');
    }

    if (type === 'checkbox' || type === 'radio') {
        return field.checked ? '1' : '0';
    }

    if (field.multiple) {
        return Array.from(field.selectedOptions ?? []).map((option) => option.value).join('|');
    }

    return field.value;
}

export function unsavedChanges({ location = window.location, notify = window.notify } = {}) {
    return {
        /** The destination the reader was trying to reach, or null while undecided. */
        pendingHref: null,

        // Baselines and the submitting set live outside Alpine's reactivity on
        // purpose: they are a record of what the server sent, keyed by element,
        // and neither is something a template has any reason to watch.
        baselines: new Map(),
        submitting: new Set(),

        /**
         * A one-shot allowance for a navigation this guard authorised itself.
         * Without it, the unload caused by the "leave" button would raise the
         * very prompt the reader had just agreed to.
         */
        bypassed: false,

        teardown: [],

        init() {
            const listen = (target, type, handler, options) => {
                // Wrapped rather than passed by reference: an un-wrapped method
                // would arrive with the event target as its `this`, and every
                // line in it reaches for the component.
                const wrapped = (event) => handler.call(this, event);

                target.addEventListener(type, wrapped, options);
                this.teardown.push(() => target.removeEventListener(type, wrapped, options));
            };

            listen(window, 'beforeunload', this.onBeforeUnload);
            listen(window, 'pageshow', this.onPageShow);
            listen(document, 'click', this.onClick, true);
            listen(document, 'submit', this.onSubmit, true);
            listen(document, 'focusin', this.onFocusIn, true);
            listen(document, 'input', this.onEdited, true);
            listen(document, 'change', this.onEdited, true);
        },

        destroy() {
            this.teardown.forEach((off) => off());
            this.teardown = [];
        },

        formOf(target) {
            if (!(target instanceof Element)) {
                return null;
            }

            // A form is not inside itself, so `closest()` alone would miss the
            // very element a `submit` event is reported on.
            const form = target instanceof HTMLFormElement ? target : target.closest('form');

            return form && isGuarded(form) ? form : null;
        },

        /**
         * Records what the server sent, once, and never moves it again.
         *
         * Taken on focus rather than on init because a framework binding is
         * entitled to write a value while the page is still settling — a select
         * driven by `x-model` does exactly that — and a baseline captured too
         * early would report a form the reader never touched as edited.
         */
        arm(form) {
            if (this.baselines.has(form)) {
                return;
            }

            const baseline = new Map();

            for (const field of trackedFields(form)) {
                baseline.set(field, readField(field));
            }

            this.baselines.set(form, baseline);
        },

        isDirty(form) {
            const baseline = this.baselines.get(form);

            if (!baseline) {
                return false;
            }

            const fields = trackedFields(form);

            // A field the baseline never saw is one the reader added, and its
            // value would be lost just as surely as an edit to an existing one.
            if (fields.length !== baseline.size) {
                return true;
            }

            return fields.some((field) => readField(field) !== baseline.get(field));
        },

        /** A form that is on its way to the server is never in a state to lose. */
        hasUnsavedChanges() {
            return guardedForms().some((form) => !this.submitting.has(form) && this.isDirty(form));
        },

        onFocusIn(event) {
            const form = this.formOf(event.target);

            if (form) {
                this.arm(form);
            }
        },

        /**
         * An edit the reader made without focusing anything first — an autofill,
         * or a browser restoring what it remembered — still counts, so the
         * baseline is taken here too.
         */
        onEdited(event) {
            const form = this.formOf(event.target);

            if (form) {
                this.arm(form);
            }
        },

        onSubmit(event) {
            const form = this.formOf(event.target);

            if (!form) {
                return;
            }

            // Flagged on `submit` rather than on the button's click, so a form
            // turned away by HTML5 validation never sets the flag. Saving is the
            // one exit that is always deliberate, and a prompt in the middle of
            // the reader's own POST is the worst possible moment to raise one.
            this.submitting.add(form);
            this.baselines.delete(form);
        },

        onClick(event) {
            // A fresh decision from the reader supersedes the allowance the last
            // one earned, and this is the only place a new one can begin.
            this.bypassed = false;

            if (event.defaultPrevented || event.button !== 0) {
                return;
            }

            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            const link = event.target instanceof Element ? event.target.closest('a[href]') : null;

            if (!link) {
                return;
            }

            // These either never leave the page or the reader has asked the
            // browser to handle them itself.
            if (link.hasAttribute('download') || link.getAttribute('target')) {
                return;
            }

            const href = link.getAttribute('href');

            if (!href || href.startsWith('#')) {
                return;
            }

            const url = new URL(link.href, location.href);

            if (url.origin !== location.origin) {
                return;
            }

            if (!this.hasUnsavedChanges()) {
                return;
            }

            event.preventDefault();
            this.pendingHref = url.href;
            this.askLeave(url.href);
        },

        /**
         * Ask, and go only if the answer was yes.
         *
         * Split out from the click handler so the decision is made before the
         * handler returns: the link is cancelled synchronously above, and only
         * the question is left to wait on. Were the two tangled together the
         * browser would follow the link while the dialog was still opening.
         *
         * @param {string} href where the reader was headed
         */
        async askLeave(href) {
            const leaving = await notify.dangerConfirm(WARNING);

            this.pendingHref = null;

            if (!leaving) {
                return;
            }

            // Set before navigating, so the unload this causes is the one prompt
            // the reader has already answered rather than a second one.
            this.bypassed = true;
            location.href = href;
        },

        onBeforeUnload(event) {
            if (this.bypassed || !this.hasUnsavedChanges()) {
                return;
            }

            event.preventDefault();
            // Still the switch in the engines that predate preventDefault alone.
            event.returnValue = '';
        },

        onPageShow() {
            // A page restored from the back/forward cache comes back with its
            // form and its baseline intact, but the allowance that let the
            // reader leave it is spent.
            this.bypassed = false;
        },
    };
}
