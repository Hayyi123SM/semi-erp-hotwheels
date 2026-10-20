import { describe, it, expect, beforeAll, afterEach, vi } from "vitest";
import Alpine from "alpinejs";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { registerMoneyModelDirective } from "./money";
import { stockInPribadiForm } from "./stock-in-pribadi";

/**
 * Field HPP/Unit Stock In Pribadi dijalankan lewat Alpine sungguhan.
 *
 * Tiga keputusan yang dibuat untuk field ini dan semuanya hanya bisa dilihat
 * perilakunya, bukan dari membaca markup:
 *
 *   1. Input yang terlihat memakai `x-money-model` (mask), bukan
 *      `x-model.number`. `x-model.number` menerima huruf dan tidak pernah
 *      memberi format; keduanya persis gejala yang dilaporkan kasir.
 *   2. Input yang terlihat sengaja tanpa `name`. `x-money-model` membuat
 *      cermin sendiri bila ada `name`, dan cermin itu tidak ikut terikat
 *      ulang ketika `idx` bergeser -- satu baris bisa terkirim dua kali
 *      dengan `name` basi.
 *   3. Yang dikirim server adalah angka polos lewat input tersembunyi
 *      `items[${idx}][cost_price]`, jadi server menerima `"45000"` dan bukan
 *      `"45.000"`.
 *
 * Unit test di `money.test.js` hanya menguji logika mask dengan `get`/`set`
 * buatan, sehingga tidak bisa membedakan directive yang terpasang benar dari
 * directive yang gagal load diam-diam (yang hanya memunculkan "Alpine
 * Expression Error" di konsol). Test ini menyalakan Alpine betulan.
 */

const BLADE = readFileSync(
    resolve(
        process.cwd(),
        "resources/views/pages/inbound/stock-in-pribadi.blade.php",
    ),
    "utf-8",
);

/**
 * Cerminan field HPP dari `stock-in-pribadi.blade.php`, termasuk `x-for`
 * yang membungkusnya.
 *
 * `x-for` ikut dicerminkan karena ia yang menentukan `idx`. Kalau ia dihapus
 * dari sini, test "nama tetap unik saat daftar bertambah" tidak akan gagal --
 * makanya strukturnya dicatat di sini.
 */
function markup() {
    return `
        <div x-data="stockInPribadiForm()">
            <template x-for="(item, idx) in items" :key="item.id">
                <div>
                    <input type="hidden" :name="\`items[\${idx}][product_id]\`" :value="item.product_id">
                    <input type="hidden" :name="\`items[\${idx}][cost_price]\`" :value="item.cost_price">
                    <input type="text"
                           inputmode="numeric"
                           autocomplete="off"
                           x-money-model="item.cost_price"
                           required
                           placeholder="0"
                           aria-label="HPP / Unit">
                </div>
            </template>
        </div>
    `;
}

let warn;
let error;
let activeHost;

beforeAll(() => {
    window.Alpine = Alpine;
    Alpine.data("stockInPribadiForm", stockInPribadiForm);
    registerMoneyModelDirective(Alpine);
    Alpine.start();

    // Alpine melaporkan kegagalan expression lewat console, bukan exception.
    warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    error = vi.spyOn(console, "error").mockImplementation(() => {});
});

afterEach(() => {
    if (activeHost) {
        Alpine.destroyTree(activeHost);
        activeHost.remove();
        activeHost = null;
    }

    warn.mockClear();
    error.mockClear();
});

async function render(html = markup()) {
    const host = document.createElement("div");
    host.innerHTML = html;
    document.body.appendChild(host);
    activeHost = host;

    await settle();

    return host;
}

/** Satu tick belum cukup: evaluator Alpine dijadwalkan sebagai microtask. */
async function settle() {
    await Alpine.nextTick();
    await Alpine.nextTick();
}

/** Ketik seperti orang: set nilainya lalu dispatch `input`. */
function typeInto(input, value) {
    input.value = value;
    input.dispatchEvent(new Event("input", { bubbles: true }));
}

function readScope(host) {
    return Alpine.$data(host.firstElementChild);
}

function expressionErrors() {
    return [...warn.mock.calls, ...error.mock.calls]
        .flat()
        .filter(
            (message) =>
                typeof message === "string" &&
                message.includes("Alpine Expression Error"),
        );
}

/** Tambah baris lewat scope, seperti operator yang memilih produk. */
async function addRow(host, row) {
    readScope(host).items.push({
        id: Math.floor(Math.random() * 1e9),
        product_id: 7,
        name: "Nissan Skyline GT-R R34",
        casting_code: "HWX-42",
        qty: 1,
        cost_price: "",
        card_condition: "MINT",
        blister_condition: "CLEAR",
        rack_id: "",
        ...row,
    });

    await settle();
}

function costPriceInputs(host) {
    return [...host.querySelectorAll('input[name$="[cost_price]"]')];
}

function visibleInputs(host) {
    return [...host.querySelectorAll("input[aria-label]")];
}

describe("field HPP di bawah Alpine", () => {
    it("input terlihat tidak mempunyai name, dan tidak membuat cermin", async () => {
        const host = await render();
        await addRow(host);

        const [visible] = visibleInputs(host);

        expect(visible.getAttribute("name")).toBeNull();
        expect(visible.type).toBe("text");
        expect(visible.getAttribute("inputmode")).toBe("numeric");

        // Barisnya hanya dua input tersembunyi milik form ini (product_id dan
        // cost_price). Bila `name` dikembalikan ke input terlihat,
        // `x-money-model` membuat cermin tersendiri dan jumlahnya jadi tiga.
        expect(host.querySelectorAll("input").length).toBe(3);
        expect(costPriceInputs(host)).toHaveLength(1);
    });

    it("membuang huruf dan mengelompokkan ribuan format Indonesia", async () => {
        const host = await render();
        await addRow(host);

        const [visible] = visibleInputs(host);

        typeInto(visible, "4a5b0c00");
        await settle();
        expect(visible.value).toBe("45.000");

        typeInto(visible, "1000000");
        await settle();
        expect(visible.value).toBe("1.000.000");
    });

    it("memanggang angka polos ke scope dan ke input tersembunyi", async () => {
        const host = await render();
        await addRow(host);

        const [visible] = visibleInputs(host);
        typeInto(visible, "45000");
        await settle();

        const scope = readScope(host);

        // Ini bagian yang tidak pernah diuji unit test mask: property scope
        // dan bentuk yang dikirim server. Kalau hanya teks di layar yang
        // berubah, server menerima `""` dan total HPP ikut salah tanpa satu
        // pun symptom di field.
        expect(scope.items[0].cost_price).toBe("45000");
        expect(costPriceInputs(host)[0].value).toBe("45000");
        expect(costPriceInputs(host)[0].name).toBe("items[0][cost_price]");
    });

    it("mempertahankan pemisahan kosong dan nol", async () => {
        const host = await render();
        await addRow(host);

        const [visible] = visibleInputs(host);

        typeInto(visible, "0");
        await settle();
        expect(readScope(host).items[0].cost_price).toBe("0");
        expect(visible.value).toBe("0");

        typeInto(visible, "");
        await settle();
        expect(readScope(host).items[0].cost_price).toBe("");
        expect(visible.value).toBe("");
    });

    it("menampilkan nilai yang datang dari server atau draft", async () => {
        const host = await render();
        await addRow(host, { cost_price: "45000" });
        await settle();

        // Tanpa `:value` di input terlihat, tampilan hanya bisa diisi lewat
        // effect `x-money-model` yang membaca scope. Kalau effect-nya tidak
        // jalan, operator melihat kolom kosong padahal datanya sudah ada --
        // terutama setelah form commit gagal dan `old()` mengisi ulang.
        expect(visibleInputs(host)[0].value).toBe("45.000");
    });

    it("menjaga nama input tetap unik ketika daftar bertambah", async () => {
        const host = await render();
        await addRow(host, { cost_price: "45000" });
        await addRow(host, { product_id: 8, name: "Toyota Supra" });
        await addRow(host, { product_id: 9, name: "Honda Civic" });

        const inputs = costPriceInputs(host);

        expect(inputs).toHaveLength(3);
        expect(inputs.map((input) => input.name)).toEqual([
            "items[0][cost_price]",
            "items[1][cost_price]",
            "items[2][cost_price]",
        ]);
        expect(
            inputs.map((input) => input.value),
        ).toEqual(["45000", "", ""]);
        expect(
            visibleInputs(host).map((input) => input.getAttribute("name")),
        ).toEqual([null, null, null]);
    });

    it("tidak menghasilkan satu pun Alpine Expression Error", async () => {
        const host = await render();
        await addRow(host);

        const [visible] = visibleInputs(host);
        typeInto(visible, "150000");
        await settle();
        typeInto(visible, "");
        await settle();

        expect(expressionErrors()).toEqual([]);
    });
});

describe("blade memakai bentuk yang sama dengan test ini", () => {
    it("memakai mask, bukan x-model.number", () => {
        expect(BLADE).toContain('x-money-model="item.cost_price"');
        expect(BLADE).not.toContain('x-model.number="item.cost_price"');
    });

    it("tidak lagi memakai step ataupun x-model.number", () => {
        // `step="1000"` dan `x-model.number` membatasi input ke kelipatan
        // seribu dan tidak pernah memberi format, padahal harga beli boleh
        // di bawah itu.
        expect(BLADE).not.toContain('step="1000"');
        expect(BLADE).not.toContain('x-model.number="item.cost_price"');
    });

    it("input terlihat tanpa name dan membawa input tersembunyi", () => {
        expect(BLADE).toContain(
            '<input type="hidden" :name="`items[${idx}][cost_price]`" :value="item.cost_price">',
        );
    });

    it("struktur x-for tercatat di test", () => {
        expect(BLADE).toContain(
            '<template x-for="(item, idx) in items" :key="item.id">',
        );
    });
});
