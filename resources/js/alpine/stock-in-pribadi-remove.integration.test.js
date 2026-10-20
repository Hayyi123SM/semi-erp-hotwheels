import { describe, it, expect, beforeAll, afterEach, vi } from "vitest";
import Alpine from "alpinejs";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { stockInPribadiForm } from "./stock-in-pribadi";

/**
 * Ikon hapus pada baris Stock In Pribadi dijalankan lewat Alpine sungguhan.
 *
 * Baris ini bersebelahan dengan seluruh isian baris -- qty, HPP, kondisi kartu
 * dan blister, rak. Salah klik berarti kehilangan pekerjaan yang barusan
 * diisi, jadi ikonnya tidak boleh langsung `splice`. Unit test di
 * `stock-in-pribadi.test.js` membuktikan keputusan di dalam `remove()`;
 * yang hanya bisa dibuktikan di sini adalah *pemasangannya*: blade harus
 * benar-benar memanggil `remove(item.id)`.
 *
 * Kalau blade memanggil `remove(idx)`, komponen mencari `item.id === 1` dan
 * tidak menemukan apa pun, sehingga ikon hapus berhenti bekerja diam-diam --
 * tidak ada exception, tidak ada error di konsol, hanya baris yang tidak mau
 * hilang. Test pertama di bawah adalah test itu.
 */

const BLADE = readFileSync(
    resolve(
        process.cwd(),
        "resources/views/pages/inbound/stock-in-pribadi.blade.php",
    ),
    "utf-8",
);

/**
 * Cerminan baris dari `stock-in-pribadi.blade.php`, termasuk `x-for` yang
 * menentukan `idx` dan `item` yang dipakai tombolnya.
 */
function markup() {
    return `
        <div x-data="stockInPribadiForm()">
            <template x-for="(item, idx) in items" :key="item.id">
                <div :data-row-idx="idx">
                    <span class="row-name" x-text="item.name"></span>
                    <button type="button"
                            @click="remove(item.id)"
                            :title="\`Hapus \${item.name} dari daftar\`"
                            aria-label="Hapus baris">Hapus</button>
                </div>
            </template>
        </div>
    `;
}

const ROW = {
    product_id: 7,
    name: "Nissan Skyline GT-R R34",
    casting_code: "HWX-42",
    qty: 2,
    cost_price: "45000",
};

let warn;
let error;
let activeHost;

beforeAll(() => {
    window.Alpine = Alpine;
    Alpine.data("stockInPribadiForm", stockInPribadiForm);
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

    delete window.notify;
    warn.mockClear();
    error.mockClear();
});

async function render(html = markup()) {
    const host = document.createElement("div");
    host.innerHTML = html;
    document.body.appendChild(host);
    activeHost = host;

    await settle();

    readScope(host).items.push({ ...ROW });
    await settle();

    return host;
}

/** Satu tick belum cukup: evaluator Alpine dijadwalkan sebagai microtask. */
async function settle() {
    await Alpine.nextTick();
    await Alpine.nextTick();
}

/**
 * Menunggu `remove()` -- yang melewati beberapa `await` di dalam
 * `confirmRemove` -- sampai selesai, baru menunggu Alpine menggambar ulang
 * `x-for`-nya. Satu `nextTick` saja tidak cukup untuk keduanya sekaligus.
 */
async function flush() {
    await new Promise((done) => setTimeout(done, 0));
    await settle();
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

function stubDangerConfirm(confirmed) {
    const dangerConfirm = vi.fn(async () => confirmed);

    window.notify = { dangerConfirm };

    return dangerConfirm;
}

describe("ikon hapus baris di bawah Alpine", () => {
    it("menghapus baris yang diklik setelah konfirmasi", async () => {
        const host = await render();
        stubDangerConfirm(true);

        host.querySelector("button[aria-label]").click();
        await flush();

        expect(host.querySelector(".row-name")).toBeNull();
        expect(readScope(host).items).toHaveLength(0);
        expect(expressionErrors()).toEqual([]);
    });

    it("membiarkan baris tetap ada saat dibatalkan", async () => {
        const host = await render();
        const dangerConfirm = stubDangerConfirm(false);

        host.querySelector("button[aria-label]").click();
        await flush();

        expect(dangerConfirm).toHaveBeenCalledOnce();
        expect(host.querySelector(".row-name").textContent).toBe(
            "Nissan Skyline GT-R R34",
        );
        expect(readScope(host).items).toHaveLength(1);
        expect(expressionErrors()).toEqual([]);
    });
});

describe("blade memanggil remove dengan id barisnya", () => {
    it("memakai item.id, bukan idx", () => {
        // `remove()` di komponen mencari baris lewat `item.id`. `idx` akan
        // lolos tanpa error dan tanpa menghapus apa pun.
        expect(BLADE).toContain('@click="remove(item.id)"');
        expect(BLADE).not.toContain('@click="remove(idx)"');
    });

    it("menempel di tombol yang sama dengan test ini", () => {
        expect(BLADE).toContain('aria-label="Hapus baris"');
        expect(BLADE).toContain(':title="`Hapus ${item.name} dari daftar`"');
    });
});
