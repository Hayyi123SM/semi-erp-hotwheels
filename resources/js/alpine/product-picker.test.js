import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { productPicker } from "./product-picker";
import { readTemplateSeed, seedTemplate } from "./template-seed";

/**
 * Panel picker berdiri sendiri di dalam dialog, jadi ia tidak pernah punya akses
 * ke scope keranjang. Dua hal yang diuji di sini adalah konsekuensi dari itu:
 * pilihannya pergi sebagai event, dan komponen yang di-seed lewat template -- bukan
 * lewat argumen pemanggil, karena `templateModal` membangun pohon baru.
 */
const TEMPLATE_ID = "pos-product-picker";

function mount({ notify = { close: vi.fn() } } = {}) {
    const template = document.createElement("template");
    template.id = TEMPLATE_ID;
    document.body.append(template);

    const search = document.createElement("input");
    document.body.append(search);

    const picker = productPicker({ url: "/pos/produk/cari", notify });

    Object.assign(picker, {
        $nextTick: (callback) => callback(),
        $refs: { search },
    });

    return { picker, template, search, notify };
}

function stubFetch(payload, { ok = true } = {}) {
    return vi.fn(async () => ({
        ok,
        status: ok ? 200 : 422,
        json: async () => payload,
        text: async () => (ok ? "" : "Kode tidak valid."),
    }));
}

const items = [
    {
        sku: "HW-001",
        name: "Hot Wheels",
        price: 45_000,
        stock: 3,
        sellable: true,
    },
    {
        sku: "HW-002",
        name: "Hot Wheels Track",
        price: 60_000,
        stock: 1,
        sellable: true,
    },
];

beforeEach(() => {
    document.head.innerHTML = '<meta name="csrf-token" content="token">';
    document.body.innerHTML = "";
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe("productPicker: searching", () => {
    it("asks the server once the term is long enough, and sends it as a search", async () => {
        const { picker } = mount();
        global.fetch = stubFetch({ items });

        picker.term = "hot";
        picker.search();
        await vi.waitFor(() => expect(picker.items).toHaveLength(2));

        expect(global.fetch).toHaveBeenCalledWith("/pos/produk/cari", {
            method: "POST",
            headers: { Accept: "application/json", "X-CSRF-TOKEN": "token" },
            body: new URLSearchParams({ q: "hot" }),
        });
    });

    it("says nothing while the term is too short, rather than reporting nothing found", () => {
        const { picker } = mount();
        global.fetch = stubFetch({ items: [] });

        picker.term = "h";
        picker.search();

        expect(global.fetch).not.toHaveBeenCalled();
        expect(picker.searched).toBe(false);
        expect(picker.error).toBe("");
    });

    it("ignores a slow answer to an old term, so the list is never from two searches", async () => {
        const { picker } = mount();

        let releaseFirst;
        global.fetch = vi
            .fn()
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        releaseFirst = () =>
                            resolve({
                                ok: true,
                                json: async () => ({
                                    items: [{ sku: "LAMA" }],
                                }),
                            });
                    }),
            )
            .mockImplementationOnce(async () => ({
                ok: true,
                json: async () => ({ items: [{ sku: "BARU" }] }),
            }));

        picker.term = "lama";
        picker.search();

        picker.term = "baru";
        picker.search();
        await vi.waitFor(() => expect(picker.items).toHaveLength(1));
        expect(picker.items[0].sku).toBe("BARU");

        releaseFirst();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(picker.items[0].sku).toBe("BARU");
    });

    it("reports the server's own complaint instead of a generic failure", async () => {
        const { picker } = mount();
        global.fetch = stubFetch(null, { ok: false });

        picker.term = "hot";
        picker.search();
        await vi.waitFor(() => expect(picker.error).not.toBe(""));
    });
});

describe("productPicker: choosing", () => {
    it("hands over the SKU and closes, because the server re-reads the lot on arrival", async () => {
        const { picker, notify } = mount();
        const add = vi.fn();

        window.addEventListener("pos:add-item", add);
        picker.choose({ sku: "HW-001" });
        window.removeEventListener("pos:add-item", add);

        expect(add).toHaveBeenCalledTimes(1);
        expect(add.mock.calls[0][0].detail.sku).toBe("HW-001");
        expect(notify.close).toHaveBeenCalled();
    });
});

describe("productPicker: being seeded", () => {
    it("starts with the code that just failed, and searches it right away", async () => {
        const { picker } = mount();
        global.fetch = stubFetch({ items });

        seedTemplate(TEMPLATE_ID, "term", "HANTU-99");
        picker.init();
        await vi.waitFor(() => expect(picker.items).toHaveLength(2));

        expect(picker.term).toBe("HANTU-99");
    });

    it("reads the seed once, so a code from ten minutes ago does not greet the next cashier", () => {
        document.body.innerHTML =
            '<template id="pos-product-picker" data-term="HANTU-99"></template>';

        expect(readTemplateSeed(TEMPLATE_ID, "term")).toBe("HANTU-99");
        expect(readTemplateSeed(TEMPLATE_ID, "term")).toBe("");
    });

    it("seeds nothing when the template is not on the page, rather than throwing", () => {
        expect(() => seedTemplate("tidak-ada", "term", "x")).not.toThrow();
        expect(readTemplateSeed("tidak-ada", "term")).toBe("");
    });
});

describe("productPicker: choosing with the keyboard", () => {
    /**
     * A picker holding `items`, with nothing left to fetch.
     *
     * `await` inside the component is already settled once the stubbed fetch
     * resolves, so the only thing left to wait for is the promise chain the
     * component kicked off -- which is what `search()` returns nothing for.
     */
    async function mountWithResults() {
        const { picker, notify } = mount();
        const picked = [];
        const record = (event) => picked.push(event.detail.sku);

        window.addEventListener("pos:add-item", record);

        global.fetch = stubFetch({ items });
        picker.term = "hw";
        picker.search();
        await vi.waitFor(() => expect(picker.items).toHaveLength(items.length));

        return {
            picker,
            notify,
            picked,
            forget: () => window.removeEventListener("pos:add-item", record),
        };
    }

    it("starts with nothing highlighted", async () => {
        const { picker } = await mountWithResults();

        // Not index 0. With 0 as the resting state, Enter would put the first
        // result in the cart without the cashier having pointed at it.
        expect(picker.activeIndex).toBe(-1);
    });

    it("moves down and back up", async () => {
        const { picker } = await mountWithResults();

        picker.move(1);
        expect(picker.activeIndex).toBe(0);

        picker.move(1);
        expect(picker.activeIndex).toBe(1);

        picker.move(-1);
        expect(picker.activeIndex).toBe(0);
    });

    it("stops at the ends instead of wrapping", async () => {
        const { picker } = await mountWithResults();

        picker.move(-1);
        expect(picker.activeIndex).toBe(-1);

        picker.move(1);
        picker.move(1);
        picker.move(1);
        expect(picker.activeIndex).toBe(items.length - 1);
    });

    it("does nothing when there are no results to move through", async () => {
        const { picker } = mount();

        picker.move(1);

        expect(picker.activeIndex).toBe(-1);
    });

    it("chooses the highlighted row on Enter", async () => {
        const { picker, notify, picked, forget } = await mountWithResults();

        picker.move(1);
        picker.move(1);
        picker.submit();
        forget();

        expect(picked).toEqual(["HW-002"]);
        expect(notify.close).toHaveBeenCalled();
    });

    it("still searches on Enter when nothing is highlighted", async () => {
        const { picker } = mount();

        picker.term = "skyline";
        picker.submit();

        // Enter has to mean both things, so the search has to still be reachable
        // once the arrows have not been used.
        expect(global.fetch).toHaveBeenCalledWith(
            "/pos/produk/cari",
            expect.objectContaining({
                body: new URLSearchParams({ q: "skyline" }),
            }),
        );
    });

    it("does not put anything in the cart when Enter only searches", async () => {
        const { picker } = mount();
        const picked = [];
        const record = (event) => picked.push(event.detail.sku);

        window.addEventListener("pos:add-item", record);

        picker.term = "hw";
        picker.submit();
        await vi.waitFor(() => expect(picker.items.length).toBeGreaterThan(0));
        window.removeEventListener("pos:add-item", record);

        // Results that just arrived are not a selection, however fresh they are.
        expect(picked).toEqual([]);
    });

    it("drops the highlight when a new search starts", async () => {
        const { picker } = await mountWithResults();

        picker.move(1);
        expect(picker.activeIndex).toBe(0);

        picker.term = "track";
        picker.search();

        // Row 2 of the previous result set is not row 2 of this one. Keeping the
        // highlight would point at a product the cashier was never shown.
        expect(picker.activeIndex).toBe(-1);
    });

    it("drops the highlight when the term is too short to search", async () => {
        const { picker } = await mountWithResults();

        picker.move(1);
        picker.term = "h";
        picker.search();

        expect(picker.activeIndex).toBe(-1);
        expect(picker.items).toHaveLength(0);
    });
});
