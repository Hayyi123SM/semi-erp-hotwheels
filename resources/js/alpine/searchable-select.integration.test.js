import { describe, it, expect, beforeAll, afterEach, vi } from 'vitest';
import Alpine from 'alpinejs';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { searchableSelect } from './searchable-select';

/**
 * Menjalankan markup komponen yang sama persis dengan blade-nya melalui Alpine
 * sungguhan.
 *
 * Unit test di `searchable-select.test.js` menyusun `$refs` sendiri, jadi
 * bentuk markup-nya bukan yang dipakai browser. Dua bug pernah lolos dari sana:
 * `invalid` tidak pernah masuk ke scope Alpine, dan `<select>` dari slot tidak
 * pernah punya `x-ref` sehingga `$refs.select` undefined. Keduanya hanya
 * terlihat sebagai "Alpine Expression Error" di konsol -- test yang hanya
 * memanggil method secara langsung tidak akan pernah menyentuhnya.
 *
 * Markup di bawah adalah cerminan `searchable-select.blade.php`; test
 * `blade dan test memakai ref yang sama` gagal kalau keduanya sudah berbeda jauh.
 */

const BLADE = readFileSync(
    resolve(process.cwd(), 'resources/views/components/ui/searchable-select.blade.php'),
    'utf-8'
);

function markup({ invalid = false } = {}) {
    return `
        <div x-data="searchableSelect({ placeholder: '— pilih —', invalid: ${invalid} })"
             @keydown.esc.window="closePanel()">
            <button type="button" x-ref="button" @click="toggle()" @keydown="onTriggerKeydown($event)"
                    :aria-expanded="open" aria-haspopup="listbox"
                    class="flex h-11 w-full"
                    :class="triggerClass">
                <span :class="displayLabel === placeholder ? 'text-text-muted' : 'text-text-strong'"
                      x-text="displayLabel"></span>
            </button>

            <select class="sr-only">
                <option value="">— pilih —</option>
                <option value="orange">Orange</option>
                <option value="purple">Purple</option>
            </select>

            <div x-ref="panel" x-show="open" x-cloak
                 class="fixed z-50 flex max-h-80 flex-col">
                <input x-ref="search" type="text" :value="query"
                       @input="query = $el.value; settleActiveIndex(); reposition()"
                       @keydown="onSearchKeydown($event)">
                <ul>
                    <template x-for="(option, index) in options" :key="option.value">
                        <li @mousedown.prevent="choose(index)" x-text="option.label"></li>
                    </template>
                    <li x-show="options.length === 0">Tidak ditemukan</li>
                </ul>
            </div>
        </div>
    `;
}

let expressionErrors;
let warn;
let error;
let activeHost;

beforeAll(() => {
    window.Alpine = Alpine;
    Alpine.data('searchableSelect', searchableSelect);
    Alpine.start();

    // Alpine melaporkan kegagalan expression lewat console, bukan exception.
    warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    error = vi.spyOn(console, 'error').mockImplementation(() => {});
});

afterEach(() => {
    if (activeHost) {
        // Tanpa destroyTree, listener global yang diikat `listen()` ikut nyangkut
        // dan inflammation test berikutnya jadi sulit ditelusuri.
        Alpine.destroyTree(activeHost);
        activeHost.remove();
        activeHost = null;
    }

    warn.mockClear();
    error.mockClear();
    expressionErrors = [];
});

/**
 * `Alpine.start()` sudah memasang MutationObserver di `document.body`, jadi
 * append lewat situ saja sudah cukup untuk menginisialisasi subtree. Memanggil
 * `Alpine.initTree()` manual justru memicu inisialisasi ganda: `@click` terpasang
 * dua kali sehingga satu klik membuka lalu langsung menutup panel lagi.
 */
async function render(html) {
    const host = document.createElement('div');
    host.innerHTML = html;
    document.body.appendChild(host);
    activeHost = host;

    await Alpine.nextTick();

    const root = host.firstElementChild;
    stubLayout(root);

    expressionErrors = [...warn.mock.calls, ...error.mock.calls]
        .flat()
        .filter((message) => typeof message === 'string' && message.includes('Alpine Expression Error'));

    return host;
}

/**
 * happy-dom tidak punya layout engine, jadi `getBoundingClientRect()` dan
 * `offsetHeight` selalu nol. Tanpa ini komponen menganggap pemicunya sudah
 * keluar layar dan menolak membuka panel.
 */
function stubLayout(root) {
    window.innerWidth = 1280;
    window.innerHeight = 900;

    const trigger = root.querySelector('button');
    trigger.getBoundingClientRect = () => ({
        top: 300,
        bottom: 344,
        left: 40,
        right: 320,
        width: 280,
        height: 44,
        x: 40,
        y: 300,
    });

    const panel = root.querySelector('[x-ref="panel"]');
    Object.defineProperty(panel, 'offsetHeight', { configurable: true, get: () => 240 });
}

describe('searchable-select: markup nyata di bawah Alpine', () => {
    it('tidak menghasilkan satu pun "Alpine Expression Error"', async () => {
        const host = await render(markup());
        const button = host.querySelector('button');

        // Membuka panel menyentuh displayLabel, options, dan kelas tombol --
        // ketiga tempat dua bug lama itu muncul.
        button.click();

        expect(expressionErrors).toEqual([]);
    });

    it('menampilkan placeholder di tombol lalu label terpilih setelah memilih', async () => {
        const host = await render(markup());
        const button = host.querySelector('button');
        const label = host.querySelector('span');

        expect(label.textContent).toBe('— pilih —');

        button.click();
        await Alpine.nextTick();

        const options = Array.from(document.querySelectorAll('li'))
            .filter((li) => li.textContent.trim() !== 'Tidak ditemukan');

        expect(options.length).toBe(2);
        options[1].dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));

        await Alpine.nextTick();
        expect(host.querySelector('select').value).toBe('purple');
        expect(label.textContent).toBe('Purple');
    });

    it('panel yang pindah ke body tetap menjangkau scope komponen', async () => {
        const host = await render(markup());
        const root = host.firstElementChild;
        const search = host.querySelector('[x-ref="search"]');

        host.querySelector('button').click();
        await Alpine.nextTick();

        const panel = search.parentElement;
        expect(panel.parentNode).toBe(document.body);

        // Alpine mencari scope lewat `closestDataStack`, yang menelusuri
        // ancestry DOM. Di dalam <body> penelusuran itu berakhir di `[]`, jadi
        // `x-for` di panel meng-clone <li> yang dievaluasi tanpa `activeIndex`
        // dan `options` -- "Alpine Expression Error: activeIndex is not
        // defined" setiap kali daftar difilter atau keyword dihapus.
        expect(Alpine.closestDataStack(panel)).toContain(root._x_dataStack[0]);
    });

    it('tetap punya scope komponen saat daftar difilter lalu dikosongkan', async () => {
        const host = await render(markup());
        const search = host.querySelector('[x-ref="search"]');

        host.querySelector('button').click();
        await Alpine.nextTick();

        // Setelah `portal()` panel hidup di <body>, jadi dicari lewat input
        // yang di-pin di dalam panel, bukan lewat `host`.
        const panel = search.parentElement;

        const labels = () => Array.from(panel.querySelectorAll('li'))
            .filter((li) => li.style.display !== 'none')
            .map((li) => li.textContent.trim());

        search.value = 'or';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        await Alpine.nextTick();
        expect(labels()).toEqual(['Orange']);

        // Menghapus keyword memaksa `x-for` meng-clone ulang <li>. Kalau panel
        // sudah tidak bisa menjangkau scope komponennya, clone itu dievaluasi
        // tanpa `activeIndex` -- inilah yang memunculkan "Alpine Expression
        // Error: activeIndex is not defined" di konsol browser.
        search.value = '';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        await Alpine.nextTick();

        expect(labels()).toEqual(['Orange', 'Purple']);
        expect(expressionErrors).toEqual([]);
    });

    it('menyalakan event change sehingga x-model pada baris grid ikut ter-update', async () => {
        const host = await render(markup());
        const select = host.querySelector('select');
        let seen = null;
        select.addEventListener('change', (event) => { seen = event.target.value; });

        host.querySelector('button').click();
        await Alpine.nextTick();
        document.querySelector('li').dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));

        await Alpine.nextTick();
        expect(seen).toBe('orange');
    });

    it('menerapkan kelas error saat prop invalid true', async () => {
        const host = await render(markup({ invalid: true }));
        const button = host.querySelector('button');

        // Diperiksa lewat nilai binding-nya, bukan `class` di DOM: happy-dom
        // membekukan atribut class begitu efek `:class` Alpine menyentuh
        // elemen itu -- `setAttribute` dan `className` ikut jadi tidak
        // berefek setelahnya. itu purely aftermarket happy-dom; di browser
        // kelasnya benar-benar terpasang.
        expect(button._x_bindings.class).toContain('border-error-border');
        expect(expressionErrors).toEqual([]);
    });

    it('blade dan test memakai ref yang sama', () => {
        for (const ref of ['button', 'panel', 'search']) {
            expect(BLADE).toContain(`x-ref="${ref}"`);
            expect(markup()).toContain(`x-ref="${ref}"`);
        }

        // Select tetap datang dari slot; kalau suatu saat blade membawanya
        // sendiri, referensinya harus ikut dikembalikan di sini.
        expect(BLADE).toContain('{{ $slot }}');
    });
});
