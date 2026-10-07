import { describe, it, expect, vi, afterEach } from 'vitest';
import { pinDialog, pinDialogForm } from './pin-dialog';

/**
 * Berdiri Berdiri untuk `notify.templateModal`.
 *
 * Dialog sungguhan hanya mengembalikan nilai dari `preConfirm` kalau tombol
 * konfirmasi ditekan, dan `request` mem-normalisasi itu jadi `grant | null`.
 * Jadi dua hal yang perlu terlihat disimpan terpisah: apa yang dikembalikan ke
 * pemanggil `request`, dan apa yang dikembalikan `preConfirm` ke SweetAlert2 --
 * yang kedua itulah yang menahan dialog tetap terbuka saat PIN ditolak.
 */
function fakeNotify() {
    const calls = [];
    const state = { popup: null, answer: undefined, run: false, preConfirmResult: undefined };

    return {
        calls,
        state,
        // `async`, dan preConfirm di-awaited-nya, karena `exchange` memang
        // async: SweetAlert2 menahan dialog di belakang spinner selama
        // promise itu berjalan, lalu resolve dengan nilainya. Stub sinkron akan
        // mengembalikan promise-nya sendiri, dan setiap test yang mengharapkan
        // grant akan melihat `null`.
        async templateModal(id, options) {
            calls.push({ id, options });

            if (!state.run) {
                // Ditekan atau ditutup tanpa menjawab: preConfirm tidak pernah jalan.
                return false;
            }

            // SweetAlert2 memanggil preConfirm dengan dirinya sebagai receiver,
            // dan receiver itu yang tahu popup yang aktif.
            state.preConfirmResult = await options.onConfirm.call({
                getPopup: () => state.popup,
            });

            return state.preConfirmResult;
        },
    };
}

/**
 * `Alpine.$data` yang produksi mencari scope di dalam subtree. Di sini scope
 * di-binding ke elemennya secara langsung lewat stub, jadi yang diuji adalah
 * pertukaran antara form dan helper, bukan pencarian scope-nya.
 */
function fakeAlpine() {
    const scopes = new WeakMap();

    return {
        $data(el) {
            return scopes.get(el);
        },
        bind(el, scope) {
            scopes.set(el, scope);

            return scope;
        },
    };
}

function mount({ answer = undefined, run = true } = {}) {
    const notify = fakeNotify();
    const Alpine = fakeAlpine();
    // Popup sungguhan adalah container SweetAlert2; form ada di dalamnya.
    // `querySelector` mencari descendant saja, jadi yang di-binding ke scope
    // harus anak dari popup, bukan popup itu sendiri.
    const popup = document.createElement('div');
    const element = document.createElement('form');

    element.setAttribute('data-pin-form', '');
    popup.appendChild(element);
    document.body.appendChild(popup);

    const form = Alpine.bind(element, pinDialogForm());

    notify.state.popup = popup;
    notify.state.answer = answer;
    notify.state.run = run;

    return {
        dialog: pinDialog({ notify, Alpine, url: '/pin/verify' }),
        notify,
        form,
        element,
    };
}

function respondWith(body, status = 200) {
    return vi.fn().mockResolvedValue({
        ok: status >= 200 && status < 300,
        status,
        json: () => Promise.resolve(body),
    });
}

const grant = {
    message: 'PIN Owner diterima.',
    token: 'token-abc',
    expires_at: '2026-09-29T10:05:00+07:00',
    context: 'consignment.scheme-override',
    owner: 'Budi Santoso',
};

afterEach(() => {
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('pinDialogForm', () => {
    it('keeps only digits as they are typed', () => {
        const form = pinDialogForm();

        expect(form.isComplete).toBe(false);

        form.onInput({ target: { value: '123' } });

        expect(form.pin).toBe('123');
        expect(form.isComplete).toBe(false);

        form.onInput({ target: { value: '123456' } });

        expect(form.pin).toBe('123456');
        expect(form.isComplete).toBe(true);
    });

    it('strips a character that is not a digit out of the field itself', () => {
        const form = pinDialogForm();
        const target = { value: '12a34' };

        form.onInput({ target });

        // The field is corrected in the DOM and not only in state, because the
        // browser's own value is what the next keystroke appends to.
        expect(target.value).toBe('1234');
        expect(form.pin).toBe('1234');
    });

    it('clears a reason that belonged to an earlier value', () => {
        const form = pinDialogForm();

        form.error = 'PIN Owner salah.';
        form.onInput({ target: { value: '1' } });

        expect(form.error).toBe('');
    });

    it('starts clean again after a reset', () => {
        const form = pinDialogForm();

        form.pin = '123456';
        form.error = 'PIN Owner salah.';
        form.reset();

        expect(form.pin).toBe('');
        expect(form.error).toBe('');
    });
});

describe('pinDialog.request', () => {
    it('opens the template the layout ships, and nothing else', async () => {
        vi.stubGlobal('fetch', respondWith(grant));
        const { dialog, notify } = mount({ answer: grant });

        await dialog.request({ context: 'consignment.scheme-override' });

        expect(notify.calls).toHaveLength(1);
        expect(notify.calls[0].id).toBe('pin-dialog');
    });

    it('shows the action in the dialog title and description', async () => {
        vi.stubGlobal('fetch', respondWith(grant));
        const { dialog, notify } = mount({ answer: grant });

        await dialog.request({
            context: 'label.print-over-qty',
            title: 'Cetak label melebihi qty',
            description: 'Cetak 3 dari 2 unit. Minta PIN Owner.',
        });

        const { options } = notify.calls[0];

        expect(options.title).toBe('Cetak label melebihi qty');
        expect(options.description).toContain('Cetak 3 dari 2 unit');
    });

    it('returns the grant the server issued', async () => {
        vi.stubGlobal('fetch', respondWith(grant));
        const { dialog, form } = mount({ answer: grant });

        form.pin = '123456';

        expect(await dialog.request({ context: 'consignment.scheme-override' })).toEqual({
            token: 'token-abc',
            expiresAt: '2026-09-29T10:05:00+07:00',
            owner: 'Budi Santoso',
            context: 'consignment.scheme-override',
        });
    });

    it('returns null when the dialog was dismissed', async () => {
        const { dialog } = mount({ run: false });

        // Dismissal resolves to something that is not a grant, and must not read
        // as an authorisation that happened.
        expect(await dialog.request({ context: 'x' })).toBeNull();
    });

    it('returns null when the dialog resolves to something that is not a grant', async () => {
        vi.stubGlobal('fetch', respondWith(grant));
        const { dialog, notify } = mount({ answer: grant });

        // Yang diuji bukan nilai yang-sustaining itu, tapi bahwa `request` membaca
        // `token` secara khusus dan bukan sekadar "?": dialog yang tertutup tanpa
        // token harus terbaca sebagai tidak ada otorisasi.
        notify.templateModal = () => Promise.resolve({ message: 'ok' });

        expect(await dialog.request({ context: 'x' })).toBeNull();
    });

    it('falls back to the same default the server falls back to', async () => {
        const fetchMock = respondWith(grant);
        vi.stubGlobal('fetch', fetchMock);
        const { dialog, form } = mount({ answer: grant });

        form.pin = '123456';

        // A caller that forgets to name an action gets `global`, which is what
        // `VerifyPinRequest` defaults to. Sending `undefined` instead would bind
        // the grant to the literal string "undefined" and hand the form a context
        // no request rule would ever match -- a token that is valid and useless
        // at the same time.
        await dialog.request({});

        const [, init] = fetchMock.mock.calls[0];

        expect(new URLSearchParams(init.body).get('context')).toBe('global');
    });

    it('reports a context that is not a usable string instead of sending it', async () => {
        const fetchMock = respondWith(grant);
        vi.stubGlobal('fetch', fetchMock);
        const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
        const { dialog, form } = mount({ answer: grant });

        form.pin = '123456';

        await dialog.request({ context: '   ' });

        const [, init] = fetchMock.mock.calls[0];

        // A blank scope is a caller's bug, so it is logged as one -- but the
        // dialog still opens and still falls back, because the cashier standing
        // in front of the printer cannot fix a string literal.
        expect(new URLSearchParams(init.body).get('context')).toBe('global');
        expect(errorSpy).toHaveBeenCalledWith(
            'pinDialog: context must be a non-empty string',
            '   ',
        );

        errorSpy.mockRestore();
    });

    it('sends the PIN and the action it is being asked for', async () => {
        const fetchMock = respondWith(grant);
        vi.stubGlobal('fetch', fetchMock);
        const { dialog, form } = mount({ answer: grant });

        form.pin = '123456';

        await dialog.request({ context: 'label.print-over-qty' });

        const [, init] = fetchMock.mock.calls[0];

        expect(init.method).toBe('POST');

        const body = new URLSearchParams(init.body);

        expect(body.get('pin')).toBe('123456');
        expect(body.get('context')).toBe('label.print-over-qty');
    });

    it('refuses a PIN that is not six digits without asking the server', async () => {
        const fetchMock = respondWith(grant);
        vi.stubGlobal('fetch', fetchMock);
        const { dialog, form } = mount({ answer: false });

        form.pin = '123';

        await dialog.request({ context: 'x' });

        expect(fetchMock).not.toHaveBeenCalled();
        expect(form.error).toContain('6 digit');
    });

    it('holds the dialog open with a reason when the PIN is refused', async () => {
        const fetchMock = respondWith(
            { message: 'PIN Owner salah.', errors: { pin: ['PIN Owner salah.'] } },
            422,
        );
        vi.stubGlobal('fetch', fetchMock);
        const { dialog, notify, form } = mount({ answer: false });

        form.pin = '999999';

        await dialog.request({ context: 'x' });

        // `false` is the shared helper's "the work did not happen" answer, and
        // it is what keeps the dialog up so the cashier can type a second PIN in
        // the same place instead of starting over.
        expect(await dialog.request({ context: 'x' })).toBeNull();
        expect(notify.state.preConfirmResult).toBe(false);
        expect(form.error).toBe('PIN Owner salah.');
    });

    it('passes the server sentence through, so "no PIN set" is not read as "wrong PIN"', async () => {
        const fetchMock = respondWith(
            { errors: { pin: ['Belum ada Owner yang mengatur PIN.'] } },
            422,
        );
        vi.stubGlobal('fetch', fetchMock);
        const { dialog, form } = mount({ answer: false });

        form.pin = '123456';

        await dialog.request({ context: 'x' });

        expect(form.error).toContain('Belum ada Owner');
        expect(form.error).not.toContain('salah');
    });

    it('says the network failed rather than blaming the PIN', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
        const { dialog, form } = mount({ answer: false });

        form.pin = '123456';

        await dialog.request({ context: 'x' });

        // A dead network and a wrong PIN send the cashier to two different places
        // to fix something, so the message has to say which.
        expect(form.error).toContain('server');
        expect(form.error).not.toContain('salah');
    });

    it('reads a non-JSON failure as a refusal rather than throwing', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: false,
            status: 500,
            json: () => Promise.reject(new Error('not json')),
        }));
        const { dialog, form } = mount({ answer: false });

        form.pin = '123456';

        await dialog.request({ context: 'x' });

        expect(form.error).not.toBe('');
    });

    it('turns a throttled response into a reason about waiting', async () => {
        const fetchMock = respondWith({ message: 'Too Many Attempts.' }, 429);
        vi.stubGlobal('fetch', fetchMock);
        const { dialog, form } = mount({ answer: false });

        form.pin = '123456';

        await dialog.request({ context: 'x' });

        expect(form.error).toContain('satu menit');
    });

    it('clears the field once a token has been issued', async () => {
        const fetchMock = respondWith(grant);
        vi.stubGlobal('fetch', fetchMock);
        const { dialog, form } = mount({ answer: grant });

        form.pin = '123456';

        await dialog.request({ context: 'x' });

        // A PIN left in a field is a PIN left on screen for the next reader of
        // that terminal, and for the next dialog that opens on the same page.
        expect(form.pin).toBe('');
    });

    it('reports a missing dialog helper instead of opening nothing', async () => {
        const dialog = pinDialog({ notify: undefined, Alpine: fakeAlpine() });
        const spy = vi.spyOn(console, 'error').mockImplementation(() => {});

        expect(await dialog.request({ context: 'x' })).toBeNull();
        expect(spy).toHaveBeenCalled();
    });

    it('reports a body it cannot find instead of reporting a wrong PIN', async () => {
        const { dialog, notify, form } = mount({ answer: false });
        const spy = vi.spyOn(console, 'warn').mockImplementation(() => {});

        form.pin = '123456';

        // The layout lost its template. Saying "wrong PIN" here would send the
        // cashier to type a different one forever.
        notify.state.popup = document.createElement('div');

        await dialog.request({ context: 'x' });

        expect(form.error).toBe('');
        expect(spy).toHaveBeenCalled();
    });
});
