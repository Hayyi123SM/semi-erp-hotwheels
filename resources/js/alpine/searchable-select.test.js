import { describe, it, expect, vi, afterEach } from 'vitest';
import { searchableSelect } from './searchable-select';

/** Instance yang masih menempel listener window/document, dilepas setelah tiap test. */
const mounted = [];

/**
 * Scope dari sebuah combobox: select native tetap satunya sumber nilai.
 *
 * Panel ada di markup sebagai anak terakhir komponen, seperti di blade; yang
 * memindahkannya ke `<body>` adalah `portal()` waktu dibuka. happy-dom tidak
 * punya layout engine, jadi `getBoundingClientRect()` dan tinggi panel di-stub.
 */
function mount({
    placeholder = 'Pilih…',
    invalid = false,
    trigger,
    panelHeight = 0,
    viewportHeight = 800,
    viewportWidth = 1024,
} = {}) {
    const root = document.createElement('div');

    // Meniru blade: select dari slot, lalu tombol, lalu panel sebagai anak
    // terakhir. Panel dipindahkan ke body oleh `portal()` saat dibuka.
    root.innerHTML = `
        <select>
            <option value="">— pilih —</option>
            <option value="orange">Orange</option>
            <option value="purple">Purple</option>
            <option value="white" disabled>White (kosong)</option>
        </select>
        <button type="button"></button>
        <div class="panel"><input type="text"></div>
    `;
    document.body.appendChild(root);

    const panel = root.querySelector('.panel');
    const button = root.querySelector('button');
    const rect = { top: 300, bottom: 344, left: 40, right: 328, width: 288, height: 44, x: 40, y: 300 };

    button.getBoundingClientRect = () => ({ ...rect, ...trigger });
    Object.defineProperty(panel, 'offsetHeight', { value: panelHeight, configurable: true });
    Object.defineProperty(window, 'innerHeight', { value: viewportHeight, configurable: true, writable: true });
    Object.defineProperty(window, 'innerWidth', { value: viewportWidth, configurable: true, writable: true });

    const scope = searchableSelect({ placeholder, invalid });
    scope.$el = root;
    scope.$refs = {
        button,
        search: panel.querySelector('input'),
        panel,
    };
    scope.$nextTick = (callback) => callback();
    // Alpine memanggil init() sendiri; di sini juga dipanggil supaya `select`
    // benar-benar ditemukan dari DOM, bukan di-stub manual.
    scope.init();

    const changes = [];
    scope.select.addEventListener('change', (event) => {
        changes.push({ value: event.target.value, bubbles: event.bubbles });
    });

    mounted.push(scope);

    return { scope, root, button, panel, changes };
}

function key(scope, handler, key, options = {}) {
    const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...options });
    scope[handler](event);
    return event;
}

function mousedown(target) {
    target.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
}

afterEach(() => {
    mounted.splice(0).forEach((scope) => scope.unlisten());
    document.body.innerHTML = '';
});

describe('searchable-select: opsi yang terlihat', () => {
    it('membuang opsi placeholder kosong dan disabled', () => {
        const { scope } = mount();

        expect(scope.options.map((option) => option.value)).toEqual(['orange', 'purple']);
    });

    it('menyaring sesuai ketikan, tidak peka kapital', () => {
        const { scope } = mount();

        scope.query = 'OR';
        expect(scope.options.map((option) => option.value)).toEqual(['orange']);
        scope.query = 'e';
        expect(scope.options.map((option) => option.label)).toEqual(['Orange', 'Purple']);
        scope.query = 'zzz';
        expect(scope.options).toEqual([]);
    });
});

describe('searchable-select: tampilan nilai terpilih', () => {
    it('menampilkan placeholder saat belum ada pilihan', () => {
        const { scope } = mount({ placeholder: '— pilih produk —' });

        expect(scope.displayLabel).toBe('— pilih produk —');
    });

    it('menampilkan label opsi yang terpilih setelah select menyalakan change', () => {
        const { scope } = mount();

        // Menulis `.value` tanpa menyalakan `change` tidak cukup: `selectedIndex`
        // milik DOM native bukan state reaktif, jadi labelnya tidak punya alasan
        // untuk dievaluasi ulang. Inilah alasan komponen menyimpan salinannya
        // sendiri -- dan kontrak yang dipegang pemanggil: mengubah nilai select
        // dari luar berarti menyalakan `change`, seperti elemen form biasa.
        scope.select.value = 'orange';
        expect(scope.displayLabel).toBe('Pilih…');

        scope.select.dispatchEvent(new Event('change', { bubbles: true }));
        expect(scope.displayLabel).toBe('Orange');
    });

    it('kembali ke placeholder saat select dikosongkan dari luar', () => {
        const { scope } = mount();

        scope.select.value = 'purple';
        scope.select.dispatchEvent(new Event('change', { bubbles: true }));
        expect(scope.displayLabel).toBe('Purple');

        scope.select.value = '';
        scope.select.dispatchEvent(new Event('change', { bubbles: true }));
        expect(scope.displayLabel).toBe('Pilih…');
    });

    it('mempertahankan label awal dari nilai yang sudah terisi blade', () => {
        const root = document.createElement('div');
        root.innerHTML = `
            <select>
                <option value="">— pilih —</option>
                <option value="orange" selected>Orange</option>
            </select>
            <button type="button"></button>
            <div class="panel"><input type="text"></div>
        `;
        document.body.appendChild(root);

        const scope = searchableSelect({ placeholder: 'Pilih…' });
        scope.$el = root;
        scope.$refs = { button: root.querySelector('button'), search: root.querySelector('input'), panel: root.querySelector('.panel') };
        scope.$nextTick = (callback) => callback();
        scope.init();
        mounted.push(scope);

        expect(scope.displayLabel).toBe('Orange');
    });
});

describe('searchable-select: pemicu yang tidak terlihat', () => {
    it('tidak membuka panel kalau pemicunya sudah keluar layar', () => {
        const { scope, root, panel } = mount({ trigger: { top: -200, bottom: -156 } });

        scope.openPanel();

        // Panel yang terbuka lalu langsung tertutup di frame yang sama terlihat
        // seperti "harus scroll untuk melihatnya", jadi lebih baik tidak dibuka.
        expect(scope.open).toBe(false);
        expect(panel.parentNode).toBe(root);
        expect(panel.style.top).toBe('');
    });

    it('menutup panel yang terbuka kalau pemicunya tergulung keluar layar', () => {
        const { scope, button } = mount();

        scope.openPanel();
        expect(scope.open).toBe(true);

        button.getBoundingClientRect = () => ({ top: -200, bottom: -156, left: 40, right: 328, width: 288, height: 44, x: 40, y: -200 });
        scope.reposition();

        expect(scope.open).toBe(false);
    });
});

describe('searchable-select: komponen yang dibuang', () => {
    it('tidak melempar error saat ref sudah hilang', () => {
        const { scope } = mount();

        scope.openPanel();
        expect(() => scope.closePanel()).not.toThrow();
    });
});

describe('searchable-select: memilih opsi', () => {
    it('memilih sesuai index dan melebarkan event change', () => {
        const { scope, changes } = mount();

        scope.query = '';
        scope.choose(1);

        expect(scope.select.value).toBe('purple');
        expect(changes).toEqual([{ value: 'purple', bubbles: true }]);
    });

    it('menutup panel setelah memilih', () => {
        const { scope } = mount();

        scope.openPanel();
        scope.choose(0);
        expect(scope.open).toBe(false);
    });

    it('tidak melakukan apa-apa pada index yang tidak ada', () => {
        const { scope, changes } = mount();

        scope.choose(5);
        expect(changes).toEqual([]);
    });
});

describe('searchable-select: buka dan tutup panel', () => {
    it('openPanel memfokus kotak pencarian', () => {
        const { scope } = mount();
        const focus = { called: false };
        Object.defineProperty(scope.$refs.search, 'focus', { value: vi.fn(() => { focus.called = true; }) });

        scope.openPanel();

        expect(scope.open).toBe(true);
        expect(focus.called).toBe(true);
    });

    it('closePanel mereset pencarian dan memfokus tombol', () => {
        const { scope } = mount();
        const focus = { called: false };
        Object.defineProperty(scope.$refs.button, 'focus', { value: vi.fn(() => { focus.called = true; }) });

        scope.openPanel();
        scope.query = 'or';
        scope.closePanel();

        expect(scope.open).toBe(false);
        expect(scope.query).toBe('');
        expect(scope.activeIndex).toBe(-1);
        expect(focus.called).toBe(true);
    });

    it('toggle membuka yang tertutup dan menutup yang terbuka', () => {
        const { scope } = mount();

        scope.toggle();
        expect(scope.open).toBe(true);
        scope.toggle();
        expect(scope.open).toBe(false);
    });
});

describe('searchable-select: keyboard', () => {
    it('ketikan pada tombol membuka panel dan mengisi query', () => {
        const { scope } = mount();

        const event = key(scope, 'onTriggerKeydown', 'o');

        expect(event.defaultPrevented).toBe(true);
        expect(scope.open).toBe(true);
        expect(scope.query).toBe('o');
    });

    it('ArrowDown pada tombol membuka panel tanpa mengisi query', () => {
        const { scope } = mount();

        key(scope, 'onTriggerKeydown', 'ArrowDown');

        expect(scope.open).toBe(true);
        expect(scope.query).toBe('');
    });

    it('huruf lain pada tombol (mis. Escape) tidak membuka panel', () => {
        const { scope } = mount();

        key(scope, 'onTriggerKeydown', 'Escape');
        expect(scope.open).toBe(false);
    });

    it('ArrowDown/ArrowUp menggerakkan sorotan dalam batas daftar', () => {
        const { scope } = mount();
        scope.openPanel();

        key(scope, 'onSearchKeydown', 'ArrowUp');
        expect(scope.activeIndex).toBe(0);

        key(scope, 'onSearchKeydown', 'ArrowUp');
        expect(scope.activeIndex).toBe(0);

        key(scope, 'onSearchKeydown', 'ArrowDown');
        expect(scope.activeIndex).toBe(1);
    });

    it('Enter memilih opsi yang tersorot', () => {
        const { scope, changes } = mount();
        scope.openPanel();
        scope.activeIndex = 0;

        key(scope, 'onSearchKeydown', 'Enter');

        expect(scope.select.value).toBe('orange');
        expect(changes).toHaveLength(1);
        expect(scope.open).toBe(false);
    });

    it('Enter dengan satu hasil menyisakan langsung memilihnya (scan barcode)', () => {
        const { scope, changes } = mount();
        scope.openPanel();
        scope.query = 'orange';

        key(scope, 'onSearchKeydown', 'Enter');

        expect(scope.select.value).toBe('orange');
        expect(changes).toHaveLength(1);
    });

    it('Enter tanpa hasil tidak memilih apa-apa', () => {
        const { scope, changes } = mount();
        scope.openPanel();
        scope.query = 'zzz';

        key(scope, 'onSearchKeydown', 'Enter');

        expect(changes).toEqual([]);
    });

    it('Escape menutup panel', () => {
        const { scope } = mount();
        scope.openPanel();

        key(scope, 'onSearchKeydown', 'Escape');

        expect(scope.open).toBe(false);
    });

    it('ketikan yang menyisakan satu opsi mengunci sorotan ke sana', () => {
        const { scope } = mount();
        scope.openPanel();

        scope.query = 'purple';
        scope.settleActiveIndex();

        expect(scope.activeIndex).toBe(0);
    });
});

describe('searchable-select: label dan kelas tombol', () => {
    it('menemukan select native dari slot pemanggil, bukan dari x-ref', () => {
        const { scope, root } = mount();

        // Select datang dari slot, jadi tidak punya x-ref. Kalau komponen ini
        // bergantung pada `$refs.select`, semua getter di bawah ini akan
        // gagal diam-diam di browser: `invalid is not defined` dan
        // `Cannot read properties of undefined (reading 'options')`.
        expect(scope.select).toBe(root.querySelector('select'));
        expect(scope.$refs.select).toBeUndefined();
        expect(scope.displayLabel).toBe('Pilih…');
        expect(scope.options).toHaveLength(2);
    });

    it('kelas tombol mengikuti state invalid dari prop blade', () => {
        const { scope } = mount({ invalid: true });

        expect(scope.triggerClass).toBe('border-error-border hover:border-error-border');

        scope.open = true;
        expect(scope.triggerClass).toBe('border-primary ring-2 ring-primary/30');
    });

    it('kelas tombol default saat tidak invalid dan panel tertutup', () => {
        const { scope } = mount();

        expect(scope.triggerClass).toBe('hover:border-border-strong');
    });
});

describe('searchable-select: penempatan panel', () => {
    it('memindahkan panel ke body saat terbuka agar tidak bisa ter-clip ancestor', () => {
        const { scope, root, panel } = mount();

        expect(panel.parentNode).toBe(root);

        scope.openPanel();

        expect(panel.parentNode).toBe(document.body);
        expect(root.contains(panel)).toBe(false);
    });

    it('mengembalikan panel ke komponennya saat ditutup', () => {
        const { scope, root, panel } = mount();

        scope.openPanel();
        scope.closePanel();

        expect(panel.parentNode).toBe(root);
    });

    it('destroy mengembalikan panel supaya tidak nyangkut di body', () => {
        const { scope, root, panel } = mount();

        scope.openPanel();
        scope.destroy();

        expect(panel.parentNode).toBe(root);
    });

    it('sejajar dengan tombol dan membuka ke bawah saat ruang cukup', () => {
        const { scope, panel } = mount({ trigger: { top: 300, bottom: 344 } });

        scope.openPanel();

        expect(panel.style.position).toBe('');
        expect(panel.style.left).toBe('40px');
        expect(panel.style.width).toBe('288px');
        expect(panel.style.top).toBe('348px');
        expect(panel.style.maxHeight).toBe('320px');
    });

    it('membalik ke atas dan menjepit tinggi saat ruang di bawah tidak cukup', () => {
        const { scope, panel } = mount({
            trigger: { top: 700, bottom: 744 },
            panelHeight: 120,
            viewportHeight: 800,
        });

        scope.openPanel();

        // 700 - 4 (jarak) - 120 (tinggi terukur) = 576
        expect(panel.style.top).toBe('576px');
        expect(panel.style.maxHeight).toBe('320px');
    });

    it('menjepit tinggi panel ke ruang yang tersedia di viewport pendek', () => {
        const { scope, panel } = mount({
            trigger: { top: 95, bottom: 139 },
            viewportHeight: 200,
        });

        scope.openPanel();

        // Ruang di atas 83 dan di bawah 49: dijepit ke minimum 120.
        expect(panel.style.maxHeight).toBe('120px');
    });

    it('tidak keluar tepi kanan saat tombol berada dekat ujung viewport', () => {
        const { scope, panel } = mount({
            trigger: { left: 700, right: 988 },
            viewportWidth: 900,
        });

        scope.openPanel();

        // 900 - 288 - 8 = 604
        expect(panel.style.left).toBe('604px');
    });

    it('menempel mengikuti tombol saat jendela digulir', () => {
        const { scope, panel, root } = mount({ trigger: { top: 300, bottom: 344 } });

        scope.openPanel();
        expect(panel.style.top).toBe('348px');

        root.querySelector('button').getBoundingClientRect = () => ({
            top: 120, bottom: 164, left: 40, right: 328, width: 288, height: 44, x: 40, y: 120,
        });
        window.dispatchEvent(new Event('scroll'));

        expect(panel.style.top).toBe('168px');
    });

    it('menutup panel saat pemicunya sudah keluar layar', () => {
        const { scope } = mount({ trigger: { top: -400, bottom: -356 } });

        scope.openPanel();

        expect(scope.open).toBe(false);
    });

    it('membuka ulang listener setiap kali panel dibuka, dan melepaskannya saat ditutup', () => {
        const { scope } = mount();

        scope.openPanel();
        scope.openPanel();
        expect(scope.listening).toBe(true);

        scope.closePanel();
        expect(scope.listening).toBe(false);
        expect(scope.release).toBe(null);
    });

    it('destroy melepas listener supaya baris x-for yang dihapus tidak bocor', () => {
        const { scope } = mount();

        scope.openPanel();
        scope.destroy();

        expect(scope.listening).toBe(false);
    });
});

describe('searchable-select: klik di luar', () => {
    it('tidak menutup saat mousedown di dalam panel (regresi setelah teleport)', () => {
        const { scope, panel } = mount();

        scope.openPanel();
        mousedown(panel.querySelector('input'));

        expect(scope.open).toBe(true);
    });

    it('tidak menutup saat mousedown di tombol pemicu', () => {
        const { scope, root } = mount();

        scope.openPanel();
        mousedown(root.querySelector('button'));

        expect(scope.open).toBe(true);
    });

    it('menutup saat mousedown di luar trigger maupun panel', () => {
        const { scope, root } = mount();

        scope.openPanel();
        mousedown(root);

        expect(scope.open).toBe(false);
    });

    it('listener dilepas saat ditutup, sehingga klik berikutnya tidak bereaksi', () => {
        const { scope, root } = mount();

        scope.openPanel();
        scope.closePanel();
        mousedown(root);

        expect(scope.open).toBe(false);
        expect(scope.listening).toBe(false);
    });
});
