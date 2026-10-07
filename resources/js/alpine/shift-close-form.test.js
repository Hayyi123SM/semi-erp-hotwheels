import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { shiftCloseForm } from './shift-close-form';

const ENDPOINT = '/pos/shift-kasir/12/tutup';
const REDIRECT = '/pos/shift-kasir';
const CONTEXT = 'pos.close-shift';
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
        Object.assign(document.createElement('input'), { name: 'pin_token', value: '' }),
    );

    return element;
}

function makeForm(overrides = {}) {
    const state = shiftCloseForm({
        endpoint: ENDPOINT,
        redirectTo: REDIRECT,
        context: CONTEXT,
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
 * Komponen dengan `window` dan `fetch` dipalsukan.
 *
 * `window.location.assign` dan `window.pin` adalah satu-satunya hal di luar
 * komponen ini yang diganti, bukan di-stub -- supaya test yang lupa memalsukan
 * salah satunya gagal keras, bukan diam-diam_failed navigate di jsdom.
 */
function mount({ responses, pin, owner = false } = {}) {
    const element = formElement();

    delete window.pin;
    window.pin = pin;

    const state = shiftCloseForm({
        endpoint: ENDPOINT,
        redirectTo: REDIRECT,
        context: CONTEXT,
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
    delete window.pin;
});

describe('shiftCloseForm difference preview', () => {
    it('treats an empty field as no number at all rather than as zero', () => {
        const form = makeForm({ closing: '' });

        // Kalau kolom kosong dibaca sebagai nol, layar langsung menunjukkan
        // "selisih Rp160.000" sebelum kasir menghitung apa pun -- dan angkanya
        // terlihat seperti temuan, bukan seperti form yang belum diisi.
        expect(form.closingCash).toBeNull();
        expect(form.difference).toBeNull();
        expect(form.needsOwnerPin()).toBe(false);
        expect(form.canSubmit()).toBe(false);
    });

    it('reads the typed figure with grouping still in the field', () => {
        // Kasir mengetik ke field yang sudah menampilkan `1.000.000`, jadi yang
        // arrive ke sini masih ada pemisah thousands-nya.
        expect(makeForm({ closing: '1.620.000' }).closingCash).toBe(1620000);
    });

    it('computes the difference the way the server does', () => {
        expect(makeForm({ closing: '162000' }).difference).toBe(2000);
        expect(makeForm({ closing: '150000' }).difference).toBe(-10000);
    });

    it('warns about a missing drawer just as loudly as an excess one', () => {
        // Uang yang kurang sebanyak yang lebih: keduanya selisih, dan keduanya
        // berarti uang yang tidak ada di laci.
        expect(makeForm({ closing: '140000' }).needsOwnerPin()).toBe(true);
        expect(makeForm({ closing: '190000' }).needsOwnerPin()).toBe(true);
    });

    it('stays quiet inside the threshold', () => {
        expect(makeForm({ closing: '165000' }).needsOwnerPin()).toBe(false);
        expect(makeForm({ closing: '155000' }).needsOwnerPin()).toBe(false);
    });

    it('stays quiet at exactly the threshold', () => {
        // 5.000 adalah "di dalam ambang", karena ambangnya sendiri yang
        // menyertai setiap pembulatan. 5.001 akan membuat
        // kasir yang menghitung satu koin kecewa tanpa penjelasan.
        expect(makeForm({ closing: '165000' }).needsOwnerPin()).toBe(false);
    });

    it('never warns an owner, whatever the difference is', () => {
        const form = shiftCloseForm({
            endpoint: ENDPOINT,
            redirectTo: REDIRECT,
            context: CONTEXT,
            expectedCash: EXPECTED,
            threshold: THRESHOLD,
            isOwner: true,
        });

        form.closing = '900000';

        expect(form.difference).toBe(740000);
        expect(form.needsOwnerPin()).toBe(false);
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

describe('shiftCloseForm and the Owner PIN', () => {
    const DIFFERENCE = 'Selisih Rp20.000: ada Rp180.000 di laci, seharusnya Rp160.000. Minta PIN Owner untuk melanjutkan.';

    it('opens the dialog when the server says the difference needs approval', async () => {
        const request = vi.fn(async () => ({ token: 'grant-1' }));
        const { state } = mount({
            pin: { request },
            responses: [validationFailure('pin_token', DIFFERENCE), jsonResponse(200, {})],
        });

        await state.submit({ preventDefault: vi.fn() });

        expect(request).toHaveBeenCalledTimes(1);
        expect(request.mock.calls[0][0].context).toBe(CONTEXT);
        // Kalimat yang muncul di dialog bukan kalimat generik: kasir perlu tahu
        // selisihnya berapa sebelum bothered Guillotine Owner.
        expect(request.mock.calls[0][0].description).toBe(DIFFERENCE);
    });

    it('resends the form exactly once with the token it was given', async () => {
        const request = vi.fn(async () => ({ token: 'grant-1' }));
        const { state, calls } = mount({
            pin: { request },
            responses: [validationFailure('pin_token', DIFFERENCE), jsonResponse(200, {})],
        });

        await state.submit({ preventDefault: vi.fn() });

        expect(calls).toHaveLength(2);
        expect(calls[1].init.body.get('pin_token')).toBe('grant-1');
    });

    it('never opens a second dialog after the owner refuses once', async () => {
        // Dialog yang terbuka sendiri di depan orang lain adalah cara paling
        // cepat membuat kasir menyalahkan Ownernya.
        const request = vi.fn(async () => ({ token: 'grant-1' }));
        const { state, calls } = mount({
            pin: { request },
            responses: [validationFailure('pin_token', DIFFERENCE), validationFailure('pin_token', 'PIN salah.')],
        });

        await state.submit({ preventDefault: vi.fn() });

        expect(request).toHaveBeenCalledTimes(1);
        expect(calls).toHaveLength(2);
        expect(state.error).toBe('PIN salah.');
    });

    it('keeps the counted money on screen when the owner cancels', async () => {
        const request = vi.fn(async () => null);
        const { state, element } = mount({
            pin: { request },
            responses: [validationFailure('pin_token', DIFFERENCE)],
        });

        await state.submit({ preventDefault: vi.fn() });

        expect(state.error).toBe(DIFFERENCE);
        // Token percobaan yang gagal dibuang, tapi angka yang sudah dihitung
        // kasir tetap di field: memaksa hitung ulang adalah cara memastikan
        // angkanya akan berbeda.
        expect(state.token).toBe('');
        expect(element.querySelector('[name="closing_cash"]').value).toBe('162000');
    });

    it('does not ask for a PIN when the failure was something else', async () => {
        const request = vi.fn();
        const { state } = mount({
            pin: { request },
            responses: [validationFailure('closing_cash', 'Uang penutup harus angka bulat rupiah.')],
        });

        await state.submit({ preventDefault: vi.fn() });

        expect(request).not.toHaveBeenCalled();
        expect(state.error).toBe('Uang penutup harus angka bulat rupiah.');
    });

    it('reports a server failure in words rather than showing nothing', async () => {
        const { state } = mount({ responses: [jsonResponse(500, {})] });

        await state.submit({ preventDefault: vi.fn() });

        expect(state.error).toBeTruthy();
    });

    it('survives a response that is not JSON at all', async () => {
        // 502 dari proxy danMaintenance mode keduanya kembali HTML. Komponen ini
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
            pin: { request: vi.fn(async () => null) },
            responses: [validationFailure('pin_token', DIFFERENCE)],
        });

        state.closing = '162000';
        await state.submit({ preventDefault: vi.fn() });

        expect(state.busy).toBe(false);
        expect(state.canSubmit()).toBe(true);
        // Dan angkanya masih ada, supaya percobaan kedua tidak dimulai dari nol.
        expect(state.closing).toBe('162000');
    });
});
