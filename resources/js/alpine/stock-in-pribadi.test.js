import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { stockInPribadiForm } from "./stock-in-pribadi";

/**
 * Form ini diuji dengan scope seadanya -- tanpa Alpine sungguhan -- karena
 * yang diuji adalah keputusan yang diambil dari payload server dan pilihan
 * pengguna: produk mana yang masuk daftar, apa yang terjadi ketika sudah ada,
 * dan kapan popup ditutup.
 *
 * `$nextTick`, `$refs`, dan `$root` disalin apa adanya seperti yang diberikan
 * Alpine -- terutama `$root.querySelector`, yang dipakai highlight untuk
 * menggulir ke baris duplikat. Kalau diganti stub yang tidak benar-benar
 * mencari elemen, test akan lulus sementara highlight di halaman tidak
 * menemukan baris apa pun.
 *
 * `dataset` juga disediakan, karena endpoint draft dibaca dari `data-draft-*`
 * pada root yang sama. Tanpa `dataset`, getter draft mengembalikan string
 * kosong yang berarti "halaman ini tidak memakai draft".
 */
function mount({ initialItems = [], dataset = null, restorable = undefined } = {}) {
    const form = stockInPribadiForm({
        lookupUrl: "/inbound/produk/cari",
        racks: [],
        cardConditions: { MINT: "MINT" },
        blisterConditions: { CLEAR: "CLEAR" },
        initialItems,
        restorable,
    });

    const rows = [];

    form.$nextTick = (fn) => fn();
    form.$refs = { scanInput: { focus: vi.fn() } };
    form.$root = {
        dataset: dataset ?? {},
        querySelector: (selector) => {
            const match = selector.match(/\[data-row-idx="(\d+)"\]/);

            if (!match) {
                return null;
            }

            return rows[Number(match[1])] ?? null;
        },
    };

    // `init()` sekarang menunggu pemulihan draft, jadi janjinya dikembalikan
    // supaya test yang peduli dengan urutannya bisa menunggu.
    const initialized = form.init();

    return { form, rows, initialized };
}

function stubFetch(items, ok = true) {
    return vi.fn(async () => ({
        ok,
        json: async () => ({ items }),
    }));
}

/**
 * Tombol hapus baris bertanya lewat `window.notify.dangerConfirm` -- helper
 * yang sama dengan tombol hapus di tabel lain. Test yang menyentuh `remove()`
 * harus memasangnya, karena tanpa helper konfirmasi barisnya memang sengaja
 * tidak dihapus.
 *
 * `onSideEffect` dipakai test yang membutuhkan daftar berubah selagi dialog
 * masih terbuka.
 */
function stubDangerConfirm(confirmed, onSideEffect = null) {
    const dangerConfirm = vi.fn(async () => {
        if (onSideEffect) {
            onSideEffect();
        }

        return confirmed;
    });

    window.notify = { dangerConfirm };

    return dangerConfirm;
}

beforeEach(() => {
    document.head.innerHTML =
        '<meta name="csrf-token" content="test-token">';
});

afterEach(() => {
    vi.restoreAllMocks();
    delete window.notify;
});

describe("stockInPribadiForm: picking a product from the popup", () => {
    it("adds the chosen product and closes the popup", () => {
        const { form } = mount();

        form.onProductPicked({
            detail: {
                item: {
                    product_id: 7,
                    name: "Nissan Skyline GT-R R34",
                    casting_code: "HWX-42",
                    series: "Hot Wheels",
                },
            },
        });

        expect(form.items).toHaveLength(1);
        expect(form.items[0]).toMatchObject({
            product_id: 7,
            name: "Nissan Skyline GT-R R34",
            casting_code: "HWX-42",
            qty: 1,
            cost_price: "",
        });
        expect(form.pickerOpen).toBe(false);
    });

    it("ignores a duplicate instead of adding a second row", () => {
        const warning = vi.fn();
        window.notify = { warning };

        const { form } = mount({
            initialItems: [
                { product_id: 7, name: "Nissan Skyline GT-R R34" },
            ],
        });

        form.onProductPicked({
            detail: { item: { product_id: 7, name: "Nissan Skyline GT-R R34" } },
        });

        expect(form.items).toHaveLength(1);
        expect(warning).toHaveBeenCalledWith(
            expect.stringContaining("sudah ada"),
        );
        expect(form.pickerOpen).toBe(false);
    });

    it("does nothing when the payload carries no product_id", () => {
        // Payload picker kasir lama tidak memuat `product_id`; tanpa guard ini
        // baris tanpa primary key akan masuk daftar dan gagal diam-diam saat
        // form disimpan. Popup juga harus tetap terbuka -- guard-nya pulang
        // sebelum `closePicker`, bukan menutup panel yang isinya kosong.
        const { form } = mount();
        form.pickerOpen = true;

        form.onProductPicked({
            detail: { item: { name: "Nissan Skyline GT-R R34" } },
        });

        expect(form.items).toHaveLength(0);
        expect(form.pickerOpen).toBe(true);
    });
});

describe("stockInPribadiForm: scanning a barcode", () => {
    it("adds the single product the scan resolves to", async () => {
        global.fetch = stubFetch([
            {
                product_id: 7,
                name: "Nissan Skyline GT-R R34",
                casting_code: "HWX-42",
            },
        ]);

        const { form } = mount();

        form.scanCode = "8991234567890";
        await form.addByBarcode("8991234567890");

        // fetch dipanggil dengan { items } -- endpoint inbound tidak mengirim
        // `lot` seperti picker kasir.
        expect(global.fetch).toHaveBeenCalledOnce();
        const body = global.fetch.mock.calls[0][1].body;
        expect(body.toString()).toBe("barcode=8991234567890");
        expect(form.scanError).toBe("");
        expect(form.items).toHaveLength(1);
        expect(form.items[0]).toMatchObject({
            product_id: 7,
            casting_code: "HWX-42",
        });
    });

    it("reports an ambiguous scan instead of guessing", async () => {
        global.fetch = stubFetch([
            { product_id: 7, name: "Nissan Skyline GT-R R34" },
            { product_id: 8, name: "Toyota Supra" },
        ]);

        const { form } = mount();
        await form.addByBarcode("8991234567890");

        expect(form.items).toHaveLength(0);
        expect(form.scanError).toContain("beberapa produk");
    });

    it("reports an unknown barcode", async () => {
        global.fetch = stubFetch([]);

        const { form } = mount();
        await form.addByBarcode("0000000000000");

        expect(form.items).toHaveLength(0);
        expect(form.scanError).toBe("Barcode tidak ditemukan");
    });

    it("keeps the code when the request itself fails", async () => {
        global.fetch = vi.fn(async () => {
            throw new Error("network down");
        });

        const { form } = mount();
        // Seperti `onScanKeydown`: kodenya diambil dari `this.scanCode`, dan
        // kegagalan request tidak boleh menghapusnya -- kalau hilang, operator
        // harus mengetik ulang setiap kali koneksi sebentar putus.
        form.scanCode = "8991234567890";
        await form.addByBarcode(form.scanCode);

        expect(form.scanCode).toBe("8991234567890");
        expect(form.scanError).toBe("Barcode tidak ditemukan");
    });
});

describe("stockInPribadiForm: duplicates", () => {
    it("scrolls to the existing row through $root, not $refs", () => {
        const scrollIntoView = vi.fn();
        const { form, rows } = mount({
            initialItems: [
                { product_id: 7, name: "Nissan Skyline GT-R R34" },
                { product_id: 8, name: "Toyota Supra" },
            ],
        });
        rows[1] = { scrollIntoView };

        const warning = vi.fn();
        window.notify = { warning };

        form.addProduct({ product_id: 8, name: "Toyota Supra" });

        expect(form.items).toHaveLength(2);
        expect(form.highlightedIndex).toBe(1);
        expect(scrollIntoView).toHaveBeenCalledWith({
            block: "nearest",
            behavior: "smooth",
        });
    });
});

describe("stockInPribadiForm: hydrated rows after a failed save", () => {
    it("keeps product_id, casting_code, and the operator's numbers", () => {
        const { form } = mount({
            initialItems: [
                {
                    product_id: 3,
                    casting_code: "HWX-42",
                    name: "Toyota Supra",
                    qty: 3,
                    cost_price: "45000",
                    card_condition: "EXC",
                    blister_condition: "DENTED",
                    rack_id: 5,
                },
            ],
        });

        expect(form.items[0]).toMatchObject({
            product_id: 3,
            casting_code: "HWX-42",
            name: "Toyota Supra",
            qty: 3,
            cost_price: "45000",
            card_condition: "EXC",
            blister_condition: "DENTED",
            rack_id: 5,
        });
    });
});

const DRAFT_DATASET = {
    draftLoad: "/inbound/stock-in-pribadi/draft",
    draftSave: "/inbound/stock-in-pribadi/draft",
    draftCancel: "/inbound/stock-in-pribadi/draft",
};

/**
 * Endpoint draft merespons berbeda untuk tiap metode, jadi stub-nya perlu
 * membedakan method-nya. Getter URL-nya juga dibaca dari dataset root, sama
 * seperti di blade.
 */
function draftFetch({ draft = null } = {}) {
    const calls = [];

    const fetchMock = vi.fn(async (url, options = {}) => {
        const method = options.method ?? "GET";
        calls.push({ url, method, body: options.body });

        if (method === "GET") {
            return {
                ok: true,
                json: async () =>
                    draft === null
                        ? { has_draft: false, draft: null }
                        : { has_draft: true, draft: { id: 1, items: [draft] } },
            };
        }

        return { ok: true, json: async () => ({ ok: true, draft_id: 1 }) };
    });

    fetchMock.calls = calls;

    return fetchMock;
}

describe("stockInPribadiForm: restoring the server draft", () => {
    it("brings the stored rows back when the form opens", async () => {
        global.fetch = draftFetch({
            draft: {
                product_id: 7,
                name: "Nissan Skyline GT-R R34",
                casting_code: "HWX-42",
                qty: 2,
                cost_price: "45000",
            },
        });

        const { form, initialized } = mount({ dataset: DRAFT_DATASET });
        await initialized;

        expect(form.items).toHaveLength(1);
        expect(form.items[0]).toMatchObject({
            product_id: 7,
            qty: 2,
            cost_price: "45000",
        });
        expect(form.draftExists).toBe(true);
        expect(form.lastSaved).not.toBeNull();
    });

    it("leaves the list alone when the operator already has rows", async () => {
        // Draft tidak boleh menimpa daftar yang sudah ada di layar. Dari
        // pandangan operator, yang ia ketik barusan lebih baru daripada
        // draft yang sedang dimuat, jadi guard-nya berhenti sebelum request
        // dikirim -- bukan sekadar membuang hasilnya.
        const fetchMock = draftFetch({
            draft: { product_id: 7, name: "Nissan Skyline GT-R R34", qty: 2 },
        });
        global.fetch = fetchMock;

        const { form, initialized } = mount({
            initialItems: [{ product_id: 99, name: "Toyota Supra" }],
            dataset: DRAFT_DATASET,
        });
        await initialized;

        expect(form.items).toHaveLength(1);
        expect(form.items[0].product_id).toBe(99);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("never asks for a draft when the page opted out", async () => {
        // Halaman yang menandai `restorable="false"` -- misalnya setelah
        // validasi commit gagal dan `old()` sudah mengisi ulang daftar --
        // tidak boleh memuat draft sama sekali.
        const fetchMock = draftFetch();
        global.fetch = fetchMock;

        const { form, initialized } = mount({
            initialItems: [{ product_id: 99, name: "Toyota Supra" }],
            dataset: DRAFT_DATASET,
            restorable: false,
        });
        await initialized;

        expect(fetchMock).not.toHaveBeenCalled();
        expect(form.items[0].product_id).toBe(99);
    });
});

describe("stockInPribadiForm: writing the draft", () => {
    it("posts the list once typing has settled", async () => {
        vi.useFakeTimers();
        const fetchMock = draftFetch();
        global.fetch = fetchMock;

        try {
            const { form, initialized } = mount({ dataset: DRAFT_DATASET });
            await initialized;

            form.addProduct({ product_id: 7, name: "Nissan Skyline GT-R R34" });
            form.scheduleSave();

            // Pengetikan cepat tidak boleh menimbulkan satu request per
            // keystroke: tungganya sengaja disetel.
            expect(
                fetchMock.calls.filter((call) => call.method !== "GET"),
            ).toHaveLength(0);

            await vi.advanceTimersByTimeAsync(400);

            const writes = fetchMock.calls.filter(
                (call) => call.method !== "GET",
            );
            expect(writes).toHaveLength(1);
            expect(writes[0].url).toBe("/inbound/stock-in-pribadi/draft");
            expect(writes[0].method).toBe("POST");
            expect(JSON.parse(writes[0].body).items[0]).toMatchObject({
                product_id: 7,
                cost_price: "",
            });
            expect(form.draftExists).toBe(true);
            expect(form.submitting).toBe(false);
        } finally {
            vi.useRealTimers();
        }
    });

    it("deletes the draft when the last row is removed", async () => {
        vi.useFakeTimers();
        const fetchMock = draftFetch();
        global.fetch = fetchMock;

        try {
            const { form, initialized } = mount({ dataset: DRAFT_DATASET });
            await initialized;

            form.addProduct({ product_id: 7, name: "Nissan Skyline GT-R R34" });
            form.scheduleSave();
            await vi.advanceTimersByTimeAsync(400);
            expect(form.draftExists).toBe(true);

            // `remove()` menunggu konfirmasi, jadi janjinya harus ditunggu
            // sebelum jadwal simpan berikutnya disusun.
            stubDangerConfirm(true);
            await form.remove(form.items[0].id);
            form.scheduleSave();
            await vi.advanceTimersByTimeAsync(400);

            expect(
                fetchMock.calls.filter((call) => call.method === "DELETE"),
            ).toHaveLength(1);
            expect(form.draftExists).toBe(false);
            expect(form.items).toHaveLength(0);
        } finally {
            vi.useRealTimers();
        }
    });

    it("writes the draft before handing the form to the browser", async () => {
        // Urutannya yang dipertaruhkan. Kalau `submit()` dipanggil duluan,
        // halaman berpindah lebih dulu dan draft yang tertulis adalah versi
        // sebelumnya -- atau tidak tertulis sama sekali.
        const order = [];

        global.fetch = vi.fn(async (url, options = {}) => {
            const method = options.method ?? "GET";
            order.push(`draft:${method}`);

            if (method === "GET") {
                return {
                    ok: true,
                    json: async () => ({ has_draft: false, draft: null }),
                };
            }

            return { ok: true, json: async () => ({ ok: true }) };
        });

        const { form, initialized } = mount({ dataset: DRAFT_DATASET });
        await initialized;

        form.addProduct({ product_id: 7, name: "Nissan Skyline GT-R R34" });

        const submit = vi.fn(() => order.push("submit"));
        await form.onSubmit({ target: { submit } });

        expect(order).toEqual(["draft:GET", "draft:POST", "submit"]);
        expect(form.submitting).toBe(true);
    });
});

describe("stockInPribadiForm: removing a row", () => {
    const ROW = {
        product_id: 7,
        name: "Nissan Skyline GT-R R34",
        casting_code: "HWX-42",
        qty: 2,
        cost_price: "45000",
    };

    it("asks before touching the list", async () => {
        const { form } = mount({ initialItems: [ROW] });
        const dangerConfirm = stubDangerConfirm(false);

        const removed = await form.remove(form.items[0].id);

        expect(removed).toBe(false);
        expect(form.items).toHaveLength(1);

        // Pertanyaannya harus menyebut baris dan angka yang hilang, bukan
        // sekadar "hapus?": tanpa qty dan HPP yang ikut lenyap, alasan
        // menekan "Batal" tidak ada.
        expect(dangerConfirm).toHaveBeenCalledWith({
            title: "Hapus dari daftar?",
            description: "Nissan Skyline GT-R R34 — 2 × Rp45.000 = Rp90.000",
            confirmText: "Hapus",
            cancelText: "Batal",
        });
    });

    it("drops the row once the operator confirms", async () => {
        const { form } = mount({
            initialItems: [ROW, { product_id: 8, name: "Toyota Supra" }],
        });
        form.highlightedIndex = 0;
        const dangerConfirm = stubDangerConfirm(true);

        const removed = await form.remove(form.items[0].id);

        expect(removed).toBe(true);
        expect(dangerConfirm).toHaveBeenCalledOnce();
        expect(form.items).toHaveLength(1);
        expect(form.items[0].name).toBe("Toyota Supra");
        expect(form.highlightedIndex).toBe(-1);
    });

    it("leaves the row alone when there is no confirmation helper", async () => {
        // Gagal menutup, bukan gagal menghapus: halaman yang tidak sanggup
        // menawarkan pertanyaan tidak boleh menghapus diam-diam, karena itu
        // justru kecelakaan yang dialog ini cegah.
        delete window.notify;

        const { form } = mount({ initialItems: [ROW] });

        const removed = await form.remove(form.items[0].id);

        expect(removed).toBe(false);
        expect(form.items).toHaveLength(1);
    });

    it("removes the row it was asked about after the list shifted", async () => {
        // Sementara dialog terbuka, baris lain ikut hilang. Menghitung posisi
        // sekali di awal lalu memakainya kembali akan menghapus indeks yang
        // sekarang menunjuk entri yang salah -- atau tidak menghapus apa pun.
        const { form } = mount({
            initialItems: [
                ROW,
                { product_id: 8, name: "Toyota Supra" },
            ],
        });
        const targetId = form.items[1].id;

        stubDangerConfirm(true, () => {
            form.items.shift();
        });

        const removed = await form.remove(targetId);

        expect(removed).toBe(true);
        expect(form.items).toHaveLength(0);
    });

    it("does not open a dialog for a row that is already gone", async () => {
        const { form } = mount({ initialItems: [ROW] });
        const dangerConfirm = stubDangerConfirm(true);

        const removed = await form.remove("sip-not-there");

        expect(removed).toBe(false);
        expect(dangerConfirm).not.toHaveBeenCalled();
        expect(form.items).toHaveLength(1);
    });
});
