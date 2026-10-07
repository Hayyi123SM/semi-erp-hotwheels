import { describe, it, expect, beforeAll, afterEach, vi } from 'vitest';
import Alpine from 'alpinejs';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { registerMoneyModelDirective } from './money';

/**
 * Menjalankan input nominal kasir melalui Alpine sungguhan.
 *
 * Laporan dari kasir: "format nominalnya belum jalan, dan huruf masih
 * diterima". Itu persis gejala directive yang tidak pernah jalan -- mask-lah yang
 * membuang huruf, jadi kalau huruf lolos berarti tidak ada yang membuangnya.
 *
 * Unit test di `money.test.js` menguji `attachMoneyModel()` dengan callback
 * `get`/`set` buatan, jadi ia hanya menguji logika mask-nya. Ia tidak pernah
 * menyentuh directive-nya, dan tidak akan bisa membedakan dua keadaan ini:
 *
 *   - directive-nya terdaftar dan menulis balik ke scope dengan benar;
 *   - directive-nya gagal load atau evaluasinya salah, sehingga Alpine
 *     mengabaikannya diam-diam. Kegagalan seperti itu tidak melempar
 *     exception ke mana pun -- hanya "Alpine Expression Error" yang muncul di
 *     konsol, dan tidak pernah jadi test gagal.
 *
 * Test ini menyalakan Alpine betulan, memasang directive-nya, lalu mengetik ke
 * field seperti yang dilakukan kasir.
 */

const BLADE = readFileSync(
    resolve(process.cwd(), 'resources/views/pages/pos/kasir.blade.php'),
    'utf-8'
);

/**
 * Cerminan input di `kasir.blade.php`, termasuk `x-data` membungkusnya.
 *
 * `x-data="{ showNumpad: true }"` ikut dicerminkan karena ia membungkus field
 * dan karena itu ikut menentukan rantai resolusi scope. Kalau ia dihapus dari
 * blade, test `blade dan test memakai directive yang sama` tidak akan gagal --
 * makanya `blade dan test memakai pembungkus yang sama` yang menjaganya.
 */
function markup() {
    return `
        <div x-data="cashier()">
            <div x-data="{ showNumpad: true }">
                <input type="text"
                       inputmode="numeric"
                       autocomplete="off"
                       x-money-model="tender"
                       aria-label="Uang diterima">
            </div>
        </div>
    `;
}

/**
 * State kasir yang(test ini benar-benar butuh: `tender` buat field-nya,
 * `change` untuk membuktikan penulisan ke scope benar-benar mengubah
 * hitungan -- bukan hanya teks yang bergerak di layar.
 */
function cashier() {
    return {
        tender: '',
        subtotal: 1500000,
        get change() {
            return Math.max(0, this.subtotal - Number(this.tender || 0));
        },
        quickTender(amount) {
            this.tender = String(amount);
        },
    };
}

let warn;
let error;
let activeHost;

beforeAll(() => {
    window.Alpine = Alpine;
    Alpine.data('cashier', cashier);
    registerMoneyModelDirective(Alpine);
    Alpine.start();

    // Alpine melaporkan kegagalan expression lewat console, bukan exception.
    warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    error = vi.spyOn(console, 'error').mockImplementation(() => {});
});

afterEach(() => {
    if (activeHost) {
        // Tanpa destroyTree, directive yang sudah terpasang ikut nyangkut dan
        // test berikutnya jadi sulit ditelusuri.
        Alpine.destroyTree(activeHost);
        activeHost.remove();
        activeHost = null;
    }

    warn.mockClear();
    error.mockClear();
});

/**
 * `Alpine.start()` memasang MutationObserver di `document.body`, jadi append
 * lewat situ saja sudah cukup untuk menginisialisasi subtree.
 */
async function render(html = markup()) {
    const host = document.createElement('div');
    host.innerHTML = html;
    document.body.appendChild(host);
    activeHost = host;

    await settle();

    return host;
}

/**
 * Satu tick saja ternyata belum cukup: effect directive membaca property lewat
 * evaluator yang dijadwalkan sebagai microtask terpisah, jadi nilainya belum
 * sampai saat tick pertama selesai.
 */
async function settle() {
    await Alpine.nextTick();
    await Alpine.nextTick();
}

/** Ketik seperti orang: set nilainya lalu dispatch `input`. */
function typeInto(input, value) {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

function readScope(host) {
    return Alpine.$data(host.firstElementChild);
}

function expressionErrors() {
    return [...warn.mock.calls, ...error.mock.calls]
        .flat()
        .filter(
            (message) =>
                typeof message === 'string' && message.includes('Alpine Expression Error')
        );
}

describe('x-money-model: field nominal kasir di bawah Alpine', () => {
    it('membuang huruf dan mengelompokkan ribuan format Indonesia', async () => {
        const host = await render();
        const input = host.querySelector('input');

        typeInto(input, '1a2b3c');
        await settle();
        expect(input.value).toBe('123');

        typeInto(input, '1000');
        await settle();
        expect(input.value).toBe('1.000');

        typeInto(input, '1000000');
        await settle();
        expect(input.value).toBe('1.000.000');
    });

    it('memanggang apa yang diketik ke property scope-nya', async () => {
        const host = await render();
        const input = host.querySelector('input');

        typeInto(input, '50000');
        await settle();

        // Ini yang tidak pernah diuji unit test mana pun: bukan hanya teks di
        // layar yang berubah, tapi property-nya juga. Kalau hanya satu arah
        // yang jalan, `change` tetap `Rp0` dan uang kembaliannya jauh-jauh
        // salah tanpa satu pun symptom yang terlihat di field.
        expect(readScope(host).tender).toBe('50000');
        expect(readScope(host).change).toBe(1450000);
    });

    it('mempertahankan pemisahan kosong dan nol', async () => {
        const host = await render();
        const input = host.querySelector('input');

        typeInto(input, '0');
        await settle();
        expect(readScope(host).tender).toBe('0');

        // `''` dan `'0'` berbeda -- tidak ada yang diketik versus nol yang
        // diketik -- dan angka tidak bisa membedakannya, jadi keduanya harus
        // tiba sebagai string.
        typeInto(input, '');
        await settle();
        expect(readScope(host).tender).toBe('');
    });

    it('meminta konfirmasi satu digit per digit dan zehn digit', async () => {
        const host = await render();
        const input = host.querySelector('input');

        typeInto(input, '0');
        await settle();
        expect(input.value).toBe('0');

        typeInto(input, '00000000');
        await settle();
        expect(input.value).toBe('0');

        typeInto(input, '0001');
        await settle();
        expect(input.value).toBe('1');
    });

    it('menampilkan property yang diisi dari kode ke dalam field', async () => {
        const host = await render();
        const input = host.querySelector('input');

        // `quickTender()` di kasir menugaskan property lalu mengandalkan effect
        // directive untuk repaint field. Jalur ini wajib hidup, kalau tidak
        // tombol quick cash hanya mengubah hitungan tanpa pernah terlihat
        // diketik.
        readScope(host).quickTender(75000);
        await settle();

        expect(input.value).toBe('75.000');
    });

    it('membiarkan uang pas mengisi field dan mengosongkannya', async () => {
        const host = await render();
        const input = host.querySelector('input');
        const scope = readScope(host);

        scope.quickTender(scope.subtotal);
        await settle();
        expect(input.value).toBe('1.500.000');
        expect(scope.change).toBe(0);

        // Reset setelah penjualan. Kalau directive tidak membedakan "kosong"
        // dari angka, field akan menampilkan `0` dan penanda cukup uang ikut
        // hilang setelah transaksi selesai.
        scope.tender = '';
        await settle();
        expect(input.value).toBe('');
    });

    it('tidak menghasilkan satu pun "Alpine Expression Error"', async () => {
        const host = await render();
        const input = host.querySelector('input');

        typeInto(input, '150000');
        await settle();
        typeInto(input, '');
        await settle();
        readScope(host).quickTender(50000);
        await settle();

        expect(expressionErrors()).toEqual([]);
    });

    it('tetap berfungsi di dalam x-data bersarang', async () => {
        const host = await render();
        const input = host.querySelector('input');

        // Field-nya dibungkus `x-data` lain. Kalau directive mencari property
        // di scope yang salah, evaluatornya diam-diam tidak menulis apa pun
        // dan property di luar sana tidak pernah berubah.
        typeInto(input, '25000');
        await settle();

        expect(readScope(host).tender).toBe('25000');
        expect(expressionErrors()).toEqual([]);
    });

    it('blade dan test memakai directive yang sama', () => {
        expect(BLADE).toContain('x-money-model="tender"');
    });

    it('blade dan test memakai pembungkus yang sama', () => {
        // Menjaga `x-data` yang membungkus field tetap tercermin di sini, jadi
        // test ini terus menguji struktur scope yang benar-benar di-deploy.
        expect(BLADE).toContain('x-data="{ showNumpad: true }"');
    });
});