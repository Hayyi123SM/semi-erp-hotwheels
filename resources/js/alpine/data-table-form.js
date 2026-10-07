/**
 * How a list's query gets submitted.
 *
 * Two forms carry one query: the toolbar, which holds the search box and every
 * filter, and the page-size form down by the pagination. Each already knows how
 * to submit itself as a plain GET form, so this component deliberately does not
 * build the URL, gather the fields or navigate. It only decides *when* a form
 * has become worth submitting, which is the one judgement the markup cannot
 * make for itself.
 *
 * The listeners live on the form rather than on each control, for two reasons.
 * A form submits whatever it contains, so a page can add a filter without
 * teaching this component about it; and a control has no business knowing which
 * form it belongs to. The second row is also re-rendered on every refresh, and
 * it reaches the browser with no form around it at all -- markup there that
 * walked up the tree looking for one would throw the first time a reader
 * touched a filter.
 */
const TYPED_LATENCY_MS = 400;

/** The search field, which submits itself on typing rather than on change. */
const SEARCH_FIELD = 'q';

/**
 * A term complete enough to be a lookup rather than a prefix of one.
 *
 * An operator pasting or typing out a full SKU wants its lot, not the pages of
 * partial matches they would otherwise wait through, so a complete code skips
 * the wait. The shape is the one SRS Lampiran A gives: two letters, two digits,
 * a category and a sequence -- `CN01-HW-001`, `OW00-HW-001`. The first group
 * takes digits too, or no real code would ever reach this.
 *
 * The trailing group needs its full three digits, so the code still waits while
 * it is being typed.
 */
const COMPLETE_CODE = /^[A-Za-z0-9]{2,4}-[A-Za-z]{1,3}-\d{3,}$/;

export function dataTableForm(options = {}) {
    // Injected so the timing can be tested without a real form.
    const submitForm = options.submit ?? null;

    return {
        timer: null,

        /** The reader changed a control: the query they asked for has changed. */
        apply(event) {
            // The search field submits itself on typing, on a delay that has
            // almost certainly already elapsed by the time a reader looks away
            // from it. Answering its blur as well would ask the server the same
            // question twice for the one word they typed.
            if (event?.target?.name === SEARCH_FIELD) {
                return;
            }

            this.submit();
        },

        /**
         * The reader is typing a search term.
         *
         * Debounced rather than submitted per keystroke, because each submit
         * asks the server for the rows again, and a reader halfway through a
         * word does not want the answer to it.
         */
        search(value) {
            clearTimeout(this.timer);

            const complete = COMPLETE_CODE.test(String(value ?? '').trim());

            this.timer = setTimeout(() => this.submit(), complete ? 0 : TYPED_LATENCY_MS);
        },

        submit() {
            if (submitForm !== null) {
                submitForm();
                return;
            }

            // `$root` is the element carrying x-data, which is the form these
            // listeners are attached to.
            this.$root.requestSubmit();
        },

        destroy() {
            // A pending timer outliving the component would submit a form that
            // is no longer the one on screen.
            clearTimeout(this.timer);
        },
    };
}