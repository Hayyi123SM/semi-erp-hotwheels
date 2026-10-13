import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { dateRange } from './date-range';

/**
 * The clock is pinned to a local time, not an ISO string, because the whole
 * point of the helper is to stay in the reader's calendar day. `setSystemTime`
 * drives `new Date()` exactly as the browser would.
 */
describe('dateRange', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 4, 15, 10, 0, 0)); // 15 May 2026
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('collapses both ends to today for a single-day preset', () => {
        const state = dateRange();

        state.applyPreset('today');

        expect(state.from).toBe('2026-05-15');
        expect(state.to).toBe('2026-05-15');
        expect(state.preset).toBe('today');
    });

    it('reaches back six days so a seven-day window includes today', () => {
        const state = dateRange();

        state.applyPreset('last7');

        expect(state.from).toBe('2026-05-09');
        expect(state.to).toBe('2026-05-15');
        expect(state.preset).toBe('last7');
    });

    it('reaches back twenty-nine days for a thirty-day window', () => {
        const state = dateRange();

        state.applyPreset('last30');

        expect(state.from).toBe('2026-04-16');
        expect(state.to).toBe('2026-05-15');
        expect(state.preset).toBe('last30');
    });

    it('starts the month window on the first of the month', () => {
        const state = dateRange();

        state.applyPreset('month');

        expect(state.from).toBe('2026-05-01');
        expect(state.to).toBe('2026-05-15');
        expect(state.preset).toBe('month');
    });

    it('crosses a month boundary without drifting a day', () => {
        vi.setSystemTime(new Date(2026, 2, 3, 9, 0, 0)); // 3 Mar 2026
        const state = dateRange();

        state.applyPreset('last7');

        expect(state.from).toBe('2026-02-25');
        expect(state.to).toBe('2026-03-03');
    });

    it('ignores an unknown preset and leaves the dates and label alone', () => {
        const state = dateRange({ from: '2026-01-01', to: '2026-01-31' });

        state.applyPreset('mungkin-nanti');

        expect(state.from).toBe('2026-01-01');
        expect(state.to).toBe('2026-01-31');
        expect(state.preset).toBe('');
    });

    it('keeps the dates it was initialised with', () => {
        const state = dateRange({ from: '2026-01-01', to: '2026-01-31' });

        expect(state.from).toBe('2026-01-01');
        expect(state.to).toBe('2026-01-31');
    });

    /**
     * The label is read back off the dates, so a page that prefills the default
     * window shows the matching option instead of a menu that looks untouched.
     */
    it('reports the preset its initial dates match', () => {
        expect(dateRange({ from: '2026-05-09', to: '2026-05-15' }).preset).toBe('last7');
        expect(dateRange({ from: '2026-04-16', to: '2026-05-15' }).preset).toBe('last30');
        expect(dateRange({ from: '2026-05-01', to: '2026-05-15' }).preset).toBe('month');
        expect(dateRange({ from: '2026-05-15', to: '2026-05-15' }).preset).toBe('today');
    });

    it('reports no preset for a custom or empty range', () => {
        expect(dateRange({ from: '2026-01-01', to: '2026-01-31' }).preset).toBe('');
        expect(dateRange().preset).toBe('');
    });
});
