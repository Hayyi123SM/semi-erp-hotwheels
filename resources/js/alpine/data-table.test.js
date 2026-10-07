import { describe, it, expect, vi } from 'vitest';
import { tableAsync, dataTable } from './data-table';

const LIST = 'http://localhost/master/penitip';

/**
 * The shape the component actually finds on a page: the toolbar form with its
 * own controls, the slot holding the second row, and the region that gets
 * replaced. Mirrored here rather than invented, because the component's whole
 * job is knowing which node is which.
 *
 * The toolbar only carries a page-size input when the size is not the default,
 * so the default case omits it — the same way the view does.
 */
function mount({ strip = 'chips', perPage = null } = {}) {
    document.body.innerHTML = `
        <div id="root">
            <form data-table-form method="GET" action="${LIST}">
                <input type="hidden" name="sort" value="">
                <input type="hidden" name="direction" value="">
                ${perPage === null ? '' : `<input type="hidden" name="per_page" value="${perPage}">`}
                <input type="search" name="q" value="">
                <div data-strip-slot ${strip === 'none' ? 'hidden' : ''}>
                    ${strip === 'none' ? '' : '<div class="filter-strip">chips</div>'}
                </div>
            </form>
            <div data-results><p>old rows</p></div>
        </div>
    `;

    return document.getElementById('root');
}

function fragment({ present = '1', strip = 'chips', rows = '<p>new rows</p>' } = {}) {
    return `
        <div data-fragment="strip" data-present="${present}">
            ${present === '1' ? `<div class="filter-strip">${strip}</div>` : ''}
        </div>
        <div data-fragment="results">${rows}</div>
    `;
}

function location(href = LIST) {
    const url = new URL(href);

    return { href: url.href, origin: url.origin, pathname: url.pathname };
}

function history() {
    const calls = [];

    return {
        calls,
        pushState(_state, _title, href) {
            calls.push(['push', href]);
        },
        replaceState(_state, _title, href) {
            calls.push(['replace', href]);
        },
    };
}

/** A fetch stand-in that answers with a fragment, or fails on demand. */
function respond({ ok = true, status = 200, body = fragment() } = {}) {
    return vi.fn().mockResolvedValue({ ok, status, text: () => Promise.resolve(body) });
}

function build(root, { fetchImpl = respond(), historyApi = history(), at = LIST, initTree = vi.fn(), assign = vi.fn() } = {}) {
    const component = tableAsync({ fetchImpl, history: historyApi, location: location(at), initTree, assign });

    // Alpine supplies this as a magic; standing in for it here keeps the test
    // about the component rather than about booting Alpine.
    component.$root = root;

    return { component, fetchImpl, historyApi, initTree, assign };
}

function submitEvent(form) {
    return { target: form, preventDefault: vi.fn() };
}

function clickEvent(target, extra = {}) {
    return { target, button: 0, defaultPrevented: false, preventDefault: vi.fn(), ...extra };
}

describe('tableAsync', () => {
    describe('swapping the fragment in', () => {
        it('replaces the rows and keeps the toolbar untouched', () => {
            const root = mount();
            const { component } = build(root);

            component.swap(fragment({ rows: '<p>fresh</p>' }));

            expect(root.querySelector('[data-results]').innerHTML).toBe('<p>fresh</p>');
            // The search field is outside the region on purpose: a reader
            // halfway through a word cannot have it swapped out from under them.
            expect(root.querySelector('input[name="q"]')).not.toBeNull();
        });

        it('runs the new markup through Alpine', () => {
            const root = mount();
            const { component, initTree } = build(root);

            component.swap(fragment());

            // Without this the page-size select and every row menu render
            // correctly and then quietly do nothing.
            const walked = initTree.mock.calls.map(([element]) => element);
            expect(walked).toContain(root.querySelector('[data-results]'));
            expect(walked).toContain(root.querySelector('[data-strip-slot]'));
        });

        it('removes the second row when the fragment says it has no reason to be there', () => {
            const root = mount();
            const { component } = build(root);

            expect(root.querySelector('[data-strip-slot]').hidden).toBe(false);

            component.swap(fragment({ present: '0' }));

            expect(root.querySelector('[data-strip-slot]').hidden).toBe(true);
            expect(root.querySelector('.filter-strip')).toBeNull();
        });

        it('puts the second row back when the fragment brings it', () => {
            const root = mount({ strip: 'none' });
            const { component } = build(root);

            component.swap(fragment({ present: '1' }));

            expect(root.querySelector('[data-strip-slot]').hidden).toBe(false);
            expect(root.querySelector('.filter-strip')).not.toBeNull();
        });

        it('ignores a response with no table in it', () => {
            const root = mount();
            const { component } = build(root);

            component.swap('<html><body><p>a different page</p></body></html>');

            expect(root.querySelector('[data-results]').innerHTML).toBe('<p>old rows</p>');
        });
    });

    describe('outrunning the reader', () => {
        it('calls off the request in flight', async () => {
            const root = mount();
            const signals = [];
            const fetchImpl = vi.fn((_url, init) => {
                signals.push(init.signal);

                return new Promise(() => {});
            });
            const { component } = build(root, { fetchImpl });

            component.load(`${LIST}?q=a`);
            component.load(`${LIST}?q=ab`);

            expect(signals[0].aborted).toBe(true);
            expect(signals[1].aborted).toBe(false);
        });

        it('drops a response a newer navigation has already overtaken', async () => {
            const root = mount();
            const gates = [];
            const bodies = { a: '<p>stale</p>', ab: '<p>fresh</p>' };
            const fetchImpl = vi.fn((url) => new Promise((resolve) => {
                const key = new URL(url).searchParams.get('q');
                gates.push(() => resolve({ ok: true, status: 200, text: () => Promise.resolve(fragment({ rows: bodies[key] })) }));
            }));
            const { component } = build(root, { fetchImpl });

            const first = component.load(`${LIST}?q=a`);
            const second = component.load(`${LIST}?q=ab`);

            // The slow answer for "a" lands after "ab" already has. Aborting is
            // a request the browser may honour or ignore, so the ticket is the
            // only thing that can catch this.
            gates[1]();
            gates[0]();
            await Promise.all([first, second]);

            expect(root.querySelector('[data-results]').textContent).toBe('fresh');
        });

        it('never lets an aborted request report a failure', async () => {
            const root = mount();
            const fetchImpl = vi.fn((_url, init) => new Promise((_resolve, reject) => {
                init.signal.addEventListener('abort', () => reject(new Error('aborted')));
            }));
            const { component } = build(root, { fetchImpl });

            const first = component.load(`${LIST}?q=a`);
            component.load(`${LIST}?q=ab`);

            await first;

            expect(component.error).toBe('');
        });
    });

    describe('history', () => {
        it('replaces the entry while the reader is still typing the same search', () => {
            const root = mount();
            const { component, historyApi } = build(root);

            component.navigate(`${LIST}?q=peni`, { replace: component.isSearchOnly(new URL(`${LIST}?q=peni`)) });

            // Otherwise every keystroke the debounce lets through is a place the
            // back button has to walk back through.
            expect(historyApi.calls).toEqual([['replace', `${LIST}?q=peni`]]);
        });

        it('treats a changed filter, sort or page as somewhere worth going back to', () => {
            const root = mount();
            const { component, historyApi } = build(root);

            const next = new URL(`${LIST}?sort=name`);
            expect(component.isSearchOnly(next)).toBe(false);

            component.navigate(next, { replace: component.isSearchOnly(next) });

            expect(historyApi.calls).toEqual([['push', `${LIST}?sort=name`]]);
        });

        it('calls a search a search even when the search is emptied', () => {
            const root = mount();
            const { component } = build(root, { at: `${LIST}?q=peni` });

            expect(component.isSearchOnly(new URL(LIST))).toBe(true);
        });

        it('refetches on a back button without writing history of its own', async () => {
            const root = mount();
            const fetchImpl = respond();
            const { component, historyApi } = build(root, { fetchImpl });
            component.init();

            window.dispatchEvent(new Event('popstate'));
            await vi.waitFor(() => expect(fetchImpl).toHaveBeenCalledTimes(1));

            expect(historyApi.calls).toEqual([]);
            component.destroy();
        });
    });

    describe('the toolbar form', () => {
        it('turns a submit into a query without dragging the reader to a new page', () => {
            const root = mount();
            const { component, fetchImpl, historyApi } = build(root);
            const form = root.querySelector('[data-table-form]');

            root.querySelector('input[name="q"]').value = 'peni';
            const event = submitEvent(form);

            component.onSubmit(event);

            expect(event.preventDefault).toHaveBeenCalled();
            expect(fetchImpl.mock.calls[0][0]).toBe(`${LIST}?q=peni`);
            expect(historyApi.calls[0][0]).toBe('replace');
        });

        it('keeps a page size the reader chose out of the way of the search', () => {
            const root = mount({ perPage: 25 });
            const { component, fetchImpl } = build(root, { at: `${LIST}?per_page=10&sort=name` });

            root.querySelector('input[name="q"]').value = 'peni';
            component.onSubmit(submitEvent(root.querySelector('[data-table-form]')));

            // The page size the reader picked travels with the search, and does
            // not read as "the page size changed".
            expect(fetchImpl.mock.calls[0][0]).toBe(`${LIST}?per_page=25&q=peni`);
        });

        it('still treats a deliberate change of page size as somewhere to go back to', () => {
            const root = mount();
            const { component } = build(root, { at: `${LIST}?per_page=10` });

            // Choosing 25 states the page size, so it is a real change and not
            // the middle of a search.
            expect(component.isSearchOnly(new URL(`${LIST}?per_page=25`))).toBe(false);
        });

        it('leaves a form that is not the list query alone', () => {
            const root = mount();
            const { component, fetchImpl } = build(root);
            const dialog = document.createElement('form');
            document.body.append(dialog);

            const event = submitEvent(dialog);
            component.onSubmit(event);

            // The confirm dialog posts to delete a row; it is not a search.
            expect(event.preventDefault).not.toHaveBeenCalled();
            expect(fetchImpl).not.toHaveBeenCalled();
        });

        it('counts a checkbox filter only while it is ticked', () => {
            const root = mount();
            root.querySelector('form').insertAdjacentHTML('beforeend', '<input type="checkbox" name="needs_review" value="1">');
            const { component } = build(root);
            const form = root.querySelector('[data-table-form]');

            expect(component.queryOf(form).get('needs_review')).toBeNull();

            form.querySelector('[name="needs_review"]').checked = true;

            expect(component.queryOf(form).get('needs_review')).toBe('1');
        });

        it('leaves out a control the reader has emptied', () => {
            const root = mount();
            const { component } = build(root);

            // A trail of `?q=&status=` is not a tidier way to say the same thing
            // than the links the server generates.
            expect(component.queryOf(root.querySelector('form')).toString()).toBe('');
        });
    });

    describe('staying in step with the URL', () => {
        it('pulls a stale hidden sort input back in line', () => {
            const root = mount();
            const { component } = build(root);

            component.syncForm(`${LIST}?sort=name&direction=desc`);

            expect(root.querySelector('input[name="sort"]').value).toBe('name');
            expect(root.querySelector('input[name="direction"]').value).toBe('desc');
        });

        it('clears a control the URL no longer mentions', () => {
            const root = mount();
            const { component } = build(root, { at: `${LIST}?q=peni` });

            component.syncForm(LIST);

            expect(root.querySelector('input[name="q"]').value).toBe('');
        });

        it('does not overwrite the field the reader is typing in', () => {
            const root = mount();
            const { component } = build(root);

            const search = root.querySelector('input[name="q"]');
            search.value = 'penitip baru';
            search.focus();

            // Their keystrokes are newer than the answer that is landing.
            component.syncForm(`${LIST}?q=peni`);

            expect(search.value).toBe('penitip baru');
        });

        it('matches a flag filter to the query rather than to its value', () => {
            const root = mount();
            root.querySelector('form').insertAdjacentHTML('beforeend', '<input type="checkbox" name="needs_review" value="1">');
            const { component } = build(root);
            const flag = root.querySelector('[name="needs_review"]');

            component.syncForm(`${LIST}?needs_review=1`);
            expect(flag.checked).toBe(true);

            component.syncForm(`${LIST}?needs_review=0`);
            expect(flag.checked).toBe(false);
        });
    });

    describe('links', () => {
        it('takes a sort header in place', () => {
            const root = mount();
            const { component, fetchImpl } = build(root);
            const link = document.createElement('a');
            link.href = `${LIST}?sort=name`;
            root.append(link);

            const event = clickEvent(link);
            component.onClick(event);

            expect(event.preventDefault).toHaveBeenCalled();
            expect(fetchImpl.mock.calls[0][0]).toBe(`${LIST}?sort=name`);
        });

        it('leaves a link out of this list to the browser', () => {
            const root = mount();
            const { component, fetchImpl } = build(root);
            const link = document.createElement('a');
            link.href = 'http://localhost/master/penitip/1/edit';
            root.append(link);

            component.onClick(clickEvent(link));

            expect(fetchImpl).not.toHaveBeenCalled();
        });

        it('leaves a download to the browser', () => {
            const root = mount();
            const { component, fetchImpl } = build(root);
            const link = document.createElement('a');
            link.href = `${LIST}?format=csv`;
            link.setAttribute('data-full-navigation', '');
            root.append(link);

            component.onClick(clickEvent(link));

            expect(fetchImpl).not.toHaveBeenCalled();
        });

        it('leaves a link the reader asked to open in a new tab alone', () => {
            const root = mount();
            const { component, fetchImpl } = build(root);
            const link = document.createElement('a');
            link.href = `${LIST}?sort=name`;
            link.target = '_blank';
            root.append(link);

            component.onClick(clickEvent(link));

            expect(fetchImpl).not.toHaveBeenCalled();
        });

        it('leaves a link the reader modified alone', () => {
            const root = mount();
            const { component, fetchImpl } = build(root);
            const link = document.createElement('a');
            link.href = `${LIST}?sort=name`;
            root.append(link);

            component.onClick(clickEvent(link, { metaKey: true }));

            expect(fetchImpl).not.toHaveBeenCalled();
        });
    });

    describe('rows', () => {
        const NOTA = 'http://localhost/pos/nota/7';

        /**
         * A row as the view renders it, with whatever the reader would click on.
         *
         * `url: null` builds the row every other table has -- one with no page of
         * its own -- so the handler can be shown to leave it alone rather than
         * being assumed to.
         */
        function mountRow({ url = NOTA, inner = '<span>HW-001</span>' } = {}) {
            const root = mount();
            const row = document.createElement('tr');

            if (url !== null) {
                row.dataset.rowUrl = url;
            }

            row.innerHTML = `<td>${inner}</td>`;
            root.querySelector('[data-results]').append(row);

            return { root, row, cell: row.querySelector('td').firstElementChild };
        }

        it('opens the page the row points at, as a real navigation', () => {
            const { root, cell } = mountRow();
            const { component, assign, fetchImpl } = build(root);
            const event = clickEvent(cell);

            component.onClick(event);

            expect(event.preventDefault).toHaveBeenCalled();
            expect(assign).toHaveBeenCalledWith(NOTA);

            // Not the fragment loader: a detail page is a document, and asking
            // `load()` for it would swap this table's rows with markup it does
            // not understand.
            expect(fetchImpl).not.toHaveBeenCalled();
        });

        it('takes a plain click anywhere on the row, not only on its text', () => {
            const { root, row } = mountRow();
            const { component, assign } = build(root);

            component.onClick(clickEvent(row));

            expect(assign).toHaveBeenCalledWith(NOTA);
        });

        it('leaves the link inside the row to the link', () => {
            const { root } = mountRow({
                inner: '<a href="http://localhost/pos/nota/7/edit">Detail</a>',
            });
            const { component, assign, fetchImpl } = build(root);

            component.onClick(clickEvent(root.querySelector('a')));

            // Out of this list, so the link branch returns without touching it
            // -- and the row must not answer for a click that landed on a link.
            expect(assign).not.toHaveBeenCalled();
            expect(fetchImpl).not.toHaveBeenCalled();
        });

        it('leaves a button inside the row as a button', () => {
            const { root } = mountRow({ inner: '<button type="button">Void</button>' });
            const { component, assign } = build(root);
            const event = clickEvent(root.querySelector('button'));

            component.onClick(event);

            expect(assign).not.toHaveBeenCalled();
            expect(event.preventDefault).not.toHaveBeenCalled();
        });

        it('leaves a row with no page of its own alone', () => {
            const { root, cell } = mountRow({ url: null });
            const { component, assign } = build(root);

            component.onClick(clickEvent(cell));

            expect(assign).not.toHaveBeenCalled();
        });

        it('leaves a modified click to the browser', () => {
            const { root, cell } = mountRow();
            const { component, assign } = build(root);

            component.onClick(clickEvent(cell, { metaKey: true }));

            expect(assign).not.toHaveBeenCalled();
        });
    });

    describe('when a request fails', () => {
        it('says so without erasing the rows it could not replace', async () => {
            const root = mount();
            const fetchImpl = respond({ ok: false, status: 500 });
            const { component } = build(root, { fetchImpl });

            await component.load(`${LIST}?q=peni`);

            expect(component.error).toBe('Gagal memuat data.');
            expect(component.busy).toBe(false);
            expect(root.querySelector('[data-results]').innerHTML).toBe('<p>old rows</p>');
        });

        it('repeats the last url on a retry', async () => {
            const root = mount();
            const fetchImpl = respond({ ok: false, status: 500 });
            const { component } = build(root, { fetchImpl });

            await component.navigate(`${LIST}?q=peni`);
            expect(component.error).not.toBe('');

            fetchImpl.mockResolvedValue({ ok: true, status: 200, text: () => Promise.resolve(fragment()) });
            await component.retry();

            expect(component.error).toBe('');
            expect(fetchImpl).toHaveBeenLastCalledWith(`${LIST}?q=peni`, expect.any(Object));
        });

        it('asks for the fragment and nothing else', async () => {
            const root = mount();
            const fetchImpl = respond();
            const { component } = build(root, { fetchImpl });

            await component.load(LIST);

            const [, init] = fetchImpl.mock.calls[0];
            expect(init.headers['X-Table-Fragment']).toBe('1');
            expect(init.credentials).toBe('same-origin');
        });
    });

    describe('while it is working', () => {
        it('marks the region busy for as long as it is, and not after', async () => {
            const root = mount();
            let release;
            const fetchImpl = vi.fn(() => new Promise((resolve) => {
                release = () => resolve({ ok: true, status: 200, text: () => Promise.resolve(fragment()) });
            }));
            const { component } = build(root, { fetchImpl });

            const pending = component.load(LIST);
            expect(component.busy).toBe(true);

            release();
            await pending;

            expect(component.busy).toBe(false);
        });
    });
});

describe('dataTable', () => {
    it('keeps the view preference and the refresh in one scope', () => {
        const combined = dataTable('datatable-view:test');

        // An element can carry only one x-data, so the root has to be the
        // component that answers to both jobs.
        expect(typeof combined.setView).toBe('function');
        expect(typeof combined.navigate).toBe('function');
        expect(typeof combined.init).toBe('function');
    });

    it('starts both halves', () => {
        document.body.innerHTML = '<div id="root"><a href="#"></a></div>';
        const addEventListener = vi.spyOn(window, 'addEventListener');

        dataTable('datatable-view:test').init();

        expect(addEventListener).toHaveBeenCalledWith('popstate', expect.any(Function));
        addEventListener.mockRestore();
    });
});
