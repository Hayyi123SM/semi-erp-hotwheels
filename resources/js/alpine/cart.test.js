import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { registerCart } from "./cart";

/**
 * Keranjang diuji dengan scope seadanya -- tanpa Alpine sungguhan -- karena
 * yang diuji di sini adalah keputusan yang diambil dari kode yang dipindai:
 * lot mana yang masuk, dan apa yang terjadi ketika tidak ada yang cocok.
 *
 * `posCart` menerima scope langsung, jadi test ini tidak perlu merakit DOM.
 * Yang tetap disalin apa adanya: `lookupUrl` dibaca dari `data-lookup-url` di
 * elemen pembungkus, `$store.toast` dipakai untuk pesan, dan CSRF diambil dari
 * meta tag -- ketiganya hal yang kalau disederhanakan akan membuat test lulus
 * sementara halamannya tidak jalan.
 */
function mount({ url = "/pos/produk/cari", lots = [] } = {}) {
    const toasts = [];
    const root = document.createElement("div");
    root.dataset.lookupUrl = url;

    let cart = null;

    registerCart({
        data(name, factory) {
            if (name !== "posCart") return;

            cart = factory(lots);
        },
    });

    // Dipanggil manual: Alpine memanggilnya sendiri di halaman.
    Object.assign(cart, {
        $root: root,
        $store: {
            toast: { push: (message, type) => toasts.push({ message, type }) },
        },
    });
    cart.init();

    const destroy = () => cart.destroy();

    mounted.push({ destroy });

    return { cart, toasts, root, destroy };
}

/**
 * Endpoint yang menjawab dengan satu lot, satu kosong, atau error.
 */
function stubFetch({ lot = null, ok = true, items = [] } = {}) {
    return vi.fn(async () => ({
        ok,
        json: async () => ({ lot, items }),
    }));
}

beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
});

/**
 * Teardown for every test that mounted a cart.
 *
 * `init()` registers a window listener so the product picker can hand its choice
 * to the cart. Alpine removes it through `destroy()` when the element goes away,
 * but a test builds the component and throws it away, and a listener that outlives
 * its cart keeps adding items to a cart nobody can see.
 */
const mounted = [];

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop().destroy();
    }

    vi.restoreAllMocks();
});

describe("posCart: scanning a code", () => {
    it("asks the server before putting anything in the cart", async () => {
        const { cart } = mount();
        const lot = {
            id: 1,
            sku: "HW-001",
            name: "Hot Wheels",
            price: 45_000,
            stock: 3,
            sellable: true,
        };

        global.fetch = stubFetch({ lot });

        await expect(cart.addBySku("HW-001")).resolves.toBe(true);

        expect(global.fetch).toHaveBeenCalledWith("/pos/produk/cari", {
            method: "POST",
            headers: {
                Accept: "application/json",
                "X-CSRF-TOKEN": "test-token",
            },
            body: new URLSearchParams({ barcode: "HW-001" }),
        });

        expect(cart.items).toHaveLength(1);
    });

    it("trims what the scanner sent, since wedge input can arrive with a stray character at either end", async () => {
        const { cart } = mount();
        global.fetch = stubFetch({
            lot: {
                id: 1,
                sku: "HW-001",
                name: "x",
                price: 1,
                stock: 1,
                sellable: true,
            },
        });

        await cart.addBySku("  HW-001  ");

        expect(global.fetch.mock.calls[0][1].body.get("barcode")).toBe(
            "HW-001",
        );
    });

    it("does not call the server at all for an empty code", async () => {
        const { cart } = mount();
        global.fetch = stubFetch({ lot: null });

        await expect(cart.addBySku("   ")).resolves.toBe(false);

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it("refuses a lot that is not sellable, and says why", async () => {
        const { cart, toasts } = mount();
        global.fetch = stubFetch({
            lot: {
                id: 1,
                sku: "HW-001",
                name: "x",
                price: 1,
                stock: 0,
                sellable: false,
                pendingLabels: 0,
            },
        });

        await expect(cart.addBySku("HW-001")).resolves.toBe(false);

        expect(cart.items).toHaveLength(0);
        expect(cart.lastError).toContain("sudah habis");
        expect(toasts).toHaveLength(1);
    });

    it("names the printer, not just the verdict, when labels are still pending", async () => {
        const { cart } = mount();
        global.fetch = stubFetch({
            lot: {
                id: 1,
                sku: "HW-001",
                name: "x",
                price: 1,
                stock: 5,
                sellable: false,
                pendingLabels: 2,
            },
        });

        await cart.addBySku("HW-001");

        expect(cart.lastError).toContain("printer");
    });
});

describe("posCart: a code nobody knows", () => {
    it("shows the code and offers the way out, rather than only complaining", async () => {
        const { cart, toasts } = mount();
        global.fetch = stubFetch({ lot: null });

        const notify = {
            modal: vi.fn(async () => ({ isConfirmed: false })),
            templateModal: vi.fn(),
        };
        window.notify = notify;

        await cart.addBySku("HANTU-999");

        expect(cart.unknownCode).toBe("HANTU-999");
        expect(cart.lastError).toContain("HANTU-999");
        expect(notify.modal).toHaveBeenCalledWith(
            expect.objectContaining({ confirmText: "Cari manual" }),
        );

        // The cart itself stays quiet: the dialog is the answer.
        expect(toasts).toHaveLength(0);
    });

    it("opens the picker seeded with the same code, but only after the dialog closed", async () => {
        const { cart } = mount();
        global.fetch = stubFetch({ lot: null });

        const order = [];
        let resolveDialog;
        window.notify = {
            modal: vi.fn(
                () =>
                    new Promise((resolve) => {
                        resolveDialog = () => {
                            order.push("dialog closed");
                            resolve({ isConfirmed: true });
                        };
                    }),
            ),
            templateModal: vi.fn(() => order.push("picker opened")),
        };

        await cart.addBySku("HANTU-999");

        // Still nothing: the picker must not open on top of a live dialog, or the
        // dialog's focus trap keeps a node that is no longer on the page.
        expect(window.notify.templateModal).not.toHaveBeenCalled();

        resolveDialog();
        await vi.waitFor(() =>
            expect(order).toEqual(["dialog closed", "picker opened"]),
        );
    });

    it("survives a page where notify never loaded", async () => {
        const { cart } = mount();
        global.fetch = stubFetch({ lot: null });
        delete window.notify;

        await expect(cart.addBySku("HANTU-999")).resolves.toBe(false);
    });
});

describe("posCart: the cart itself", () => {
    it("adds to the existing row instead of opening a second one for the same lot", async () => {
        const { cart } = mount();
        global.fetch = stubFetch({
            lot: {
                id: 1,
                sku: "HW-001",
                name: "x",
                price: 10,
                stock: 5,
                sellable: true,
            },
        });

        await cart.addBySku("HW-001");
        await cart.addBySku("HW-001");

        expect(cart.items).toHaveLength(1);
        expect(cart.items[0].qty).toBe(2);
    });

    it("stops at the stock it was told about", async () => {
        const { cart } = mount();
        global.fetch = stubFetch({
            lot: {
                id: 1,
                sku: "HW-001",
                name: "x",
                price: 10,
                stock: 1,
                sellable: true,
            },
        });

        await cart.addBySku("HW-001");
        await cart.addBySku("HW-001");

        expect(cart.items[0].qty).toBe(1);
    });

    it("totals and counts what is on screen", () => {
        const { cart } = mount();

        cart.addItem({
            id: 1,
            sku: "A",
            name: "a",
            price: 10_000,
            stock: 4,
            sellable: true,
        });
        cart.addItem({
            id: 2,
            sku: "B",
            name: "b",
            price: 5_000,
            stock: 4,
            sellable: true,
        });

        expect(cart.subtotal).toBe(15_000);
        expect(cart.cartCount).toBe(2);
    });

    it("never reports change for a shortfall, because a cashier cannot hand out negative money", () => {
        const { cart } = mount();
        cart.addItem({
            id: 1,
            sku: "A",
            name: "a",
            price: 10_000,
            stock: 4,
            sellable: true,
        });
        cart.tender = 5_000;

        expect(cart.change).toBe(0);
    });

    it("drops the row entirely when the last unit is decremented", () => {
        const { cart } = mount();
        cart.addItem({
            id: 1,
            sku: "A",
            name: "a",
            price: 10_000,
            stock: 4,
            sellable: true,
        });

        cart.decrement("A");

        expect(cart.items).toHaveLength(0);
    });

    it("never asks while decrementing, however far the qty goes", () => {
        const ask = vi.fn().mockResolvedValue(false);
        window.notify = { ask };

        try {
            const { cart } = mount();
            cart.addItem({ id: 1, sku: "A", name: "a", price: 10_000, stock: 4, sellable: true });
            cart.addItem({ id: 2, sku: "B", name: "b", price: 10_000, stock: 4, sellable: true });

            // qty 1 -> 0 menghapus barisnya, dan tidak boleh membuka dialog.
            cart.decrement("A");

            expect(cart.items.map((i) => i.sku)).toEqual(["B"]);
            expect(ask).not.toHaveBeenCalled();
        } finally {
            delete window.notify;
        }
    });
});

describe("posCart: removing a row deliberately", () => {
    const lot = {
        id: 1,
        sku: "A",
        name: "Hot Wheels Model A",
        price: 10_000,
        stock: 4,
        sellable: true,
    };

    function mountWithNotify(ask) {
        window.notify = { ask };

        const { cart } = mount();
        cart.addItem({ ...lot });

        return cart;
    }

    afterEach(() => {
        delete window.notify;
    });

    it("removes the row once confirmed", async () => {
        const cart = mountWithNotify(vi.fn().mockResolvedValue(true));

        expect(await cart.removeItem("A")).toBe(true);
        expect(cart.items).toHaveLength(0);
    });

    it("leaves the row alone when the answer is no", async () => {
        const cart = mountWithNotify(vi.fn().mockResolvedValue(false));

        expect(await cart.removeItem("A")).toBe(false);
        expect(cart.items).toHaveLength(1);
        expect(cart.items[0].sku).toBe("A");
    });

    it("names the row and its line total, so the right button is the obvious one", async () => {
        const ask = vi.fn().mockResolvedValue(false);
        const cart = mountWithNotify(ask);

        await cart.removeItem("A");

        const options = ask.mock.calls[0][0];
        expect(options.title).toBe("Hapus dari keranjang?");
        expect(options.description).toContain("Hot Wheels Model A");
        expect(options.description).toContain("Rp10.000");
        expect(options.danger).toBe(true);
    });

    it("closes off every way of answering yes by accident", async () => {
        const ask = vi.fn().mockResolvedValue(false);
        const cart = mountWithNotify(ask);

        await cart.removeItem("A");

        // `danger` di `notify.ask()` yang memindahkan fokus ke Batal dan mematikan
        // backdrop, Escape, dan tombol X. Tanpa dia, dialog hapus punya empat
        // jalan keluar yang semuanya berarti "hapus".
        expect(ask.mock.calls[0][0].danger).toBe(true);
        expect(ask.mock.calls[0][0].cancelText).toBe("Batal");
    });

    it("deletes nothing when the dialog helper is missing", async () => {
        // Menghapus tanpa bisa bertanya lebih berbahaya daripada tidak menghapus,
        // jadi helper yang hilang berarti batal, bukan persetujuan diam-diam.
        const { cart } = mount();
        cart.addItem({ ...lot });

        expect(await cart.removeItem("A")).toBe(false);
        expect(cart.items).toHaveLength(1);
    });

    it("removes nothing for a sku that is not in the cart", async () => {
        const ask = vi.fn().mockResolvedValue(true);
        const cart = mountWithNotify(ask);

        expect(await cart.removeItem("Z")).toBe(false);
        expect(cart.items).toHaveLength(1);
        // Tidak bertanya untuk SKU yang memang tidak ada: dialog untuk produk
        // sudah hilang hanya membingungkan.
        expect(ask).not.toHaveBeenCalled();
    });
});

describe("posCart: taking a pick from the product picker", () => {
    const lot = {
        id: 1,
        sku: "HW-001",
        name: "Hot Wheels",
        price: 45_000,
        stock: 3,
        sellable: true,
    };

    /**
     * What `productPicker.choose()` sends.
     *
     * Spelled out here rather than imported from the picker so the test fails if
     * either side moves alone: the picker dispatches this, and the cart listens
     * for it, and a rename that only touches one of them is exactly the bug that
     * left the picker closing with an empty cart and both test files green.
     */
    function picked(sku) {
        window.dispatchEvent(
            new CustomEvent("pos:add-item", { detail: { sku } }),
        );
    }

    it("puts the picked item in the cart", async () => {
        const { cart } = mount();

        global.fetch = stubFetch({ lot });

        picked("HW-001");
        await vi.waitFor(() => expect(cart.items).toHaveLength(1));

        expect(cart.items[0]).toMatchObject({ sku: "HW-001", qty: 1 });
    });

    it("asks the server for the lot, the same way a scan does", async () => {
        const { cart } = mount();

        global.fetch = stubFetch({ lot });

        picked("HW-001");
        await vi.waitFor(() => expect(cart.items).toHaveLength(1));

        // The picker sends a SKU and nothing else. Price and stock on its screen
        // can already be stale by the time the button is pressed, so the cart has
        // to re-read them -- and re-reading them over the scanner's endpoint is
        // the point: one lookup, one decision, two ways in.
        expect(global.fetch).toHaveBeenCalledWith("/pos/produk/cari", {
            method: "POST",
            headers: {
                Accept: "application/json",
                "X-CSRF-TOKEN": "test-token",
            },
            body: new URLSearchParams({ barcode: "HW-001" }),
        });
    });

    it("ignores a pick with no SKU rather than looking up nothing", async () => {
        const { cart } = mount();

        global.fetch = stubFetch({ lot });

        window.dispatchEvent(new CustomEvent("pos:add-item", { detail: {} }));
        await Promise.resolve();

        expect(global.fetch).not.toHaveBeenCalled();
        expect(cart.items).toHaveLength(0);
    });

    it("stops listening once the cart is gone", async () => {
        const { cart, destroy } = mount();

        global.fetch = stubFetch({ lot });
        destroy();

        picked("HW-001");
        await Promise.resolve();

        expect(global.fetch).not.toHaveBeenCalled();
        expect(cart.items).toHaveLength(0);
    });
});

describe("posCart: the money received", () => {
    function cartWithTotal(total) {
        const { cart } = mount();

        cart.addItem({
            id: 1,
            sku: "A",
            name: "a",
            price: total,
            stock: 4,
            sellable: true,
        });

        return cart;
    }

    it("counts nothing as nothing, not as a zero typed by hand", () => {
        const cart = cartWithTotal(45_000);

        // `''` and `'0'` are different states and a number cannot tell them apart.
        // Collapsing them means an empty field and a field showing `0` are one
        // value, and the change figure cannot tell the cashier which one it saw.
        expect(cart.change).toBe(0);

        cart.tender = "0";
        expect(cart.change).toBe(0);
    });

    it("reads grouped digits typed into the field as plain digits", () => {
        const cart = cartWithTotal(45_000);

        // The field shows `1.000.000`; the property behind it holds the digits.
        cart.tender = "1000000";

        expect(cart.change).toBe(955_000);
    });

    it("never reports change owed to the customer", () => {
        const cart = cartWithTotal(45_000);

        cart.tender = "20000";

        expect(cart.change).toBe(0);
    });

    it("fills the field from quick cash without losing the grouping", () => {
        const cart = cartWithTotal(45_000);

        cart.quickTender(50_000);

        expect(cart.tender).toBe("50000");
        expect(cart.change).toBe(5_000);
    });

    it("leaves the field empty for an exact payment of nothing", () => {
        const cart = cartWithTotal(45_000);

        cart.quickTender(0);

        expect(cart.tender).toBe("");
    });

    it("empties the field after a sale", () => {
        const cart = cartWithTotal(45_000);

        cart.quickTender(50_000);
        cart.clear();

        expect(cart.tender).toBe("");
        expect(cart.items).toHaveLength(0);
    });
});

describe("posCart: paying", () => {
    const LOT = {
        id: 7,
        sku: "HW-001",
        name: "Hot Wheels",
        price: 45_000,
        stock: 3,
        sellable: true,
    };

    /**
     * A cart with one line and cash in hand, pointed at the checkout endpoint.
     *
     * The endpoint is read from the wrapper exactly as on the page, because a
     * test that hard-codes a URL proves nothing about the page that does not.
     */
    function cartWith(tender = "50000") {
        const { cart, toasts, root } = mount();

        root.dataset.checkoutUrl = "/pos/transaksi";
        cart.addItem({ ...LOT });
        cart.tender = tender;

        return { cart, toasts, root };
    }

    function checkout({ ok = true, status = 200, body = {} } = {}) {
        return vi.fn(async () => ({ ok, status, json: async () => body }));
    }

    function sent(globalFetch) {
        const [url, options] = globalFetch.mock.calls[0];

        return { url, options, payload: JSON.parse(options.body) };
    }

    it("sends only what the server cannot know, and never a price", async () => {
        const { cart } = cartWith();
        global.fetch = checkout({ body: { receipt_no: "HW-20261006-0001", change: 5_000 } });

        await cart.pay();

        const { url, options, payload } = sent(global.fetch);

        expect(url).toBe("/pos/transaksi");
        expect(options.headers["X-CSRF-TOKEN"]).toBe("test-token");
        expect(options.headers.Accept).toBe("application/json");

        expect(payload).toMatchObject({
            items: [{ lot_id: 7, qty: 1, input_method: "SCAN" }],
            payments: [{ method: "TUNAI", amount: 45_000 }],
            tender: 50_000,
        });

        // `alpha_dash` at the other end; a key it would reject only surfaces
        // after the cashier has already pressed Bayar.
        expect(payload.client_sale_id).toMatch(/^[A-Za-z0-9_-]+$/);

        // The cart's price is a copy that can already be stale, so it must not
        // travel: the server reads `stock_lots` and decides for itself.
        expect(payload.items[0]).not.toHaveProperty("price");
        expect(payload).not.toHaveProperty("subtotal");
    });

    it("keeps the cart when the server rejects it", async () => {
        const { cart, toasts } = cartWith();
        global.fetch = checkout({
            ok: false,
            status: 422,
            body: {
                message: "The given data was invalid.",
                errors: { items: ["Stok HW-001 tinggal 0 unit."] },
            },
        });

        await cart.pay();

        expect(cart.items).toHaveLength(1);
        expect(toasts.at(-1)).toMatchObject({
            type: "error",
            message: "Stok HW-001 tinggal 0 unit.",
        });
    });

    it("empties the cart and names the receipt only after the server says yes", async () => {
        const { cart, toasts } = cartWith();
        global.fetch = checkout({
            body: { receipt_no: "HW-20261006-0001", total: 45_000, change: 5_000 },
        });

        await cart.pay();

        expect(cart.items).toHaveLength(0);
        expect(cart.tender).toBe("");
        expect(toasts.at(-1)).toMatchObject({ type: "success" });
        expect(toasts.at(-1).message).toContain("HW-20261006-0001");
        expect(toasts.at(-1).message).toContain("Rp5.000");
    });

    it("reports the change the server calculated, not the one on screen", async () => {
        const { cart, toasts } = cartWith("50000");
        global.fetch = checkout({ body: { receipt_no: "HW-20261006-0001", change: 0 } });

        await cart.pay();

        // The screen said Rp5.000 before the server corrected the price.
        expect(cart.change).toBe(0);
        expect(toasts.at(-1).message).not.toContain("kembalian");
    });

    it("reuses one key while the cart is unchanged, and drops it when it is not", () => {
        const { cart } = cartWith();

        const first = cart.saleId();

        expect(cart.saleId()).toBe(first);

        cart.increment("HW-001");
        const second = cart.saleId();

        expect(second).not.toBe(first);

        cart.clear();

        expect(cart.saleId()).not.toBe(second);
    });

    it("refuses short cash before sending anything at all", async () => {
        const { cart, toasts } = cartWith("10000");
        global.fetch = checkout();

        await cart.pay();

        expect(global.fetch).not.toHaveBeenCalled();
        expect(cart.items).toHaveLength(1);
        expect(toasts.at(-1)).toMatchObject({ type: "warning" });
        expect(toasts.at(-1).message).toContain("kurang dari total");
    });

    it("sends no tender for a payment that produces no change", async () => {
        const { cart } = cartWith();
        cart.paymentMethod = "QRIS";
        global.fetch = checkout({ body: { receipt_no: "HW-20261006-0001", change: 0 } });

        await cart.pay();

        const { payload } = sent(global.fetch);

        expect(payload.tender).toBeNull();
        expect(payload.payments).toEqual([
            { method: "QRIS", amount: 45_000 },
        ]);
    });

    it("sends one request however many times F8 is pressed", async () => {
        const { cart } = cartWith();

        let release;
        global.fetch = vi.fn(
            () =>
                new Promise((resolve) => {
                    release = () =>
                        resolve({ ok: true, json: async () => ({ receipt_no: "X", change: 0 }) });
                }),
        );

        const first = cart.pay();
        const second = cart.pay();

        expect(global.fetch).toHaveBeenCalledTimes(1);

        release();
        await Promise.all([first, second]);

        expect(cart.paying).toBe(false);
    });

    it("keeps the cart when the server cannot be reached", async () => {
        const { cart, toasts } = cartWith();
        global.fetch = vi.fn(async () => {
            throw new Error("network down");
        });

        await cart.pay();

        expect(cart.items).toHaveLength(1);
        expect(toasts.at(-1)).toMatchObject({ type: "error" });
        expect(toasts.at(-1).message).toContain("belum tercatat");
    });

    it("opens the struk dialog with the server-rendered preview once the sale is saved", async () => {
        const { cart } = cartWith();
        const modal = vi.fn(async () => ({ isConfirmed: false }));
        window.notify = { modal };

        global.fetch = checkout({
            body: {
                receipt_no: "HW-20261006-0001",
                total: 45_000,
                change: 5_000,
                struk_html: "<div class=\"receipt-sheet\">pratinjau dari server</div>",
                print_method: "browser",
                paper: "80mm",
                thermal_url: "/pos/struk/7/thermal",
                print_url: "/pos/struk/7?auto=1&change=5000",
            },
        });

        await cart.pay();

        expect(modal).toHaveBeenCalledTimes(1);

        const options = modal.mock.calls[0][0];
        expect(options.title).toContain("HW-20261006-0001");
        expect(options.html).toContain("pratinjau dari server");
        expect(options.html).toContain("Cetak Struk");
        expect(options.html).toContain('x-data="posStrukDialog($el.dataset)"');

        // Dialog tidak menahan `pay()`: pemeriksaan di sini selesai walau
        // dialognya masih terbuka.
        expect(cart.paying).toBe(false);
        expect(cart.items).toHaveLength(0);
    });

    it("skips the dialog when the checkout answer carries no preview", async () => {
        const { cart } = cartWith();
        const modal = vi.fn();
        window.notify = { modal };

        global.fetch = checkout({
            body: { receipt_no: "HW-20261006-0001", change: 5_000 },
        });

        await cart.pay();

        expect(modal).not.toHaveBeenCalled();
        expect(cart.items).toHaveLength(0);
    });
});

describe("posCart: struk dialog body", () => {
    const STRUK = {
        receitLikeHtml: "<div class=\"receipt-sheet\">stub</div>",
        print_method: "thermal",
        paper: "58mm",
        thermal_url: "/pos/struk/7/thermal",
        print_url: "/pos/struk/7?auto=1&change=5000",
    };

    function dialogHtml(data) {
        const { cart } = mount();
        return cart.strukDialogHtml(data);
    }

    it("hands the two print routes to the dialog through data attributes, not a string template", () => {
        const html = dialogHtml({ ...STRUK, struk_html: STRUK.receitLikeHtml });

        expect(html).toContain("data-thermal-url=\"/pos/struk/7/thermal\"");
        expect(html).toContain("data-print-url=\"/pos/struk/7?auto=1&amp;change=5000\"");
        expect(html).toContain("data-method=\"thermal\"");
        expect(html).toContain("data-paper=\"58mm\"");
    });

    it("keeps the server preview intact inside the dialog", () => {
        const html = dialogHtml({ ...STRUK, struk_html: STRUK.receitLikeHtml });

        expect(html).toContain("receipt-sheet");
        expect(html).toContain("Cetak Struk");
        expect(html).toContain("Selesai");
    });
});

describe("posStrukDialog: printing after a sale", () => {
    const BASE = {
        method: "browser",
        paper: "80mm",
        thermalUrl: "/pos/struk/7/thermal",
        printUrl: "/pos/struk/7?auto=1",
    };

    function buildDialog(opts) {
        let dialog = null;

        registerCart({
            data(name, factory) {
                if (name !== "posStrukDialog") return;
                dialog = factory(opts);
            },
        });

        return dialog;
    }

    afterEach(() => {
        delete window.notify;
        delete window.__thermalPrint;
        delete window.open;
    });

    it("opens the browser print dialog for the default (browser) method", () => {
        const open = vi.fn();
        window.open = open;

        const dialog = buildDialog(BASE);
        dialog.cetak();

        expect(open).toHaveBeenCalledWith(
            "/pos/struk/7?auto=1",
            "_blank",
            "noopener",
        );
    });

    it("sends bytes to the thermal endpoint only for struk paper when the shop is on thermal", () => {
        const print = vi.fn();
        window.__thermalPrint = print;

        const dialog = buildDialog({ ...BASE, method: "thermal", paper: "58mm" });
        dialog.cetak();

        expect(print).toHaveBeenCalledWith(
            "/pos/struk/7/thermal",
            expect.objectContaining({ onFailed: expect.any(Function) }),
        );
    });

    it("never attempts thermal on A4, whatever the global method says", () => {
        const print = vi.fn();
        window.__thermalPrint = print;
        const open = vi.fn();
        window.open = open;

        const dialog = buildDialog({ ...BASE, method: "thermal", paper: "a4" });
        dialog.cetak();

        expect(print).not.toHaveBeenCalled();
        expect(open).toHaveBeenCalled();
    });

    it("falls back to the browser dialog when thermal fails", () => {
        const open = vi.fn();
        window.open = open;
        window.__thermalPrint = (url, { onFailed }) => onFailed();

        const dialog = buildDialog({ ...BASE, method: "thermal", paper: "58mm" });
        dialog.cetak();

        expect(open).toHaveBeenCalledWith(
            "/pos/struk/7?auto=1",
            "_blank",
            "noopener",
        );
    });

    it("closes the dialog on Selesai, and only inside a page that has notify", () => {
        const close = vi.fn();
        window.notify = { close };

        const dialog = buildDialog(BASE);
        dialog.selesai();

        expect(close).toHaveBeenCalledTimes(1);

        delete window.notify;
        dialog.selesai();
    });
});
