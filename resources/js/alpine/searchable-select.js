import Alpine from 'alpinejs';

/**
 * Combobox pencarian di atas sebuah <select> native.
 *
 * Element `<select>` tetap menjadi sumber kebenaran: nilainya yang disubmit
 * dengan `name`, `required` dan `@selected`/`old()` dari blade tetap berfungsi,
 * dan skema form tidak berubah. Komponen ini hanya menimpa tampilannya dengan
 * tombol + panel, dan menyinkronkan nilai lewat event `change` yang dibubble
 * -- sehingga `x-model` pada select (mis. tiap baris grid) ikut ter-update.
 *
 * Pilihan dibaca dari `<option>`/`<optgroup>` yang sudah dirender server, jadi
 * komponen ini bekerja apa adanya di dalam `<template x-for>` tanpa perlu
 * registrasi ulang: tiap baris punya scope-nya sendiri.
 *
 * Panel-nya dipindahkan ke `<body>` selama terbuka (lihat `portal()`) lalu
 * diposisikan dengan `position: fixed` dari `getBoundingClientRect()` tombol.
 * Karena tidak lagi hidup di dalam subtree elemen, panel tidak bisa terpotong
 * oleh `overflow` ancestor dan tidak ikut bergeser saat halaman di-scroll.
 */

/** Batas tinggi panel: cukup untuk daftar yang panjang tanpa menutupi layar. */

const PANEL_MAX_HEIGHT = 320;

/** Batas bawah supaya daftar tetap punya isi saat ruangnya mepet. */
const PANEL_MIN_HEIGHT = 120;

/**
 * Ruang di bawah yang dianggap "cukup". Melewati nilai ini, dan ruang di atas
 * lebih lega, panel membalik ke atas -- perilaku yang sama dengan select native.
 */
const FLIP_SPACE_THRESHOLD = 200;

const PANEL_GAP = 4;
const VIEWPORT_PADDING = 8;

export function searchableSelect({ placeholder = 'Pilih…', invalid = false } = {}) {
    return {
        placeholder,
        invalid,
        select: null,
        selectedValue: '',
        onSelectionChange: null,
        open: false,
        query: '',
        activeIndex: -1,
        listening: false,
        release: null,
        detachScope: null,

        init() {
            // <select> native datang dari slot pemanggil, jadi tidak bisa diberi
            // `x-ref` dari dalam komponen ini. Komponen yang mencarinya sendiri:
            // berharap setiap pemanggil ingat menambah atribut adalah cara yang
            // paling mudah terlupa, dan ketika terlupa `$refs.select` undefined
            // tanpa error yang terlihat sampai panel tidak menampilkan apa pun.
            this.syncSelection();
            this.onSelectionChange = () => this.syncSelection();
            this.select.addEventListener('change', this.onSelectionChange);
        },

        /**
         * Menyalin nilai `<select>` ke state reaktif.
         *
         * Label di tombol harus ikut berubah setelah memilih, tapi `selectedIndex`
         * milik DOM native bukan state reaktif -- Alpine tidak punya alasan untuk
         * mengevaluasi ulang efek yang membacanya. Tanpa salinan ini labelnya
         * membeku di placeholder.
         *
         * Kontraknya sama seperti elemen form biasa: nilai `<select>` yang diubah
         * dari luar harus menyalakan `change`. Itu yang dilakukan `choose()` di
         * bawah, dan `x-model` di pemanggil menulis lewat jalur yang sama.
         */
        syncSelection() {
            const select = this.$el.querySelector('select');

            // Select bisa saja diganti pemanggil (mis. baris `x-for` di-render
            // ulang), jadi jangan menggantungkan referensi lama.
            if (select !== this.select) {
                this.select = select;
            }

            this.selectedValue = select?.value ?? '';
        },

        /** Opsi yang terlihat: yang punya nilai, tidak disabled, dan cocok query. */
        get options() {
            const haystack = this.query.trim().toLowerCase();

            return Array.from(this.select.options)
                .filter((option) => option.value !== '' && !option.disabled)
                .filter((option) => haystack === '' || option.textContent.trim().toLowerCase().includes(haystack))
                .map((option) => ({ value: option.value, label: option.textContent.trim() }));
        },

        get displayLabel() {
            if (this.selectedValue === '' || this.select === null) {
                return this.placeholder;
            }

            const selected = Array.from(this.select.options)
                .find((option) => option.value === this.selectedValue);

            return selected ? selected.textContent.trim() : this.placeholder;
        },

        /**
         * Kelas tombol pemicu. Semuanya di sini, bukan di template, supaya
         * `invalid` (yang dibawa sebagai prop Blade) punya satu tempat yang
         * jelas untuk ikut sebagai bagian dari scope Alpine.
         */
        get triggerClass() {
            if (this.open) {
                return 'border-primary ring-2 ring-primary/30';
            }

            if (this.invalid) {
                return 'border-error-border hover:border-error-border';
            }

            return 'hover:border-border-strong';
        },

        toggle() {
            if (this.open) {
                this.closePanel();
                return;
            }

            this.openPanel();
        },

        openPanel() {
            // Pemicu yang tidak terlihat tidak akan pernah bisa punya panel yang
            // berguna. Menolaknya di sini, bukan membiarkan `reposition()`
            // menutupnya lagi di frame yang sama -- kalau tidak, panel terlihat
            // "terbuka sebentar lalu hilang" tepat seperti gejala harus scroll
            // untuk melihatnya.
            if (!this.triggerOnScreen()) {
                return;
            }

            this.open = true;
            this.query = '';
            this.activeIndex = this.options.length === 1 ? 0 : -1;
            this.portal();
            this.listen();

            this.reposition();
            this.$nextTick(() => {
                // Pass kedua: `x-show` baru selesai di-flush pada frame ini,
                // jadi baru di sini tinggi panel terukur dan posisinya final.
                this.reposition();

                // Komponen bisa sudah dibuang (baris `x-for` dihapus, atau test
                // yang memanggil `destroyTree`) sebelum frame ini datang, dan
                // `x-ref` ikut terhapus bersama elemennya.
                this.$refs.search?.focus();
            });
        },

        /** Pemicu dianggap terlihat kalau ada bagiannya yang masih di dalam viewport. */
        triggerOnScreen() {
            const trigger = this.$refs.button;

            if (trigger === undefined) {
                // Belum siap; biarkan `reposition()` yang memutuskan.
                return true;
            }

            const rect = trigger.getBoundingClientRect();

            return rect.bottom > 0 && rect.top < window.innerHeight;
        },

        closePanel() {
            if (!this.open) {
                return;
            }

            this.open = false;
            this.query = '';
            this.activeIndex = -1;
            this.unlisten();
            this.unportal();

            // `preventScroll` supaya menutup panel yang pemicunya sudah keluar
            // layar tidak ikut menggulir halaman untuk mengejarnya.
            this.$nextTick(() => this.$refs.button?.focus({ preventScroll: true }));
        },

        /**
         * Memindahkan panel ke <body> selagi terbuka.
         *
         * Dua hal yang `x-teleport` Alpine lakukan dan wajib ditiru persis:
         *
         * 1. Scope diambil dari posisi panel yang MASIH di dalam komponen
         *    (`addScopeToNode`). Alpine mencari scope lewat `closestDataStack`,
         *    yang menelusuri ancestry DOM; begitu panel hidup di `<body>`,
         *    penelusuran itu berakhir di `[]`. Akibatnya `x-for` di dalam panel
         *    meng-clone `<li>` yang dievaluasi tanpa `activeIndex`/`options`,
         *    dan konsol penuh "Alpine Expression Error: activeIndex is not
         *    defined" setiap kali daftar difilter atau keyword dihapus.
         *
         * 2. Pemindahan dibungkus `mutateDom`, supaya MutationObserver Alpine
         *    tidak meng-init ulang subtree yang baru dipindah -- yang akan
         *    memasang listener kedua pada tombol dan membuat satu klik membuka
         *    lalu langsung menutup panel.
         *
         * Dipakai alih-alih `x-teleport` karena `x-teleport` memindahkan isi
         * template ke `<body>` lebih dulu, sebelum Alpine mengumpulkan `x-ref` --
         * jadi `$refs.panel` dan `$refs.search` berakhir `undefined` dan panel
         * tidak pernah bisa diposisikan. Memindahkan node-nya sendiri
         * membiarkan referensinya tetap hidup, karena yang dipindah adalah
         * objek yang sama, bukan salinannya.
         */
        portal() {
            const panel = this.$refs.panel;

            if (!panel || panel.parentNode === document.body) {
                return;
            }

            this.detachScope = Alpine.addScopeToNode(panel, {}, this.$el);
            Alpine.mutateDom(() => document.body.appendChild(panel));
        },

        /** Panel kembali ke tempat semula, ikut hilang bersama baris/componennya. */
        unportal() {
            const panel = this.$refs.panel;

            if (!panel || panel.parentNode === this.$el) {
                return;
            }

            Alpine.mutateDom(() => this.$el.appendChild(panel));
            this.detachScope?.();
            this.detachScope = null;
        },

        /**
         * Menempelkan panel ke tombol pemicu: sejajar kiri dan selebar tombol,
         * terbuka ke bawah, atau ke atas bila ruang di bawah tidak cukup.
         */
        reposition() {
            const panel = this.$refs.panel;
            const trigger = this.$refs.button;

            if (panel === undefined || trigger === undefined) {
                return;
            }

            const rect = trigger.getBoundingClientRect();

            if (rect.bottom <= 0 || rect.top >= window.innerHeight) {
                // Pemicunya sudah keluar layar; lebih baik tutup daripada
                // membiarkan panel menggantung di tempat yang tidak berguna.
                this.closePanel();

                return;
            }

            const spaceBelow = window.innerHeight - rect.bottom - PANEL_GAP - VIEWPORT_PADDING;
            const spaceAbove = rect.top - PANEL_GAP - VIEWPORT_PADDING;
            const flipped = spaceBelow < FLIP_SPACE_THRESHOLD && spaceAbove > spaceBelow;
            const space = flipped ? spaceAbove : spaceBelow;
            const maxHeight = Math.min(Math.max(space, PANEL_MIN_HEIGHT), PANEL_MAX_HEIGHT);
            const width = rect.width;
            const left = Math.max(
                VIEWPORT_PADDING,
                Math.min(rect.left, window.innerWidth - width - VIEWPORT_PADDING)
            );

            panel.style.maxHeight = `${maxHeight}px`;
            panel.style.width = `${width}px`;
            panel.style.left = `${left}px`;

            // Saat terbalik, tinggi yang dipakai adalah tinggi panel yang
            // terukur; `maxHeight` hanya perkiraan untuk frame sebelum terukur.
            const height = panel.offsetHeight || maxHeight;
            const top = flipped ? rect.top - PANEL_GAP - height : rect.bottom + PANEL_GAP;

            panel.style.top = `${Math.max(VIEWPORT_PADDING, top)}px`;
        },

        /**
         * Mendengarkan perubahan viewport dan klik di luar.
         *
         * `@click.outside` tidak bisa dipakai di sini: panel berada di luar
         * subtree komponen setelah di-teleport, jadi klik di kotak pencarian
         * akan terhitung "di luar" dan menutup panel yang sedang dipakai.
         * Karena itu closure dibuat di sini -- `this` tetap instance komponen
         * (proxy reaktif) saat dipanggil browser, dan satu `release` menutup
         * listener yang sama persis.
         */
        listen() {
            if (this.listening) {
                return;
            }

            const repositionWhileOpen = () => {
                if (this.open) {
                    this.reposition();
                }
            };

            const closeWhenOutside = (event) => {
                const target = event.target;

                if (this.$refs.button.contains(target) || this.$refs.panel?.contains(target)) {
                    return;
                }

                this.closePanel();
            };

            // `true` = fase capture, supaya scroll di dalam `.table-scroll`
            // ikut tertangkap, bukan hanya scroll dokumen.
            window.addEventListener('scroll', repositionWhileOpen, true);
            window.addEventListener('resize', repositionWhileOpen);
            document.addEventListener('mousedown', closeWhenOutside, true);

            this.listening = true;
            this.release = () => {
                window.removeEventListener('scroll', repositionWhileOpen, true);
                window.removeEventListener('resize', repositionWhileOpen);
                document.removeEventListener('mousedown', closeWhenOutside, true);
                this.listening = false;
                this.release = null;
            };
        },

        unlisten() {
            this.release?.();
        },

        /** Baris `x-for` yang dihapus saat panelnya terbuka tidak boleh meninggalkan listener. */
        destroy() {
            this.unlisten();
            this.unportal();
            this.detachScope?.();
            this.select?.removeEventListener('change', this.onSelectionChange);
        },

        choose(index) {
            const option = this.options[index];

            if (!option) {
                return;
            }

            this.select.value = option.value;
            this.select.dispatchEvent(new Event('change', { bubbles: true }));
            this.closePanel();
        },

        /**
         * Keyboard pada tombol pemicu saat panel tertutup: ketikan langsung
         * membuka panel dan mengisi pencarian (mis. barcode = ketik cepat).
         */
        onTriggerKeydown(event) {
            if (this.open) {
                return;
            }

            const printable = event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey;
            const opens = printable || ['ArrowDown', 'Enter', ' '].includes(event.key);

            if (!opens) {
                return;
            }

            event.preventDefault();
            this.openPanel();

            if (printable) {
                this.query = event.key;
            }
        },

        /** Keyboard di dalam kotak pencarian panel. Huruf tidak dicegat di sini. */
        onSearchKeydown(event) {
            const { key } = event;

            if (key === 'Escape') {
                this.closePanel();
                return;
            }

            if (key === 'ArrowDown') {
                event.preventDefault();
                this.activeIndex = Math.min(this.activeIndex + 1, this.options.length - 1);
                return;
            }

            if (key === 'ArrowUp') {
                event.preventDefault();
                this.activeIndex = Math.max(this.activeIndex - 1, 0);
                return;
            }

            if (key === 'Enter') {
                event.preventDefault();
                const index = this.activeIndex >= 0 ? this.activeIndex : (this.options.length === 1 ? 0 : -1);

                if (index >= 0) {
                    this.choose(index);
                }

                return;
            }

            const printable = key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey;
            if (printable && this.options.length > 1) {
                this.activeIndex = -1;
            }
        },

        /** Ketikan terakhir menyisakan satu opsi -> sorot, siap dipilih Enter. */
        settleActiveIndex() {
            if (this.options.length === 1) {
                this.activeIndex = 0;
            }
        },
    };
}
