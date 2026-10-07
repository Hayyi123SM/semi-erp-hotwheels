import { describe, it, expect, beforeAll, afterEach, vi } from 'vitest';
import Alpine from 'alpinejs';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { labelQueue } from './label-queue';
import { reprintForm } from './reprint-form';

/**
 * Select-all di kedua tabel diuji dengan Alpine sungguhan, bukan dengan
 * pemanggilan method langsung.
 *
 * Bagian yang rapuh justru bagian yang tidak punya method sama sekali:
 * `x-model.number` di checkbox baris, `:checked` di header, dan penyetelan
 * `indeterminate`. Kalau salah satu nama bindingnya salah, unit test tetap
 * hijau -- method-nya memang benar, tidak ada yang memanggilnya dengan cara
 * yang salah. Gejalanya di browser adalah checkbox header yang tidak pernah
 * berubah, atau state `selected` yang tetap kosong setelah operator menekan
 * "pilih semua".
 *
 * Alpine melaporkan kegagalan expression lewat console, bukan exception, jadi
 * test yang tidak spying ke `console.error` akan lolos meski gagal.
 */

const BLADE = readFileSync(
    resolve(process.cwd(), 'resources/views/pages/inbound/cetak-label.blade.php'),
    'utf-8'
);

let expressionErrors;
let host;

/**
 * Markup di bawah adalah cerminan bagian select-all di blade. Test
 * `markup select-all di blade` gagal kalau blade sudah berubah dan cerminannya
 * belum.
 */
function queueMarkup() {
    return `
        <div x-data="labelQueue({ 1: 'QUEUED', 2: 'QUEUED', 3: 'QUEUED' }, [1, 2, 3])">
            <input type="checkbox" name="ids[]" value="1" x-model.number="selected">
            <input type="checkbox" name="ids[]" value="2" x-model.number="selected">
            <input type="checkbox" name="ids[]" value="3" x-model.number="selected">
            <input type="checkbox" x-ref="selectAll" :checked="allSelected()"
                   :aria-checked="someSelected() ? 'mixed' : allSelected()"
                   @change="toggleAll()">
        </div>
    `;
}

function reprintMarkup() {
    return `
        <form x-data="reprintForm({ endpoint: '/x', redirectTo: '/y', context: 'inventory.label-overprint' }, [7, 8])">
            <input type="checkbox" name="lot_ids[]" value="7" x-model.number="selected">
            <input type="checkbox" name="lot_ids[]" value="8" x-model.number="selected">
            <input type="checkbox" x-ref="selectAll" :checked="allSelected()"
                   :aria-checked="someSelected() ? 'mixed' : allSelected()"
                   @change="toggleAll()">
            <button type="submit" :disabled="busy || ! canSubmit()">Buat</button>
        </form>
    `;
}

function render(markup) {
    host = document.createElement('div');
    host.innerHTML = markup;
    document.body.append(host);
    Alpine.initTree(host);

    return host;
}

function checkboxes(root) {
    return [...root.querySelectorAll('input[type=checkbox]')];
}

function header(root) {
    return root.querySelector('[x-ref="selectAll"]');
}

function submitButton(root) {
    return root.querySelector('button');
}

/** Beri Alpine kesempatan menyelesaikan flush reaction-nya. */
function settle() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

beforeAll(() => {
    window.Alpine = Alpine;
    Alpine.data('labelQueue', labelQueue);
    Alpine.data('reprintForm', reprintForm);
    Alpine.start();

    // Alpine melaporkan expression yang gagal lewat console, bukan exception.
    expressionErrors = [];
    vi.spyOn(console, 'warn').mockImplementation((...args) => {
        expressionErrors.push(args.join(' '));
    });
    vi.spyOn(console, 'error').mockImplementation((...args) => {
        expressionErrors.push(args.join(' '));
    });
});

afterEach(() => {
    // Tanpa destroyTree, listener yang diikat Alpine ikut nyangkut dan test
    // berikutnya jadi sulit ditelusuri.
    if (host) {
        Alpine.destroyTree(host);
        host.remove();
        host = null;
    }

    expressionErrors = [];
});

describe('select all pada antrean', () => {
    it('mencentang semua baris saat header ditekan', async () => {
        const root = render(queueMarkup());
        const rows = checkboxes(root);

        header(root).click();
        await settle();

        expect(rows[0].checked).toBe(true);
        expect(rows[2].checked).toBe(true);
    });

    it('membaca nilai baris sebagai angka, bukan string', async () => {
        const root = render(queueMarkup());
        const rows = checkboxes(root);

        rows[0].click();
        await settle();

        // `x-model.number` yang rusak akan menaruh string di `selected`, dan
        // perbandingan `selected.includes(jobId)` ikut gagal diam-diam -- header
        // kelihatan benar tapi tidak pernah menyala.
        expect(rows[0].checked).toBe(true);
        expect(root.querySelector('div')._x_dataStack[0].selected).toEqual([1]);
    });

    it('menandai header sebagai indeterminate saat hanya sebagian baris terpilih', async () => {
        const root = render(queueMarkup());

        checkboxes(root)[1].click();
        await settle();

        expect(header(root).indeterminate).toBe(true);
        expect(header(root).checked).toBe(false);
        expect(header(root).getAttribute('aria-checked')).toBe('mixed');
    });

    it('menandai header sebagai tercentang penuh setelah semua baris dipilih', async () => {
        const root = render(queueMarkup());
        const rows = checkboxes(root);

        rows[0].click();
        rows[1].click();
        rows[2].click();
        await settle();

        expect(header(root).checked).toBe(true);
        expect(header(root).indeterminate).toBe(false);
    });

    it('mengosongkan semua baris saat header ditekan dua kali', async () => {
        const root = render(queueMarkup());
        const rows = checkboxes(root);

        header(root).click();
        await settle();
        header(root).click();
        await settle();

        expect(rows.every((row) => !row.checked)).toBe(true);
        expect(header(root).checked).toBe(false);
        expect(header(root).indeterminate).toBe(false);
    });

    it('tidak menyalakan error Alpine saat select-all dipakai', async () => {
        const root = render(queueMarkup());

        header(root).click();
        await settle();
        header(root).click();
        await settle();

        expect(expressionErrors).toEqual([]);
    });
});

describe('select all pada cetak ulang', () => {
    it('menocentangi lot dan menghidupkan tombol kirim', async () => {
        const root = render(reprintMarkup());
        const rows = checkboxes(root);

        expect(submitButton(root).disabled).toBe(true);

        header(root).click();
        await settle();

        expect(rows[0].checked).toBe(true);
        expect(rows[1].checked).toBe(true);
        expect(submitButton(root).disabled).toBe(false);
    });

    it('mematikan lagi tombol kirim saat semua lot dibatalkan', async () => {
        const root = render(reprintMarkup());

        header(root).click();
        await settle();
        header(root).click();
        await settle();

        expect(submitButton(root).disabled).toBe(true);
        expect(header(root).indeterminate).toBe(false);
    });

    it('menandai header sebagai indeterminate untuk sebagian lot', async () => {
        const root = render(reprintMarkup());

        checkboxes(root)[0].click();
        await settle();

        expect(header(root).indeterminate).toBe(true);
        expect(submitButton(root).disabled).toBe(false);
    });

    it('tidak menyalakan error Alpine saat select-all dipakai', async () => {
        const root = render(reprintMarkup());

        header(root).click();
        await settle();
        header(root).click();
        await settle();

        expect(expressionErrors).toEqual([]);
    });
});

describe('markup select-all di blade', () => {
    it('tetap memakai binding yang sama dengan yang diuji di sini', () => {
        // Dua header, dua baris. Kalau blade berubah dan test ini belum, yang
        // benar di test sudah tidak benar di browser.
        expect(BLADE.match(/x-ref="selectAll"/g)).toHaveLength(2);
        expect(BLADE.match(/@change="toggleAll\(\)"/g)).toHaveLength(2);
        expect(BLADE.match(/x-model\.number="selected"/g)).toHaveLength(2);
        expect(BLADE).toContain("someSelected() ? 'mixed' : allSelected()");
        expect(BLADE).toContain(':disabled="busy || ! canSubmit()"');
    });
});
