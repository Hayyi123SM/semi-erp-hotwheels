import { describe, it, expect, vi, afterEach } from 'vitest';
import { rowConfirm } from './row-confirm';

/**
 * The scope has no form of its own: the table renders one shared form and this
 * fills it in and submits it. So these tests stand in a form and a dialog.
 *
 * The stub runs `onConfirm` the way the real helper does -- inside the dialog's
 * own pre-confirm step, and only for a yes -- because that is the contract the
 * scope is written against. A stub that skipped it would let the scope submit
 * from the wrong place and still pass.
 */
function mount({ confirm = true } = {}) {
    const form = document.createElement('form');
    const method = document.createElement('input');
    form.append(method);
    form.submit = vi.fn();

    // Mutable so a test can change its mind about the answer partway through,
    // the way a reader who clicks Hapus and then changes their mind does.
    const answer = { confirm };

    const notify = {
        dangerConfirm: vi.fn(async (options) => {
            if (!answer.confirm) {
                return false;
            }

            await options.onConfirm?.();

            return true;
        }),
    };

    const scope = rowConfirm({ notify });
    scope.$refs = { form, method };

    return { scope, form, method, notify, answer };
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('rowConfirm: asking', () => {
    it('passes the row action\'s wording straight through to the question', async () => {
        const { scope, notify } = mount();

        await scope.ask({
            title: 'Arsipkan penitip?',
            description: 'Data tidak bisa dikembalikan.',
            confirmText: 'Arsipkan',
            action: '/master/penitip/1/archive',
            method: 'PATCH',
        });

        expect(notify.dangerConfirm).toHaveBeenCalledWith({
            title: 'Arsipkan penitip?',
            description: 'Data tidak bisa dikembalikan.',
            confirmText: 'Arsipkan',
            onConfirm: expect.any(Function),
        });
    });

    it('falls back to wording when the controller sent none', async () => {
        const { scope, notify } = mount();

        await scope.ask({ action: '/x' });

        expect(notify.dangerConfirm).toHaveBeenCalledWith({
            title: 'Konfirmasi',
            description: '',
            confirmText: 'Ya, lanjutkan',
            onConfirm: expect.any(Function),
        });
    });

    it('asks as a destructive question, since every caller is deleting or archiving', async () => {
        const { scope, notify } = mount();

        await scope.ask({ title: 'Hapus?' });

        expect(notify.dangerConfirm).toHaveBeenCalled();
    });
});

describe('rowConfirm: submitting', () => {
    it('posts through the shared form once the question is answered yes', async () => {
        const { scope, form } = mount({ confirm: true });

        await scope.ask({ action: '/master/penitip/1/archive', method: 'PATCH' });

        expect(form.submit).toHaveBeenCalledTimes(1);
    });

    it('sends the method override for anything that is not a plain POST', async () => {
        // One scope per submit, and not only out of neatness: the second `ask()`
        // on a single scope is exactly the duplicate this component now refuses,
        // so reusing one here would test nothing.
        const first = mount();
        await first.scope.ask({ action: '/a', method: 'DELETE' });
        expect(first.method.value).toBe('DELETE');

        const second = mount();
        await second.scope.ask({ action: '/a', method: 'PUT' });
        expect(second.method.value).toBe('PUT');
    });

    it('leaves the override empty for a plain POST, which would otherwise double up', async () => {
        const { scope, method } = mount();

        await scope.ask({ action: '/a', method: 'post' });

        expect(method.value).toBe('');
    });

    it('points the form at the row it was asked about', async () => {
        const { scope, form } = mount();

        await scope.ask({ action: '/master/penitip/7/archive', method: 'POST' });

        expect(form.action).toContain('/master/penitip/7/archive');
    });

    it('submits nothing at all when the answer is no', async () => {
        const { scope, form, method } = mount({ confirm: false });

        await scope.ask({ action: '/master/penitip/1/archive', method: 'PATCH' });

        expect(form.submit).not.toHaveBeenCalled();
        // Untouched as well, so a later row cannot inherit this row's target.
        expect(method.value).toBe('');
    });

    it('submits the row once however many times the question is answered yes', async () => {
        const { scope, form } = mount({ confirm: true });

        // The sequence a fast double-click on the row button produces: two
        // questions raised, two yeses, one row.
        await scope.ask({ action: '/master/penitip/1/archive', method: 'PATCH' });
        await scope.ask({ action: '/master/penitip/1/archive', method: 'PATCH' });
        await scope.ask({ action: '/master/penitip/1/archive', method: 'PATCH' });

        expect(form.submit).toHaveBeenCalledTimes(1);
    });

    it('keeps the latch shut even when a later question names a different row', async () => {
        const { scope, form } = mount({ confirm: true });

        await scope.ask({ action: '/master/penitip/1/archive', method: 'PATCH' });
        await scope.ask({ action: '/master/penitip/2/archive', method: 'PATCH' });

        // The second row is not the one the reader approved, and the first is
        // already on its way out.
        expect(form.submit).toHaveBeenCalledTimes(1);
    });

    it('leaves the latch clear for the next question after a no', async () => {
        // The reader who clicks Hapus, changes their mind, and clicks it again
        // must not be left with a button that does nothing.
        const { scope, form, answer } = mount({ confirm: false });

        await scope.ask({ action: '/a', method: 'DELETE' });
        expect(scope.submitted).toBe(false);

        answer.confirm = true;
        await scope.ask({ action: '/a', method: 'DELETE' });

        expect(form.submit).toHaveBeenCalledTimes(1);
    });
});

describe('rowConfirm: wiring', () => {
    it('answers the event the row buttons dispatch', async () => {
        const { scope, notify } = mount();

        scope.init();
        window.dispatchEvent(new CustomEvent('row-confirm', {
            detail: { title: 'Hapus rak?', action: '/a', method: 'DELETE' },
        }));

        // The listener is async, so let the microtask that reaches the dialog run.
        await Promise.resolve();
        await Promise.resolve();

        expect(notify.dangerConfirm).toHaveBeenCalledWith(
            expect.objectContaining({ title: 'Hapus rak?' })
        );
    });

    it('survives an event with no detail at all', async () => {
        const { scope, notify, form } = mount({ confirm: false });

        scope.init();
        window.dispatchEvent(new CustomEvent('row-confirm'));

        await Promise.resolve();
        await Promise.resolve();

        expect(notify.dangerConfirm).toHaveBeenCalled();
        expect(form.submit).not.toHaveBeenCalled();
    });
});
