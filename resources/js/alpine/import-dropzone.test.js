import { describe, it, expect } from 'vitest';
import { importDropzone } from './import-dropzone';

/**
 * The component exists to fix a bug the markup could not fix alone: the old
 * upload area looked like a dropzone but only ever responded to a click. So
 * these tests stand in a real form with a real file input and drive real drop
 * events. "The input receives the file" and "the page does not navigate away"
 * are the two halves of the fix, and neither is observable from a stub.
 *
 * `dataTransfer.files` is a plain array rather than a real `FileList`: the
 * component only ever assigns it to `input.files` and reads `length` and index
 * 0, so an array exercises the same path without fighting happy-dom's setter.
 */
function mount(options = {}) {
    const form = document.createElement('form');
    form.submit = () => {
        throw new Error('form harus tidak terkirim');
    };

    const input = document.createElement('input');
    input.type = 'file';
    input.name = 'import_file';
    form.append(input);

    const scope = importDropzone(options);
    scope.$refs = { input };

    return { scope, form, input };
}

/**
 * A file with a genuine byte length, because the size rule reads `file.size`
 * and a one-character stand-in would sail past the limit it is meant to trip.
 */
function file(name, { size = 1024 } = {}) {
    return new File([new Uint8Array(size)], name, { type: 'application/octet-stream' });
}

function dropEvent(files) {
    const event = new Event('drop', { bubbles: true, cancelable: true });

    event.dataTransfer = { files, items: [], types: ['Files'] };

    return event;
}

describe('importDropzone: menerima berkas yang dijatuhkan', () => {
    it('menulis berkas drop ke input sehingga form punya isian', () => {
        const { scope, input } = mount();

        scope.drop(dropEvent([file('katalog.xlsx')]));

        expect(input.files).toHaveLength(1);
        expect(input.files[0].name).toBe('katalog.xlsx');
    });

    it('menahan peramban agar halaman tidak pindah ke berkas yang dijatuhkan', () => {
        const { scope } = mount();

        const event = dropEvent([file('katalog.xlsx')]);
        scope.drop(event);

        // Tanpa ini, browser memperlakukan berkas yang dijatuhkan sebagai
        // tautan: halaman pindah ke berkas itu dan seluruh isian form hilang.
        expect(event.defaultPrevented).toBe(true);
    });

    it('membuang berkas yang ekstensinya tidak diterima', () => {
        const { scope, input } = mount();

        scope.drop(dropEvent([file('catatan.txt')]));

        expect(input.files).toHaveLength(0);
        expect(scope.error).toContain('.xlsx');
        expect(scope.fileName).toBe('');
    });

    it('membuang berkas yang melebihi batas ukuran', () => {
        const { scope, input } = mount({ maxBytes: 512 });

        scope.drop(dropEvent([file('besar.xlsx', { size: 4096 })]));

        expect(input.files).toHaveLength(0);
        expect(scope.error).toContain('melebihi batas');
    });

    it('menolak drop lebih dari satu berkas dan mengosongkan input', () => {
        const { scope, input } = mount();

        scope.drop(dropEvent([file('a.xlsx'), file('b.xlsx')]));

        expect(input.files).toHaveLength(0);
        expect(scope.error).toContain('satu berkas');
    });

    it('tidak melakukan apa-apa bila drop tidak membawa berkas', () => {
        const { scope, input } = mount();

        scope.drop(dropEvent([]));

        expect(input.files).toHaveLength(0);
        expect(scope.error).toBe('');
    });
});

describe('importDropzone: berkas yang dipilih lewat dialog picker', () => {
    it('memakai jalur yang sama dengan drop supaya nama berkas tetap tampil', () => {
        const { scope, input } = mount();
        const files = [file('rak.csv')];
        input.files = files;

        scope.picked({ target: { files } });

        expect(scope.fileName).toBe('rak.csv');
        expect(scope.error).toBe('');
    });

    it('menolak berkas dari picker dan mengosongkan input', () => {
        const { scope, input } = mount();
        input.files = [file('gambar.png')];

        scope.picked({ target: { files: input.files } });

        expect(input.files).toHaveLength(0);
        expect(scope.error).toContain('.xlsx');
    });
});

describe('importDropzone: status saat sedang diseret', () => {
    it('menyala saat pointer masuk', () => {
        const { scope } = mount();

        scope.dragEnter();

        expect(scope.dragging).toBe(true);
    });

    it('tetap menyala saat pointer menyeberangi anak-anaknya', () => {
        const { scope } = mount();

        // Satu gerakan melewati isi dropzone menyalakan dragenter lalu
        // dragleave untuk tiap elemen. Tanpa penghitung, penanda "sedang
        // diseret" berkedip di tengah gerakan yang biasa saja.
        scope.dragEnter();
        scope.dragEnter();
        scope.dragLeave();

        expect(scope.dragging).toBe(true);
    });

    it('padam hanya setelah pointer benar-benar keluar', () => {
        const { scope } = mount();

        scope.dragEnter();
        scope.dragEnter();
        scope.dragLeave();
        scope.dragLeave();

        expect(scope.dragging).toBe(false);
    });

    it('tidak pernah pada hitungan negatif setelah leave berlebihan', () => {
        const { scope } = mount();

        scope.dragLeave();
        scope.dragLeave();

        expect(scope.depth).toBe(0);
        expect(scope.dragging).toBe(false);
    });

    it('mematikan penanda diseret begitu berkas diterima', () => {
        const { scope } = mount();
        scope.dragEnter();

        scope.drop(dropEvent([file('katalog.xlsx')]));

        expect(scope.dragging).toBe(false);
        expect(scope.depth).toBe(0);
    });
});

describe('importDropzone: format ukuran berkas', () => {
    it('menampilkan ukuran dalam satuan yang mudah dibaca', () => {
        const { scope } = mount();

        expect(scope.formatBytes(0)).toBe('0 B');
        expect(scope.formatBytes(512)).toBe('512 B');
        expect(scope.formatBytes(2048)).toBe('2,0 KB');
        expect(scope.formatBytes(5 * 1024 * 1024)).toBe('5,0 MB');
    });
});