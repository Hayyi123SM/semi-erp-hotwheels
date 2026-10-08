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
 */
function mount({ initialItems = [] } = {}) {
    const form = stockInPribadiForm({
        lookupUrl: "/inbound/produk/cari",
        racks: [],
        cardConditions: { MINT: "MINT" },
        blisterConditions: { CLEAR: "CLEAR" },
        initialItems,
    });

    const rows = [];

    form.$nextTick = (fn) => fn();
    form.$refs = { scanInput: { focus: vi.fn() } };
    form.$root = {
        querySelector: (selector) => {
            const match = selector.match(/\[data-row-idx="(\d+)"\]/);

            if (!match) {
                return null;
            }

            return rows[Number(match[1])] ?? null;
        },
    };

    form.init();

    return { form, rows };
}

function stubFetch(items, ok = true) {
    return vi.fn(async () => ({
        ok,
        json: async () => ({ items }),
    }));
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
