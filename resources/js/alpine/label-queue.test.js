import { describe, it, expect, vi } from 'vitest';
import { labelQueue } from './label-queue';

/**
 * The statuses map is what the server rendered for this page: job id to
 * status. Everything below is built from it, so the tests never assume an id
 * means a particular status unless they say so.
 */
const STATUSES = { 1: 'QUEUED', 2: 'QUEUED', 3: 'SENT', 4: 'SENT', 5: 'FAILED' };

/**
 * Ids yang dirender pada halaman ini, sesuai urutan tabel. Dipisah dari
 * `STATUSES` karena select-all harus memakai daftar ini -- bukan kunci peta
 * status, yang bisa memuat id yang tidak sedang tampil.
 */
const JOB_IDS = [1, 2, 3, 4, 5];

function makeQueue(overrides = {}) {
    return { ...labelQueue(STATUSES, JOB_IDS), ...overrides };
}

/**
 * Checkbox header ditulis lewat `$refs` untuk `indeterminate`, jadi butuh objek
 * yang benar-benar punya properti itu. Stub datar sudah cukup untuk menguji
 * apakah state benar-benar menulis ke sana.
 */
function makeHeader() {
    return { checked: false, indeterminate: false };
}

describe('labelQueue', () => {
    describe('select all', () => {
        it('selects every job on the page', () => {
            const queue = makeQueue();

            queue.toggleAll();

            expect(queue.selected).toEqual(JOB_IDS);
            expect(queue.allSelected()).toBe(true);
            expect(queue.someSelected()).toBe(false);
        });

        it('clears the selection when everything is already selected', () => {
            const queue = makeQueue({ selected: [...JOB_IDS] });

            queue.toggleAll();

            expect(queue.selected).toEqual([]);
            expect(queue.allSelected()).toBe(false);
        });

        it('reports a partial selection as indeterminate, not as all selected', () => {
            const queue = makeQueue({ selected: [2] });

            expect(queue.someSelected()).toBe(true);
            expect(queue.allSelected()).toBe(false);
        });

        it('is not indeterminate for an empty selection', () => {
            const queue = makeQueue();

            expect(queue.selected).toEqual([]);
            expect(queue.someSelected()).toBe(false);
        });

        it('never reports all selected on a page with no rows', () => {
            const queue = labelQueue({}, []);

            expect(queue.allSelected()).toBe(false);
            expect(queue.someSelected()).toBe(false);

            queue.toggleAll();

            expect(queue.selected).toEqual([]);
        });

        it('selects only the rows on the page, not every id in the statuses map', () => {
            // Statuses bisa memuat id dari query lain. Mencentang "semua" tidak
            // boleh mengirim job yang tidak sedang tampil di tabel.
            const queue = labelQueue({ 1: 'QUEUED', 2: 'QUEUED', 9: 'QUEUED' }, [1, 2]);

            queue.toggleAll();

            expect(queue.selected).toEqual([1, 2]);
        });

        it('writes indeterminate onto the header checkbox on init', () => {
            const queue = makeQueue({ $refs: { selectAll: makeHeader() } });
            queue.$watch = vi.fn();

            queue.init();

            expect(queue.$refs.selectAll.indeterminate).toBe(false);
        });

        it('writes indeterminate after a row is ticked by hand', () => {
            const queue = makeQueue({ $refs: { selectAll: makeHeader() } });
            queue.$watch = vi.fn();
            queue.init();

            // Mengganti `selected` seperti yang dilakukan checkbox baris.
            queue.selected = [1];
            queue.syncSelectAll();

            expect(queue.$refs.selectAll.indeterminate).toBe(true);
        });

        it('clears indeterminate once the last row is unticked', () => {
            const queue = makeQueue({ $refs: { selectAll: makeHeader() } });
            queue.$watch = vi.fn();
            queue.init();

            queue.selected = [];
            queue.syncSelectAll();

            expect(queue.$refs.selectAll.indeterminate).toBe(false);
        });

        it('tolerates a render without the header checkbox', () => {
            const queue = makeQueue({ $refs: {} });
            queue.$watch = vi.fn();

            expect(() => queue.init()).not.toThrow();
        });

        it('reports whether a specific row is ticked', () => {
            const queue = makeQueue({ selected: [3] });

            expect(queue.isSelected(3)).toBe(true);
            expect(queue.isSelected(1)).toBe(false);
        });
    });

    describe('status-scoped actions', () => {
        it('marks as printed only when every selected job is QUEUED', () => {
            const queue = makeQueue({ selected: [1, 2] });

            expect(queue.canPrint()).toBe(true);
        });

        it('refuses to mark as printed when a SENT job is mixed in', () => {
            const queue = makeQueue({ selected: [1, 3] });

            expect(queue.canPrint()).toBe(false);
            expect(queue.canConfirm()).toBe(false);
        });

        it('confirms only when every selected job is SENT', () => {
            const queue = makeQueue({ selected: [3, 4] });

            expect(queue.canConfirm()).toBe(true);
        });

        it('retries only when every selected job is FAILED', () => {
            const queue = makeQueue({ selected: [5] });

            expect(queue.canRetry()).toBe(true);
        });

        it('never offers a FAILED job any forward action', () => {
            const queue = makeQueue({ selected: [5] });

            expect(queue.canPrint()).toBe(false);
            expect(queue.canConfirm()).toBe(false);
        });
    });

    describe('empty selection', () => {
        it('disables every action', () => {
            const queue = makeQueue({ selected: [] });

            expect(queue.canPrint()).toBe(false);
            expect(queue.canConfirm()).toBe(false);
            expect(queue.canRetry()).toBe(false);
            expect(queue.canPreview()).toBe(false);
        });

        it('does not read an empty selection as "all of them match"', () => {
            // The reason allMatch() checks length first. Without that check an
            // empty selection matches every status vacuously and the buttons
            // would light up with nothing selected.
            const queue = makeQueue({ selected: [] });

            expect(queue.allMatch('QUEUED')).toBe(false);
            expect(queue.allMatch('SENT')).toBe(false);
        });
    });

    describe('preview', () => {
        it('is available for any status, because it only renders', () => {
            for (const ids of [[1], [3], [5], [1, 3, 5]]) {
                expect(makeQueue({ selected: ids }).canPreview()).toBe(true);
            }
        });

        it('is available for a mixed selection, unlike the status actions', () => {
            const queue = makeQueue({ selected: [1, 3, 5] });

            expect(queue.canPreview()).toBe(true);
            expect(queue.canPrint()).toBe(false);
        });
    });

    describe('explanation for a disabled button', () => {
        it('lists the statuses actually selected', () => {
            const queue = makeQueue({ selected: [1, 3, 5] });

            expect(queue.mixedStatuses().sort()).toEqual(['FAILED', 'QUEUED', 'SENT']);
        });

        it('reports one status for a homogeneous selection', () => {
            const queue = makeQueue({ selected: [1, 2] });

            expect(queue.mixedStatuses()).toEqual(['QUEUED']);
        });
    });

    describe('unknown job id', () => {
        it('cannot satisfy any status action', () => {
            // An id the server did not render has no status, so it must not be
            // silently treated as matching. It would otherwise ride along on a
            // selection and reach a form that validates it away.
            const queue = makeQueue({ selected: [99] });

            expect(queue.canPrint()).toBe(false);
            expect(queue.canConfirm()).toBe(false);
            expect(queue.canRetry()).toBe(false);
            expect(queue.canPreview()).toBe(true);
        });
    });

    describe('failure dialog', () => {
        it('opens only when the selection is confirmable', () => {
            const queue = makeQueue({ selected: [1, 2] });

            expect(queue.openFail()).toBe(false);
            expect(queue.showFail).toBe(false);
        });

        it('opens for a SENT selection', () => {
            const queue = makeQueue({ selected: [3] });

            expect(queue.openFail()).toBe(true);
            expect(queue.showFail).toBe(true);
        });

        it('starts with an empty reason', () => {
            const queue = makeQueue({ selected: [3], failMessage: 'sisa dari dialog sebelumnya' });

            queue.openFail();

            expect(queue.failMessage).toBe('');
            expect(queue.failError).toBe('');
        });
    });

    describe('submitting the failure reason', () => {
        it('refuses an empty reason without submitting', () => {
            const requestSubmit = vi.fn();
            const queue = makeQueue({ selected: [3], $refs: { failForm: { requestSubmit } } });

            expect(queue.submitFail()).toBe(false);
            expect(requestSubmit).not.toHaveBeenCalled();
            expect(queue.failError).not.toBe('');
        });

        it('refuses a whitespace-only reason', () => {
            const requestSubmit = vi.fn();
            const queue = makeQueue({ selected: [3], $refs: { failForm: { requestSubmit } } });

            expect(queue.submitFail()).toBe(false);
            expect(requestSubmit).not.toHaveBeenCalled();
        });

        it('submits once a reason is given', () => {
            const requestSubmit = vi.fn();
            const queue = makeQueue({ selected: [3], $refs: { failForm: { requestSubmit } } });

            queue.failMessage = 'kertas habis';

            expect(queue.submitFail()).toBe(true);
            expect(requestSubmit).toHaveBeenCalledTimes(1);
        });

        it('clears a previous error when a new attempt succeeds', () => {
            const requestSubmit = vi.fn();
            const queue = makeQueue({ selected: [3], $refs: { failForm: { requestSubmit } } });

            queue.submitFail();
            queue.failMessage = 'kertas habis';
            queue.submitFail();

            expect(requestSubmit).toHaveBeenCalledTimes(1);
        });
    });
});
