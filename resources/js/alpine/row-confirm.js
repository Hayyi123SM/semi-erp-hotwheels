/**
 * A confirmation for a row action, asked through the shared dialog helper.
 *
 * Row actions dispatch a `row-confirm` event and this one scope answers it. The
 * form stays shared for the same reason it was shared before: a ten-row table
 * renders its rows twice, for the table and the mobile card list, and giving
 * each confirmable action its own form would put twenty of them on the page.
 *
 * The dialog itself is not here any more. It used to be a Blade component with
 * a hand-written panel, an overlay and a focus rule, and it is now one call to
 * the shared helper, which is the same helper the unsaved-changes guard uses.
 *
 * The listener is on the window and the latch below is per scope, so a page
 * carrying two tables would have two scopes each submitting the one event, and
 * the latch would not stop either. Every page here mounts exactly one table; a
 * page that ever needs a second one has to scope the event to the table it came
 * from, or the shared form has to become a page-level thing.
 */
export function rowConfirm(options = {}) {
    const notify = options.notify ?? window.notify;

    return {
        /**
         * Latched the moment a submit is handed off, and never released.
         *
         * Its lifetime is one page load, which is exactly the window in which a
         * duplicate can be sent: the row button stays clickable and looks
         * identical while the browser is still working out where to navigate,
         * and a reader who has been given no feedback presses again. Two DELETEs
         * for one row is not something the server can undo.
         *
         * A submit that comes back as a validation error is a redirect, so it
         * arrives as a new page with a new scope and a clear latch -- there is
         * nothing to release.
         */
        submitted: false,

        init() {
            window.addEventListener('row-confirm', (event) => {
                this.ask(event.detail ?? {});
            });
        },

        /**
         * Ask, then submit the shared form if the answer was yes.
         *
         * The form is a real one with a CSRF token Blade rendered, rather than
         * something assembled here, so the request is the same request the row
         * would have made without a confirmation step at all.
         *
         * The submit happens inside the dialog's `onConfirm` rather than after
         * it, so the button the reader pressed is the one still spinning while
         * the row goes out. Answering first and submitting afterwards left a
         * gap where the dialog was gone and nothing had happened yet, and a
         * gap like that is exactly where a second press gets in.
         *
         * @param {object} detail
         */
        async ask(detail) {
            if (this.submitted) {
                return;
            }

            const method = (detail.method ?? 'POST').toUpperCase();

            await notify.dangerConfirm({
                title: detail.title ?? 'Konfirmasi',
                description: detail.description ?? '',
                confirmText: detail.confirmText ?? 'Ya, lanjutkan',
                onConfirm: () => {
                    // A second question raised before the first one reached the
                    // form gets here too. It holds the dialog open rather than
                    // reporting a success that never happened.
                    if (this.submitted) {
                        return false;
                    }

                    this.submitted = true;

                    this.$refs.form.action = detail.action;
                    this.$refs.method.value = ['GET', 'POST'].includes(method) ? '' : method;
                    this.$refs.form.submit();
                },
            });
        },
    };
}
