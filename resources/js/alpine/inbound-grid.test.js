import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

import { inboundGrid } from './inbound-grid.js';

const CONSIGNOR = {
    7: {
        name: 'Budi',
        schemeType: 'PERCENTAGE',
        scheme_rate: 20,
        scheme_amount: null,
        discountPolicy: 'STORE_BEARS',
    },
};

const PRICES = { 3: 50_000 };

function grid({ isOwner = false, consignorId = '7', pinToken = '' } = {}) {
    const component = inboundGrid({
        consignors: CONSIGNOR,
        productPrices: PRICES,
        isOwner,
        pinToken,
    });

    // Alpine memanggil `init()`-nya sendiri; di sini komponen dipakai langsung
    // sebagai objek biasa, jadi tidak ada yang menginisialisasinya.
    component.consignorId = consignorId;

    return component;
}

/**
 * `localStorage` untuk test cermin draft.
 *
 * Dipasang lewat `defineProperty` karena di environment test properti `window`
 * hanya bisa dibaca: penugasan biasa akan dibuang diam-diam, dan testnya akan
 * hijau karena cermin lokal tidak pernah benar-benar tersimpan.
 */
function installLocalStorage() {
    const store = new Map();

    Object.defineProperty(window, 'localStorage', {
        configurable: true,
        writable: true,
        value: {
            getItem: (key) => (store.has(key) ? store.get(key) : null),
            setItem: (key, value) => store.set(key, String(value)),
            removeItem: (key) => store.delete(key),
            clear: () => store.clear(),
        },
    });
}

function removeLocalStorage() {
    delete window.localStorage;
}

function row(overrides = {}) {
    return { product_id: '3', qty: 2, card_condition: 'MINT', blister_condition: 'CLEAR', rack_id: '', list_price: '', scheme_type: '', scheme_rate: '', scheme_amount: '', discount_policy: '', ...overrides };
}

describe('inboundGrid', () => {
    let component;

    beforeEach(() => {
        component = grid();
        component.rows = [];
    });

    describe('effective terms', () => {
        it('falls back to the consignor contract and the product price', () => {
            const effective = component.effective(row());

            expect(effective).toMatchObject({
                listPrice: 50_000,
                schemeType: 'PERCENTAGE',
                schemeRate: 20,
                schemeAmount: null,
                discountPolicy: 'STORE_BEARS',
            });
        });

        it('has no price at all before a product is chosen', () => {
            // Angka nol di sini akan terbaca as "this item is worth nothing" and would
            // flow into the preview; null says "not known yet".
            expect(component.priceFor(row({ product_id: '' }))).toBeNull();
        });

        it('does not carry a rate into a flat scheme', () => {
            const effective = component.effective(row({ scheme_type: 'FLAT', scheme_amount: 8_000, scheme_rate: 20 }));

            expect(effective.schemeRate).toBeNull();
            expect(effective.schemeAmount).toBe(8_000);
        });
    });

    describe('deviation', () => {
        it('is not a deviation to send nothing at all', () => {
            expect(component.deviates(row())).toBe(false);
        });

        it('is not a deviation to restate the consignor default', () => {
            expect(component.deviates(row({ scheme_type: 'PERCENTAGE', scheme_rate: '20' }))).toBe(false);
        });

        it('is a deviation to change the rate', () => {
            expect(component.deviates(row({ scheme_type: 'PERCENTAGE', scheme_rate: '5' }))).toBe(true);
        });

        it('is a deviation to change the scheme', () => {
            expect(component.deviates(row({ scheme_type: 'FLAT', scheme_amount: '8000' }))).toBe(true);
        });

        it('is a deviation to send a price at all', () => {
            expect(component.deviates(row({ list_price: '35.000' }))).toBe(true);
        });

        it('compares a rate as a number, not as a string', () => {
            // "20" dari form dan 20 dari database adalah skema yang sama. String
            // akan membandingkan keduanya dan meminta PIN untuk form yang isinya
            // persis sama dengan kontrak.
            expect(component.deviates(row({ scheme_type: 'PERCENTAGE', scheme_rate: 20 }))).toBe(false);
        });

        it('reports a deviation anywhere in the grid', () => {
            component.rows = [row(), row({ scheme_type: 'FLAT', scheme_amount: '8000' })];

            expect(component.hasDeviation()).toBe(true);
        });
    });

    describe('owner pin', () => {
        it('is not needed when nothing deviates', () => {
            component.rows = [row()];

            expect(component.needsOwnerPin()).toBe(false);
        });

        it('is needed for a staff override', () => {
            component.rows = [row({ scheme_type: 'FLAT', scheme_amount: '8000' })];

            expect(component.needsOwnerPin()).toBe(true);
        });

        it('is never needed by the owner, even for an override', () => {
            const owner = grid({ isOwner: true });
            owner.rows = [row({ scheme_type: 'FLAT', scheme_amount: '8000' })];

            expect(owner.needsOwnerPin()).toBe(false);
        });
    });

    describe('requesting a pin at commit', () => {
        it('asks once and keeps the token for a resubmit', async () => {
            const request = vi.fn().mockResolvedValue({ token: 'tok-1' });
            globalThis.window = { pin: { request } };

            component.rows = [row({ scheme_type: 'FLAT', scheme_amount: '8000' })];

            await expect(component.requestPinAndSubmit()).resolves.toBe(true);
            expect(component.pinToken).toBe('tok-1');

            // The second pass is the POST that came back with a validation error.
            // Reusing the token is the whole point: the cashier is not asked to
            // type a PIN again for a form they have not changed the terms of.
            await expect(component.requestPinAndSubmit()).resolves.toBe(true);
            expect(request).toHaveBeenCalledTimes(1);
        });

        it('sends nothing when the dialog is cancelled', async () => {
            globalThis.window = { pin: { request: vi.fn().mockResolvedValue(null) } };

            component.rows = [row({ scheme_type: 'FLAT', scheme_amount: '8000' })];

            await expect(component.requestPinAndSubmit()).resolves.toBe(false);
            expect(component.pinToken).toBe('');
        });

        it('does not re-prompt for a form the server rejected over something else', async () => {
            // The page is reloaded after a validation failure, carrying the token
            // back with it. That token is still good, so the second attempt is the
            // first thing that should open a dialog -- and it should not.
            const request = vi.fn();
            globalThis.window = { pin: { request } };

            const rejected = grid({ pinToken: 'tok-kept' });
            rejected.rows = [row({ scheme_type: 'FLAT', scheme_amount: '8000' })];

            const form = { submit: vi.fn() };
            await rejected.onSubmit({ target: form });

            expect(request).not.toHaveBeenCalled();
            expect(rejected.pinToken).toBe('tok-kept');
            expect(form.submit).toHaveBeenCalledTimes(1);
        });

        it('does not open a dialog for a commit that needs no pin', async () => {
            const request = vi.fn();
            globalThis.window = { pin: { request } };

            component.rows = [row()];

            await expect(component.requestPinAndSubmit()).resolves.toBe(true);
            expect(request).not.toHaveBeenCalled();
        });
    });

    describe('submit', () => {
        it('posts without a dialog when no row deviates', async () => {
            const request = vi.fn();
            globalThis.window = { pin: { request } };
            component.rows = [row()];

            const form = { submit: vi.fn() };
            await component.onSubmit({ target: form });

            expect(request).not.toHaveBeenCalled();
            expect(form.submit).toHaveBeenCalledTimes(1);
        });

        it('asks once and then posts, even though it is called twice', async () => {
            // The second call is the browser re-entering submit because the first
            // one reset a reactive field. Two dialogs for one click is the exact
            // failure this flow exists to prevent.
            const request = vi.fn().mockResolvedValue({ token: 'tok-1' });
            globalThis.window = { pin: { request } };
            component.rows = [row({ scheme_type: 'FLAT', scheme_amount: '8000' })];

            const form = { submit: vi.fn() };
            await component.onSubmit({ target: form });
            await component.onSubmit({ target: form });

            expect(request).toHaveBeenCalledTimes(1);
            expect(form.submit).toHaveBeenCalledTimes(1);
        });

        it('does not post when the dialog is cancelled', async () => {
            globalThis.window = { pin: { request: vi.fn().mockResolvedValue(null) } };
            component.rows = [row({ scheme_type: 'FLAT', scheme_amount: '8000' })];

            const form = { submit: vi.fn() };
            await component.onSubmit({ target: form });

            expect(form.submit).not.toHaveBeenCalled();
            expect(component.submitting).toBe(false);
        });
    });

    describe('restoring a rejected form', () => {
        it('brings the rows back after a validation failure', () => {
            // The server returns the items it received; dropping them would make
            // the cashier retype the whole document over one bad cell.
            const restored = grid();
            restored.rows = [];

            const reloaded = inboundGrid({
                consignors: CONSIGNOR,
                productPrices: PRICES,
                rows: [{ product_id: '3', qty: '2', scheme_type: 'FLAT', scheme_amount: '8000' }],
            });

            expect(reloaded.rows[0]).toMatchObject({ product_id: '3', qty: '2', scheme_type: 'FLAT' });
            // Fields the server never received come back as the row default, not
            // as undefined, so the markup does not render "undefined" in an input.
            expect(reloaded.rows[0].rack_id).toBe('');
        });
    });

    describe('variance', () => {
        it('is zero while the claim box is off, even if a number was typed before', () => {
            component.claimedEnabled = false;
            component.claimedQty = 10;
            component.rows = [row({ qty: 8 })];

            // Angka lama tidak boleh ikut dihitung: checkbox yang dimatikan
            // berarti tidak ada klaim, dan selisih harus kembali ke nol.
            expect(component.variance()).toBe(0);
        });

        it('counts what the consignor claimed against what was counted', () => {
            component.claimedEnabled = true;
            component.claimedQty = 10;
            component.rows = [row({ qty: 6 }), row({ qty: 2 })];

            expect(component.variance()).toBe(2);
        });

        it('goes negative when more arrived than the consignor claimed', () => {
            component.claimedEnabled = true;
            component.claimedQty = 5;
            component.rows = [row({ qty: 8 })];

            expect(component.variance()).toBe(-3);
        });

        it('treats an empty claim as no claim', () => {
            component.claimedEnabled = true;
            component.claimedQty = '';
            component.rows = [row({ qty: 4 })];

            expect(component.variance()).toBe(0);
        });
    });

    describe('draft autosave', () => {
        beforeEach(() => {
            vi.useFakeTimers();

            installLocalStorage();
            global.fetch = vi.fn(async () => new Response(JSON.stringify({ draft: { draft_id: 'draft-1', saved_at: '2026-09-29T10:00:00+07:00' } }), { status: 200 }));
        });

        afterEach(() => {
            vi.useRealTimers();
            delete global.fetch;
            removeLocalStorage();
        });

        it('does not save a form nobody has touched', async () => {
            // Staff yang membuka halaman lalu menunggu tidak boleh
            // meninggalkan draft kosong di server.
            component.scheduleSave();
            await vi.advanceTimersByTimeAsync(10_000);

            expect(global.fetch).not.toHaveBeenCalled();
        });

        it('creates a draft the first time there is something to save', async () => {
            component.rows = [row({ qty: 3 })];

            component.scheduleSave();
            await vi.advanceTimersByTimeAsync(10_000);

            expect(global.fetch).toHaveBeenCalledTimes(1);
            expect(global.fetch.mock.calls[0][0]).toBe('/inbound/consignment-in/drafts');
            expect(global.fetch.mock.calls[0][1].method).toBe('POST');
            expect(component.draftId).toBe('draft-1');
        });

        it('patches the same draft on every later save', async () => {
            component.rows = [row({ qty: 3 })];
            component.scheduleSave();
            await vi.advanceTimersByTimeAsync(10_000);

            component.rows = [row({ qty: 4 })];
            component.scheduleSave();
            await vi.advanceTimersByTimeAsync(10_000);

            expect(global.fetch).toHaveBeenCalledTimes(2);
            expect(global.fetch.mock.calls[1][0]).toBe('/inbound/consignment-in/drafts/draft-1');
            expect(global.fetch.mock.calls[1][1].method).toBe('PATCH');
        });

        it('waits for the typing to stop instead of saving on every keystroke', async () => {
            component.rows = [row({ qty: 3 })];

            component.scheduleSave();
            await vi.advanceTimersByTimeAsync(9_000);
            component.rows = [row({ qty: 4 })];
            component.scheduleSave();
            await vi.advanceTimersByTimeAsync(9_000);

            expect(global.fetch).not.toHaveBeenCalled();

            await vi.advanceTimersByTimeAsync(1_500);

            expect(global.fetch).toHaveBeenCalledTimes(1);
        });

        it('never sends a half-filled line', async () => {
            component.rows = [row({ qty: 3 }), { ...row(), product_id: '' }];

            component.scheduleSave();
            await vi.advanceTimersByTimeAsync(10_000);

            const body = JSON.parse(global.fetch.mock.calls[0][1].body);

            expect(body.items).toHaveLength(1);
            expect(body.items[0].qty).toBe(3);
        });

        it('stops saving when the server says the document is already committed', async () => {
            global.fetch = vi.fn(async () => new Response(JSON.stringify({ message: 'sudah di-commit' }), { status: 409 }));

            component.rows = [row({ qty: 3 })];
            component.scheduleSave();
            await vi.advanceTimersByTimeAsync(10_000);

            expect(component.saveState).toBe('locked');

            component.rows = [row({ qty: 4 })];
            component.scheduleSave();
            await vi.advanceTimersByTimeAsync(10_000);

            // Menimpa dokumen yang sudah jadi SKU akan mengarang barang yang
            // sudah pernah diterima, jadi autosave harus benar-benar berhenti.
            expect(global.fetch).toHaveBeenCalledTimes(1);
        });

        it('keeps the local copy when the server is unreachable', async () => {
            global.fetch = vi.fn(async () => { throw new Error('offline'); });

            component.rows = [row({ qty: 3 })];
            component.scheduleSave();
            await vi.advanceTimersByTimeAsync(10_000);

            // Autosave yang gagal tidak boleh menggagalkan commit, tapi isinya
            // tetap harus ada di perangkat ini -- kalau tidak, satu outage
            //_network berarti seluruh pekerjaan Staff hilang.
            expect(component.saveState).toBe('offline');
            expect(window.localStorage.getItem('wms.consignment-in.draft.v1')).toContain('"qty":3');
        });

        it('lets the server win over the stale local copy after a rejected commit', () => {
            // Cermin lokal berasal dari sebelum commit ditolak, jadi isinya bisa
            // saja tidak sama dengan yang sudah dikoreksi server. `old()` yang
            // jadi sumber -- kalau cermin lokal menang, perbaikan Staff hilang.
            window.localStorage.setItem('wms.consignment-in.draft.v1', JSON.stringify({
                draftId: 'draft-9',
                consignorId: '7',
                rows: [row({ qty: 1 })],
            }));

            const reloaded = inboundGrid({
                consignors: CONSIGNOR,
                productPrices: PRICES,
                rows: [row({ qty: 4 })],
                restorable: false,
            });

            reloaded.init();

            expect(reloaded.rows[0].qty).toBe(4);
            expect(reloaded.draftId).toBe('');
        });

        it('restores the local copy when the form was not returned by a rejection', () => {
            window.localStorage.setItem('wms.consignment-in.draft.v1', JSON.stringify({
                draftId: 'draft-9',
                consignorId: '7',
                rows: [row({ qty: 1 })],
            }));

            const reloaded = inboundGrid({ consignors: CONSIGNOR, productPrices: PRICES, restorable: true });

            reloaded.init();

            // Draft yang hilang karena tab tertutup adalah masalah yang harus
            // diselesaikan -- bukan dihapus karena sudah lewat 10 detik.
            expect(reloaded.rows[0].qty).toBe(1);
            expect(reloaded.draftId).toBe('draft-9');
        });
    });

    describe('grid editing', () => {
        it('removes a row even when it is the last one', () => {
            // Produk hanya masuk lewat popup, jadi grid kosong adalah keadaan
            // yang sah -- menyisakan baris kosong tanpa cara mengisinya hanya
            // menyisakan baris yang tidak bisa dipakai.
            component.rows = [row()];

            component.removeRow(0);

            expect(component.rows).toHaveLength(0);
        });

        it('splits a row into a copy that has to be re-entered', () => {
            component.rows = [row({ qty: 10, scheme_type: 'PERCENTAGE', scheme_rate: 20 })];

            component.splitRow(0);

            expect(component.rows).toHaveLength(2);
            expect(component.rows[1]).toMatchObject({ qty: 1, scheme_type: 'PERCENTAGE', scheme_rate: 20 });
        });
    });

    describe('product picker', () => {
        it('appends the chosen product as a new row and closes the popup', () => {
            component.pickerOpen = true;

            component.onProductPicked({
                detail: {
                    item: {
                        product_id: 3,
                        name: 'Toyota Supra',
                        casting_code: 'HWX-42',
                    },
                },
            });

            expect(component.rows).toHaveLength(1);
            expect(component.rows[0]).toMatchObject({
                product_id: 3,
                product_name: 'Toyota Supra',
                casting_code: 'HWX-42',
                qty: 1,
                card_condition: 'MINT',
            });
            expect(component.pickerOpen).toBe(false);
        });

        it('lets the same product be added twice', () => {
            // `splitRow` memecah satu produk ke beberapa baris dengan skema
            // berbeda, jadi menolak produk yang sudah ada akan mematikan alur
            // itu. Berbeda dari Stock In Pribadi, duplikat di sini sah.
            component.onProductPicked({ detail: { item: { product_id: 3, name: 'Toyota Supra' } } });
            component.onProductPicked({ detail: { item: { product_id: 3, name: 'Toyota Supra' } } });

            expect(component.rows).toHaveLength(2);
        });

        it('does nothing when the payload carries no product_id', () => {
            // Picker kasir lama tidak memuat `product_id`; tanpa guard ini baris
            // tanpa primary key masuk grid dan ditolak server sebagai baris
            // kosong. Popup juga harus tetap terbuka -- guard pulang sebelum
            // `closePicker`.
            component.pickerOpen = true;

            component.onProductPicked({ detail: { item: { name: 'Toyota Supra' } } });

            expect(component.rows).toHaveLength(0);
            expect(component.pickerOpen).toBe(true);
        });

        it('reads the product straight off the detail when there is no item wrapper', () => {
            component.onProductPicked({ detail: { product_id: 3, name: 'Toyota Supra', casting_code: 'HWX-42' } });

            expect(component.rows[0]).toMatchObject({ product_id: 3, product_name: 'Toyota Supra' });
        });
    });
});
