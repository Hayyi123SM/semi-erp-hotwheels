import { tableView } from './table-view';

/**
 * Refreshing a data table without refreshing the page.
 *
 * Search, sort, filter and paging all end up in a query string the server
 * already knows how to answer, and the answer is HTML. So this does not build a
 * data layer, does not render rows and does not mirror the query: it asks the
 * same URL for the two regions the table owns, and puts the answer where the
 * old one was. Everything the reader has already got — the layout, the sidebar,
 * the search field they are typing into, an open confirm dialog — is left alone.
 *
 * The GET form stays in the markup and stays the fallback. Nothing here is
 * required for the table to work; with this script the navigation is quieter,
 * without it the page still navigates.
 */
const FRAGMENT_HEADER = 'X-Table-Fragment';

/** A key the reader cannot see, so its value never belongs in a comparison. */
const SEARCH_KEY = 'q';

/** How a checkbox filter reads "off" in a query string. */
const FALSY = ['0', 'false', ''];

export function tableAsync(options = {}) {
    const fetchImpl = options.fetchImpl ?? ((...args) => window.fetch(...args));
    const historyApi = options.history ?? window.history;
    const locationApi = options.location ?? window.location;
    const initTree = options.initTree ?? ((element) => window.Alpine.initTree(element));

    /**
     * A real page load, for the one click that leaves this list.
     *
     * Rows point at other pages, so their URL has to go through the browser
     * rather than through `navigate()`: `navigate()` fetches a table fragment
     * and swaps two regions, and asking it for a detail page would swap this
     * list's rows with a document it does not understand.
     */
    const assign = options.assign ?? ((href) => window.location.assign(href));

    return {
        busy: false,
        error: '',

        /** The URL the reader last asked for, which is what a retry repeats. */
        lastUrl: '',

        /** The in-flight request, so the next navigation can call it off. */
        controller: null,

        /**
         * Counts navigations rather than comparing aborts.
         *
         * Aborting is a promise the browser may keep or ignore, so a response
         * can still arrive after the reader has already moved on. The ticket
         * tells us whether this is still the newest answer, and is the only
         * thing standing between a fast typist and last year's results.
         */
        ticket: 0,

        onPopState: null,

        init() {
            this.onPopState = () => this.load(locationApi.href);
            window.addEventListener('popstate', this.onPopState);
        },

        destroy() {
            window.removeEventListener('popstate', this.onPopState);
            this.controller?.abort();
        },

        retry() {
            if (this.lastUrl !== '') {
                this.load(this.lastUrl);
            }
        },

        /**
         * Go to a URL inside this list.
         *
         * History is written before the request rather than after it, so the
         * address bar never claims something the table is not showing yet. A
         * request that then fails leaves the URL at the query the reader asked
         * for, with the error and a retry next to rows that are honestly the
         * old ones.
         */
        navigate(url, { replace = false, writeHistory = true } = {}) {
            const href = typeof url === 'string' ? url : url.href;

            this.lastUrl = href;

            if (writeHistory) {
                if (replace) {
                    historyApi.replaceState({}, '', href);
                } else {
                    historyApi.pushState({}, '', href);
                }
            }

            this.load(href);
        },

        async load(href) {
            this.controller?.abort();

            const controller = new AbortController();
            const ticket = ++this.ticket;

            this.controller = controller;
            this.busy = true;
            this.error = '';

            try {
                const response = await fetchImpl(href, {
                    headers: {
                        Accept: 'text/html',
                        'X-Requested-With': 'XMLHttpRequest',
                        [FRAGMENT_HEADER]: '1',
                    },
                    credentials: 'same-origin',
                    redirect: 'follow',
                    signal: controller.signal,
                });

                if (!response.ok) {
                    throw new Error(`Table fragment responded ${response.status}`);
                }

                const html = await response.text();

                if (ticket !== this.ticket) {
                    return;
                }

                this.swap(html);
                this.syncForm(href);
            } catch (exception) {
                // An abort is a navigation we asked for, not a failure to report.
                if (controller.signal.aborted || ticket !== this.ticket) {
                    return;
                }

                this.error = 'Gagal memuat data.';
            } finally {
                if (ticket === this.ticket) {
                    this.busy = false;
                    this.controller = null;
                }
            }
        },

        /**
         * Put the two marked regions of a fragment where the old ones were.
         *
         * Parsed out of the whole response rather than sent bare, because the
         * page around the table still renders: this component cannot render the
         * page, only the part of it that is a table.
         */
        swap(html) {
            const parsed = new DOMParser().parseFromString(html, 'text/html');
            const results = parsed.querySelector('[data-fragment="results"]');

            if (results === null) {
                return;
            }

            const strip = parsed.querySelector('[data-fragment="strip"]');
            const stripSlot = this.$root.querySelector('[data-strip-slot]');

            if (strip !== null && stripSlot !== null) {
                stripSlot.innerHTML = strip.innerHTML;

                // Lifting the last filter off a table with nothing else to put
                // in that row has to take the row away, not leave the tint.
                stripSlot.hidden = strip.dataset.present !== '1';
            }

            const resultsSlot = this.$root.querySelector('[data-results]');

            if (resultsSlot === null) {
                return;
            }

            resultsSlot.innerHTML = results.innerHTML;

            // Fresh markup arrives with fresh Alpine directives, and Alpine only
            // walks the tree once on start. Without this the page-size select
            // and every row menu render perfectly and then do nothing at all.
            initTree(resultsSlot);

            if (stripSlot !== null) {
                initTree(stripSlot);
            }
        },

        /**
         * Pull the toolbar's own controls back in line with the URL.
         *
         * The rows are re-rendered from the response, but the search field and
         * the hidden sort and page-size inputs live in the row that is not
         * swapped, so they keep whatever they last held. A sort link that never
         * reaches the search field would leave the next search asking for the
         * previous sort.
         *
         * A control the reader is inside is left alone. Their keystrokes are
         * newer than the response that is arriving, and overwriting the field
         * mid-word is worse than a value a moment out of step.
         */
        syncForm(href) {
            const form = this.$root.querySelector('[data-table-form]');

            if (form === null) {
                return;
            }

            const params = new URL(href, locationApi.href).searchParams;

            for (const control of Array.from(form.elements)) {
                if (!control.name || control.disabled || this.isFocused(control)) {
                    continue;
                }

                if (control.type === 'checkbox' || control.type === 'radio') {
                    control.checked = params.has(control.name)
                        && !FALSY.includes(params.get(control.name));

                    continue;
                }

                const value = params.get(control.name) ?? '';

                // Only written when it differs: assigning an equal value still
                // moves the caret in some browsers, which is a small thing to
                // do to someone halfway through typing a search.
                if (control.value !== value) {
                    control.value = value;
                }
            }
        },

        isFocused(control) {
            return control === document.activeElement;
        },

        /**
         * The toolbar form, submitted by a control rather than by a button.
         *
         * Search submits itself on typing and the filters on change, all of
         * them through `requestSubmit()`, so the submit event is the one place
         * that hears about every list query — including the ones this component
         * has never heard of.
         */
        onSubmit(event) {
            const form = event.target;

            if (form === null || !form.matches('[data-table-form]')) {
                return;
            }

            event.preventDefault();

            const url = this.urlFor(form, this.queryOf(form));

            this.navigate(url, { replace: this.isSearchOnly(url) });
        },

        /**
         * Sort headers, filter chips, the page links, the reset button and the
         * rows themselves.
         *
         * Only same-path links are taken. The row actions and the create button
         * leave the list, and a link that opens elsewhere, downloads, or was
         * asked for with a modifier key belongs to the browser. A row carries no
         * href, so a modified click on it belongs to nothing and does nothing —
         * the reader who wants a background tab has the link inside the row,
         * which answers that question the way a link is supposed to.
         */
        onClick(event) {
            if (event.defaultPrevented || event.button !== 0) {
                return;
            }

            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            const link = event.target?.closest?.('a[href]');

            if (link === null || link === undefined) {
                this.openRow(event);

                return;
            }

            if (link.target === '_blank' || link.hasAttribute('download')) {
                return;
            }

            if (link.hasAttribute('data-full-navigation')) {
                return;
            }

            const url = new URL(link.href, locationApi.href);

            if (!this.isSameList(url)) {
                return;
            }

            event.preventDefault();

            this.navigate(url, { replace: this.isSearchOnly(url) || url.href === locationApi.href });
        },

        /**
         * Open the page behind a row the reader clicked anywhere on.
         *
         * Only reached when the click was not on a link, so a row's own actions
         * keep winning: the "Detail" link goes to the detail page it names, a
         * confirm button stays a button, and nothing that is meant to be typed
         * in or toggled navigates away underneath it. The row itself exists for
         * the reader who already knows where they are going and does not want
         * to aim at one link among seven columns.
         */
        openRow(event) {
            const row = event.target?.closest?.('[data-row-url]');

            if (row === null || row === undefined) {
                return;
            }

            const control = event.target?.closest?.('a, button, input, select, textarea, label, [role="button"]');

            if (control !== null && control !== undefined && row.contains(control)) {
                return;
            }

            event.preventDefault();

            assign(row.dataset.rowUrl);
        },

        urlFor(form, params) {
            const url = new URL(form.action || locationApi.href, locationApi.href);
            url.search = params.toString();

            return url;
        },

        /**
         * The controls of a GET form, as a query string.
         *
         * Built from the controls rather than from `form.action`, which is the
         * bare URL, because that is the whole point of the form: search and
         * filters are one query. An empty control is left out, which is what
         * makes the URL read like the links the server generates instead of
         * carrying a trail of `?q=&status=`. A checkbox counts only when it is
         * ticked, the same rule the browser applies.
         */
        queryOf(form) {
            const params = new URLSearchParams();

            for (const control of Array.from(form.elements)) {
                if (!control.name || control.disabled) {
                    continue;
                }

                if (control.type === 'submit' || control.type === 'button'
                    || control.type === 'file' || control.type === 'image') {
                    continue;
                }

                if ((control.type === 'checkbox' || control.type === 'radio') && !control.checked) {
                    continue;
                }

                if (control.value === '' || control.value === undefined || control.value === null) {
                    continue;
                }

                params.append(control.name, control.value);
            }

            return params;
        },

        isSameList(url) {
            return url.origin === locationApi.origin && url.pathname === locationApi.pathname;
        },

        /**
         * Whether a URL is the current one with the search term changed.
         *
         * This is what keeps the back button usable. A search is one thought the
         * reader is having, not eight places they have been, so it replaces the
         * entry it came from. Everything else — a filter, a sort, a page — is a
         * place worth going back to.
         */
        isSearchOnly(url) {
            const current = new URL(locationApi.href);

            if (url.pathname !== current.pathname) {
                return false;
            }

            const keys = new Set([...current.searchParams.keys(), ...url.searchParams.keys()]);

            for (const key of keys) {
                if (key === SEARCH_KEY) {
                    continue;
                }

                // Every link the server generates states the page size, even at
                // its default, while the toolbar only carries the control when
                // it is not the default. Read strictly, the first keystroke
                // after any link click would look like the page size changing
                // and would push a history entry per character again. A page
                // size the new URL does not mention is the default, and the
                // default is not a change.
                if (key === 'per_page' && !url.searchParams.has('per_page')) {
                    continue;
                }

                if (this.param(current, key) !== this.param(url, key)) {
                    return false;
                }
            }

            return true;
        },

        param(url, key) {
            return url.searchParams.getAll(key).join(',');
        },
    };
}

/**
 * What one data table is, as a single Alpine scope.
 *
 * The view preference and the in-place refresh are composed rather than nested:
 * an element can carry only one `x-data`, and a second attribute on the same
 * tag is not a second scope, it is an attribute the HTML parser throws away.
 */
export function dataTable(storageKey, options = {}) {
    const view = tableView(storageKey);
    const refresh = tableAsync(options);

    return {
        ...view,
        ...refresh,

        // Both halves define an init, and spreading would keep only the second.
        init() {
            view.init.call(this);
            refresh.init.call(this);
        },
    };
}
