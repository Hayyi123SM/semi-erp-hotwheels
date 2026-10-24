import { describe, it, expect, vi } from 'vitest';
import { liveStockBulk } from './live-stock-bulk';

/**
 * The component only ever queries the page for `[data-bulk-lot]` and reads
 * `checked`, so a list of boxes is all a stand-in needs. The list is swapped
 * between calls in the tests that cover a table refresh, which is exactly what
 * the real DOM does when the fragment is replaced.
 */
function makeBulk(checked = []) {
    const state = { boxes: checked.map((value) => ({ checked: value })) };
    const component = {
        ...liveStockBulk(),
        $root: { querySelectorAll: () => state.boxes },
        $dispatch: vi.fn(),
    };

    return { component, state };
}

describe('liveStockBulk', () => {
    it('counts the boxes that are already checked when the page boots', () => {
        const { component } = makeBulk([true, false, true]);

        component.init();

        expect(component.count).toBe(2);
    });

    it('selects every row currently on the page', () => {
        const { component, state } = makeBulk([false, false]);

        component.selectVisible();

        expect(state.boxes.every((box) => box.checked)).toBe(true);
        expect(component.count).toBe(2);
    });

    it('clears every tick and resets the count', () => {
        const { component, state } = makeBulk([true, true, true]);
        component.sync();

        component.clearAll();

        expect(state.boxes.every((box) => box.checked)).toBe(false);
        expect(component.count).toBe(0);
    });

    it('recounts from the DOM rather than keeping a running total', () => {
        const { component, state } = makeBulk([true]);
        component.sync();

        // A refresh replaces the rows; the selection goes with them even though
        // no event ever said "uncheck".
        state.boxes = [{ checked: false }, { checked: false }];

        component.sync();

        expect(component.count).toBe(0);
    });

    it('opens the bulk dialog once something is selected', () => {
        const { component } = makeBulk([true]);

        component.openBulk();

        expect(component.$dispatch).toHaveBeenCalledWith('open-modal', 'pindah-rak-bulk');
    });

    it('stays closed when nothing is selected', () => {
        const { component } = makeBulk([false]);

        component.openBulk();

        expect(component.$dispatch).not.toHaveBeenCalled();
    });

    it('counts a row again after the reader ticks it, before the dialog opens', () => {
        const { component, state } = makeBulk([false]);

        state.boxes[0].checked = true;
        component.openBulk();

        expect(component.count).toBe(1);
        expect(component.$dispatch).toHaveBeenCalledOnce();
    });
});
