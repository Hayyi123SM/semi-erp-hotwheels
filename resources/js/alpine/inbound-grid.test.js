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

function grid({ consignorId = '7' } = {}) {
    const component = inboundGrid({
        consignors: CONSIGNOR,
        productPrices: PRICES,
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

/**
 * Dialog konfirmasi dipakai lewat `window.notify.dangerConfirm`, helper yang
 * sama dengan tombol hapus di tabel lain. Test yang menyentuh `remove()` atau
 * `clearConfirmed()` harus memasangnya, karena tanpa helper konfirmasi keduanya
 * memang sengaja tidak melakukan apa-apa.
 *
 * `onSideEffect` dipakai test yang membutuhkan grid berubah selagi dialog
 * masih terbuka.
 */
function stubDangerConfirm(confirmed, onSideEffect = null) {
    const dangerConfirm = vi.fn(async () => {
        if (onSideEffect) {
            onSideEffect();
        }

        return confirmed;
    });

    // `notify` ditempelkan ke `window` yang sudah ada, bukan menggantinya:
    // test draft memasang `localStorage` lewat `defineProperty` pada objek yang
    // sama, dan menimpanya utuh akan membuang pasangan itu.
    window.notify = { dangerConfirm };

    return dangerConfirm;
}

describe('inboundGrid', () => {
    let component;

    beforeEach(() => {
        component = grid();
        component.rows = [];
    });

    afterEach(() => {
        // `stubDangerConfirm` menempelkan `notify` ke `window` yang dipakai
        // bersama test lain; dibiarkan, test berikutnya bisa lolos karena
        // menemukan dialog yang dipasang test sebelumnya.
        if (window.notify) {
            delete window.notify;
        }
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
            // akan membandingkan keduanya dan melaporkan penyimpangan untuk form
            // yang isinya persis sama dengan kontrak.
            expect(component.deviates(row({ scheme_type: 'PERCENTAGE', scheme_rate: 20 }))).toBe(false);
        });

        it('reports a deviation anywhere in the grid', () => {
            component.rows = [row(), row({ scheme_type: 'FLAT', scheme_amount: '8000' })];

            expect(component.hasDeviation()).toBe(true);
        });
    });

    describe('submit', () => {
        it('posts directly when no row deviates', async () => {
            component.rows = [row()];

            const form = { submit: vi.fn() };
            await component.onSubmit({ target: form });

            expect(form.submit).toHaveBeenCalledTimes(1);
            expect(component.submitting).toBe(true);
        });

        it('posts directly even when a row deviates', async () => {
            // Modul ini hanya bisa dibuka Owner, jadi penyimpangan skema adalah
            // keputusan Owner sendiri -- tidak ada otorisasi kedua yang perlu
            // ditanyakan ke server.
            component.rows = [row({ scheme_type: 'FLAT', scheme_amount: '8000' })];

            const form = { submit: vi.fn() };
            await component.onSubmit({ target: form });

            expect(form.submit).toHaveBeenCalledTimes(1);
        });

        it('ignores a second submit while the first is still in flight', async () => {
            // The browser can re-enter submit while the first POST is still
            // navigating. Posting twice would create two documents.
            component.rows = [row()];

            const form = { submit: vi.fn() };
            await component.onSubmit({ target: form });
            await component.onSubmit({ target: form });

            expect(form.submit).toHaveBeenCalledTimes(1);
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
        it('removes a row after the operator confirms', async () => {
            // Produk hanya masuk lewat popup, jadi grid kosong adalah keadaan
            // yang sah -- menyisakan baris kosong tanpa cara mengisinya hanya
            // menyisakan baris yang tidak bisa dipakai.
            component.rows = [row({ qty: 3 })];
            const dangerConfirm = stubDangerConfirm(true);

            const removed = await component.remove(component.rows[0]);

            expect(removed).toBe(true);
            expect(component.rows).toHaveLength(0);
            expect(dangerConfirm).toHaveBeenCalledWith({
                title: 'Hapus dari daftar?',
                description: 'Produk — Qty 3',
                confirmText: 'Hapus',
                cancelText: 'Batal',
            });
        });

        it('keeps the row when the dialog is cancelled', async () => {
            component.rows = [row({ qty: 3 })];
            stubDangerConfirm(false);

            const removed = await component.remove(component.rows[0]);

            expect(removed).toBe(false);
            expect(component.rows).toHaveLength(1);
        });

        it('removes the row it was asked about after the grid shifted', async () => {
            // Sementara dialog terbuka, baris lain bisa hilang. Baris dicari
            // ulang lewat identitasnya setelah jawaban tiba, jadi baris yang
            // sekarang menempati indeksnya tidak ikut terhapus.
            const target = row({ product_id: '8', qty: 3 });
            component.rows = [row(), target];

            stubDangerConfirm(true, () => {
                component.rows.shift();
            });

            const removed = await component.remove(target);

            expect(removed).toBe(true);
            expect(component.rows).toHaveLength(0);
        });

        it('leaves the row alone when there is no confirmation helper', async () => {
            // Halaman yang tidak sanggup menawarkan pertanyaan tidak boleh
            // menghapus diam-diam.
            delete window.notify;

            component.rows = [row()];

            const removed = await component.remove(component.rows[0]);

            expect(removed).toBe(false);
            expect(component.rows).toHaveLength(1);
        });

        it('splits a row into a copy that has to be re-entered', () => {
            component.rows = [row({ qty: 10, scheme_type: 'PERCENTAGE', scheme_rate: 20 })];

            component.splitRow(0);

            expect(component.rows).toHaveLength(2);
            expect(component.rows[1]).toMatchObject({ qty: 1, scheme_type: 'PERCENTAGE', scheme_rate: 20 });
        });
    });

    describe('emptying the whole grid', () => {
        beforeEach(() => {
            installLocalStorage();
            global.fetch = vi.fn(async () => new Response('{}', { status: 200 }));
        });

        afterEach(() => {
            delete global.fetch;
            removeLocalStorage();
        });

        it('asks first and keeps the rows when the answer is no', async () => {
            component.rows = [row({ qty: 2 }), row({ qty: 3 })];
            const dangerConfirm = stubDangerConfirm(false);

            const cleared = await component.clearConfirmed();

            expect(cleared).toBe(false);
            expect(component.rows).toHaveLength(2);
            expect(dangerConfirm).toHaveBeenCalledWith({
                title: 'Kosongkan daftar?',
                description: '2 barang (total qty 5) akan dihapus dari daftar.',
                confirmText: 'Kosongkan',
                cancelText: 'Batal',
            });
        });

        it('empties the rows and forgets both copies of the draft', async () => {
            // Dua jejak sekaligus: draft sisa di server akan muncul lagi di
            // daftar "Lanjutkan", dan cermin lokal akan memulihkan baris yang
            // barusan dikosongkan pada muat ulang berikutnya.
            component.rows = [row({ qty: 3 })];
            component.draftId = 'draft-9';
            component.savedAt = '2026-09-29T10:00:00+07:00';
            window.localStorage.setItem('wms.consignment-in.draft.v1', JSON.stringify({ draftId: 'draft-9', rows: [row()] }));

            stubDangerConfirm(true);

            const cleared = await component.clearConfirmed();

            expect(cleared).toBe(true);
            expect(component.rows).toHaveLength(0);
            expect(component.draftId).toBe('');
            expect(component.saveState).toBe('idle');
            expect(window.localStorage.getItem('wms.consignment-in.draft.v1')).toBeNull();
            expect(global.fetch).toHaveBeenCalledTimes(1);
            expect(global.fetch.mock.calls[0][0]).toBe('/inbound/consignment-in/drafts/draft-9');
            expect(global.fetch.mock.calls[0][1].method).toBe('DELETE');
        });

        it('sends no request when the grid never reached the server', async () => {
            component.rows = [row({ qty: 3 })];

            stubDangerConfirm(true);

            const cleared = await component.clearConfirmed();

            expect(cleared).toBe(true);
            expect(component.rows).toHaveLength(0);
            expect(global.fetch).not.toHaveBeenCalled();
        });

        it('cancels the pending autosave so the cleared draft is not rewritten', async () => {
            vi.useFakeTimers();

            try {
                component.rows = [row({ qty: 3 })];
                component.draftId = 'draft-9';
                component.scheduleSave();

                stubDangerConfirm(true);
                await component.clearConfirmed();

                // Timer yang sudah dijadwalkan tidak melihat bahwa barisnya
                // sudah hilang, jadi ia harus benar-benar dibatalkan. Yang
                // tersisa hanya satu DELETE untuk membuang draftnya -- tidak
                // ada PATCH yang menulis ulang grid kosong.
                await vi.advanceTimersByTimeAsync(10_000);

                expect(component.saveTimer).toBeNull();
                expect(global.fetch).toHaveBeenCalledTimes(1);
                expect(global.fetch.mock.calls[0][1].method).toBe('DELETE');
            } finally {
                vi.useRealTimers();
            }
        });

        it('does nothing when the grid is already empty', async () => {
            component.rows = [];
            const dangerConfirm = stubDangerConfirm(true);

            const cleared = await component.clearConfirmed();

            expect(cleared).toBe(false);
            expect(dangerConfirm).not.toHaveBeenCalled();
        });

        it('leaves the rows alone when there is no confirmation helper', async () => {
            delete window.notify;

            component.rows = [row()];

            const cleared = await component.clearConfirmed();

            expect(cleared).toBe(false);
            expect(component.rows).toHaveLength(1);
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
