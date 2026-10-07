import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { dataTableForm } from './data-table-form';

const TYPED_LATENCY_MS = 400;

/** The component with its one dependency replaced, so nothing can navigate. */
function mount() {
    const submit = vi.fn();

    return { state: dataTableForm({ submit }), submit };
}

/** A change event as the browser delivers it, from a control with a name. */
function changeFrom(name) {
    return { target: { name } };
}

describe('dataTableForm', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('submits as soon as a filter changes', () => {
        const { state, submit } = mount();

        state.apply(changeFrom('status'));

        expect(submit).toHaveBeenCalledTimes(1);
    });

    it('leaves the search field alone, because typing already answered it', () => {
        const { state, submit } = mount();

        // Looking away from the field fires `change` as well as `input`, and the
        // debounce has long since submitted. Answering both would ask the server
        // the same question twice for one word.
        state.search('budi');
        state.apply(changeFrom('q'));
        vi.runAllTimers();

        expect(submit).toHaveBeenCalledTimes(1);
    });

    it('survives a change event that carries no control', () => {
        const { state, submit } = mount();

        state.apply({});
        state.apply(undefined);

        expect(submit).toHaveBeenCalledTimes(2);
    });

    it('waits for a pause in typing rather than asking per keystroke', () => {
        const { state, submit } = mount();

        state.search('b');
        state.search('bu');
        state.search('bud');

        expect(submit).not.toHaveBeenCalled();

        vi.advanceTimersByTime(TYPED_LATENCY_MS - 1);
        expect(submit).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        expect(submit).toHaveBeenCalledTimes(1);
    });

it('asks straight away for a term that can only be one thing', () => {
        const { state, submit } = mount();

        // A complete code is a lookup, not a prefix of one.
        state.search('  CN01-HW-001  ');

        // A single tick, where ordinary typing is still 399ms from submitting:
        // that is the whole difference between the two.
        vi.advanceTimersByTime(1);

        expect(submit).toHaveBeenCalledTimes(1);
    });

    it.each(['CN01-HW-001', 'OW00-HW-001', 'ab-hw-001'])(
        'recognises %s as a complete code',
        (code) => {
            const { state, submit } = mount();

            // Both real code shapes from SRS Lampiran A, or this judgement is
            // never reached in production and the wait never gets skipped.
            state.search(code);
            vi.advanceTimersByTime(1);

            expect(submit).toHaveBeenCalledTimes(1);
        },
    );

    it.each(['CN01-HW-00', 'CN01-HW', 'budi', '50%'])(
        'does not mistake %s for a complete code',
        (term) => {
            const { state, submit } = mount();

            state.search(term);
            vi.advanceTimersByTime(1);

            expect(submit).not.toHaveBeenCalled();
        },
    );

    it('still waits when the code is nearly complete', () => {
        const { state, submit } = mount();

        state.search('CN01-HW-0');

        expect(submit).not.toHaveBeenCalled();
    });

    it('treats an empty field as ordinary typing', () => {
        const { state, submit } = mount();

        // An empty box is what a reader has after clearing it, and it is not a
        // code, so it waits rather than answering every backspace at once.
        state.search('');
        state.search(undefined);

        expect(submit).not.toHaveBeenCalled();
    });

    it('does not submit a form that is no longer on screen', () => {
        const { state, submit } = mount();

        state.search('budi');
        state.destroy();
        vi.runAllTimers();

        expect(submit).not.toHaveBeenCalled();
    });

    it('falls back to the form it is attached to', () => {
        // Without the injected dependency this is what a page runs, so it is
        // checked rather than assumed: the submit has to reach the form.
        const requestSubmit = vi.fn();
        const state = dataTableForm();
        state.$root = { requestSubmit };

        state.apply(changeFrom('status'));
        state.search('CN01-HW-001');
        vi.runAllTimers();

        expect(requestSubmit).toHaveBeenCalledTimes(2);
    });
});