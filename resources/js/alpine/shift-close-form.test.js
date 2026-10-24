import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { shiftCloseForm } from './shift-close-form';

const ENDPOINT = '/pos/shift-kasir/12/tutup';
const REDIRECT = '/pos/shift-kasir';
const EXPECTED = 160000;
const THRESHOLD = 5000;

/**
 * Form yang benar-benar ada di DOM, bukan objek biasa.
 *
 * `send()` mengambil `new FormData(this.$el)`, jadi `closing_cash` harus benar-benar
 * terkirim sebagai field -- kalau test memakai objek biasa, test ini hanya memeriksa
 * bentuk, bukan apakah angkanya sampai ke server.
 */
function formElement(closingCash = '162000') {
    const element = document.createElement('form');

    element.append(
        Object.assign(document.createElement('input'), {
            name: 'closing_cash',
            value: closingCash,
        }),
    );

    return element;
}

function makeForm(overrides = {}) {
    const state = shiftCloseForm({
        endpoint: ENDPOINT,
        redirectTo: REDIRECT,
        expectedCash: EXPECTED,
        threshold: THRESHOLD,
        isOwner: false,
    });

    state.$el = formElement();

    return Object.assign(state, overrides);
}

function jsonResponse(status, payload) {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => payload,
    };
}

/** Jawaban 422 Laravel untuk field yang gagal divalidasi. */
function validationFailure(field, message) {
    return jsonResponse(422, { message, errors: { [field]: [message] } });
}

/**
 * Komponen dengan `fetch` dan `window.location.assign` dipalsukan.
 *
 * Keduanya adalah satu-satunya hal di luar komponen ini yang diganti, bukan
 * di-stub -- supaya test yang lupa memalsukan salah satunya gagal keras, bukan
 * diam-diam menavigasi jsdom.
 */
function mount({ responses, owner = false } = {}) {
    const element = formElement();

    const state = shiftCloseForm({
        endpoint: ENDPOINT,
        redirectTo: REDIRECT,
        expectedCash: EXPECTED,
        threshold: THRESHOLD,
        isOwner: owner,
    });

    state.$el = element;

    const queue = [...(responses ?? [])];
    const calls = [];

    global.fetch = vi.fn(async (url, init) => {
        calls.push({ url, init });

        return queue.length > 1 ? queue.shift() : queue[0];
    });

    return { state, calls, element };
}

beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('shiftCloseForm difference preview', () => {
    it('treats an empty field as no number at all rather than as zero', () => {
        const form = makeForm({ closing: '' });

        // Kalau kolom kosong dibaca sebagai nol, layar langsung menunjukkan
        // "selisih Rp160.000" sebelum kasir menghitung apa pun -- dan angkanya
        // terlihat seperti temuan, bukan seperti form yang belum diisi.
        expect(form.closingCash).toBeNull();
        expect(form.difference).toBeNull();
        expect(form.needsApproval()).toBe(false);
        expect(form.canSubmit()).toBe(false);
    });

    it('reads the typed figure with grouping still in the field', () => {
        // Kasir mengetik ke field yang sudah menampilkan `1.000.000`, jadi yang
        // sampai ke sini masih ada pemisah ribuan-nya.
        expect(makeForm({ closing: '1.620.000' }).closingCash).toBe(1620000);
    });

    it('computes the difference the way the server does', () => {
        expect(makeForm({ closing: '162000' }).difference).toBe(2000);
        expect(makeForm({ closing: '150000' }).difference).toBe(-10000);
    });

    it('warns about a missing drawer just as loudly as an excess one', () => {
        // Uang yang kurang sebanyak yang lebih: keduanya selisih, dan keduanya
        // berarti uang yang tidak ada di laci.
        expect(makeForm({ closing: '140000' }).needsApproval()).toBe(true);
        expect(makeForm({ closing: '190000' }).needsApproval()).toBe(true);
    });

    it('stays quiet inside the threshold', () => {
        expect(makeForm({ closing: '165000' }).needsApproval()).toBe(false);
        expect(makeForm({ closing: '155000' }).needsApproval()).toBe(false);
    });

    it('stays quiet at exactly the threshold', () => {
        // 5.000 adalah "di dalam ambang": hanya selisih yang benar-benar
        // melewatinya yang dikirim ke Owner.
        expect(makeForm({ closing: '165000' }).needsApproval()).toBe(false);
    });

    it('never warns an owner, whatever the difference is', () => {
        const form = shiftCloseForm({
            endpoint: ENDPOINT,
            redirectTo: REDIRECT,
            expectedCash: EXPECTED,
            threshold: THRESHOLD,
            isOwner: true,
        });

        form.closing = '900000';

        expect(form.difference).toBe(740000);
        expect(form.needsApproval()).toBe(false);
    });

    it('keeps the button dead while a request is in flight', () => {
        expect(makeForm({ closing: '162000' }).canSubmit()).toBe(true);
        expect(makeForm({ closing: '162000', busy: true }).canSubmit()).toBe(false);
    });

    it('refuses to run twice at once', async () => {
        const { state, calls } = mount({ responses: [jsonResponse(200, {})] });

        state.busy = true;
        await state.run();

        expect(calls).toHaveLength(0);
    });
});

describe('shiftCloseForm.submit', () => {
    it('sends the form to the endpoint it was given', async () => {
        const { state, calls } = mount({ responses: [jsonResponse(200, {})] });

        await state.submit({ preventDefault: vi.fn() });

        expect(calls[0].url).toBe(ENDPOINT);
        expect(calls[0].init.method).toBe('POST');
    });

    it('asks for JSON so the refusal arrives as a 422 instead of a page of HTML', async () => {
        const { state, calls } = mount({ responses: [jsonResponse(200, {})] });

        await state.submit({ preventDefault: vi.fn() });

        expect(calls[0].init.headers.Accept).toBe('application/json');
        expect(calls[0].init.headers['X-Requested-With']).toBe('XMLHttpRequest');
    });

    it('actually puts the counted money on the wire', async () => {
        const { state, calls } = mount({ responses: [jsonResponse(200, {})] });

        state.closing = '162000';
        state.$el.querySelector('[name="closing_cash"]').value = '162000';

        await state.submit({ preventDefault: vi.fn() });

        expect(calls[0].init.body.get('closing_cash')).toBe('162000');
    });

    it('sends no authorization token at all', async () => {
        const { state, calls } = mount({ responses: [jsonResponse(200, {})] });

        await state.submit({ preventDefault: vi.fn() });

        expect(calls[0].init.body.has('pin_token')).toBe(false);
    });

    it('prevents the browser submitting the form as well', async () => {
        const preventDefault = vi.fn();
        const { state } = mount({ responses: [jsonResponse(200, {})] });

        await state.submit({ preventDefault });

        expect(preventDefault).toHaveBeenCalled();
    });

    it('goes to the shift page when the close was accepted', async () => {
        const assign = vi.fn();
        const { state } = mount({ responses: [jsonResponse(200, {})] });

        window.location.assign = assign;

        await state.submit({ preventDefault: vi.fn() });

        expect(assign).toHaveBeenCalledWith(REDIRECT);
    });
});

describe('shiftCloseForm when the server refuses the request', () => {
    it('shows the first validation message', async () => {
        const { state } = mount({
            responses: [validationFailure('closing_cash', 'Uang penutup harus angka bulat rupiah.')],
        });

        await state.submit({ preventDefault: vi.fn() });

        expect(state.error).toBe('Uang penutup harus angka bulat rupiah.');
    });

    it('does not navigate when the request was refused', async () => {
        const assign = vi.fn();
        const { state } = mount({ responses: [validationFailure('closing_cash', 'Wajib diisi.')] });
        window.location.assign = assign;

        await state.submit({ preventDefault: vi.fn() });

        expect(assign).not.toHaveBeenCalled();
    });

    it('reports a server failure in words rather than showing nothing', async () => {
        const { state } = mount({ responses: [jsonResponse(500, {})] });

        await state.submit({ preventDefault: vi.fn() });

        expect(state.error).toBeTruthy();
    });

    it('survives a response that is not JSON at all', async () => {
        // 502 dari proxy dan maintenance mode keduanya kembali HTML. Komponen ini
        // tidak boleh berubah jadi halaman kosong karena tidak bisa parse.
        const { state } = mount({
            responses: [{ ok: false, status: 500, json: async () => { throw new Error('not json'); } }],
        });

        await state.submit({ preventDefault: vi.fn() });

        expect(state.error).toBeTruthy();
    });

    it('says the network is the problem when the request never arrived', async () => {
        const { state } = mount({ responses: [jsonResponse(200, {})] });

        // Dipasang setelah `mount`, karena `mount` memasang `fetch`-nya
        // sendiri dan akan menimpa ini kalau urutannya dibalik.
        global.fetch = vi.fn(async () => {
            throw new Error('offline');
        });

        await state.submit({ preventDefault: vi.fn() });

        expect(state.error).toContain('koneksi');
    });

    it('releases the button after a failure so the next attempt is possible', async () => {
        const { state } = mount({
            responses: [validationFailure('closing_cash', 'Wajib diisi.')],
        });

        state.closing = '162000';
        await state.submit({ preventDefault: vi.fn() });

        expect(state.busy).toBe(false);
        expect(state.canSubmit()).toBe(true);
        // Dan angkanya masih ada, supaya percobaan kedua tidak dimulai dari nol.
        expect(state.closing).toBe('162000');
    });
});
