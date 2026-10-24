/**
 * Form Stock In Pribadi dengan popup pencarian produk.
 *
 * Mengelola daftar item terpilih (cart-like), membuka picker produk,
 * menangani duplikasi (ignore + highlight), serta scan barcode.
 *
 * Picker memakai pencarian level produk (endpoint `inbound.produk.cari`),
 * bukan level lot seperti picker kasir: form ini diisi sebelum barang
 * diterima, jadi produk yang dicari belum tentu punya satu lot pun, dan
 * `products` tidak punya kolom `sku` -- `casting_code` yang dipakai sebagai
 * identitas tampilan.
 */

import { rupiah } from "../format";

/**
 * Jeda sebelum daftar benar-benar dikirim ke server, dalam milidetik.
 *
 * Mengetik qty beberapa baris berturut-turut tidak perlu menulis satu
 * permintaan per ketukan; yang penting keadaan terakhir, dan itulah yang
 * dikirim setelah ketukan terakhir.
 */
const AUTOSAVE_DELAY_MS = 400;

/**
 * Palet badge seri, dipilih deterministik dari nama serinya.
 *
 * Warna yang sama untuk seri yang sama di setiap baris, perangkat, dan
 * sesi -- fungsi hash murni, tanpa keadaan yang disimpan di mana pun. Hash
 * sederhana (polynomial rolling) cukup: yang dibutuhkan hanya satu indeks
 * stabil dari nama ke palet, bukan kriptografi.
 *
 * Kelas tertulis lengkap sebagai string literal supaya pemindai Tailwind
 * menemukannya di berkas sumber; palet ini memakai warna pastel latar
 * terang-teks-gelap, seperti badge kondisi yang sudah ada di halaman.
 */
const SERIES_BADGE_CLASSES = [
    "bg-sky-100 text-sky-800",
    "bg-violet-100 text-violet-800",
    "bg-emerald-100 text-emerald-800",
    "bg-amber-100 text-amber-800",
    "bg-rose-100 text-rose-800",
    "bg-indigo-100 text-indigo-800",
    "bg-teal-100 text-teal-800",
    "bg-orange-100 text-orange-800",
];

/**
 * Kelas warna untuk satu nama seri, atau string kosong bila namanya kosong.
 *
 * @param {string} name
 * @returns {string}
 */
function seriesBadgeClass(name) {
    const series = String(name ?? "");

    if (series === "") {
        return "";
    }

    let hash = 0;

    for (let i = 0; i < series.length; i++) {
        hash = (hash * 31 + series.charCodeAt(i)) | 0;
    }

    return SERIES_BADGE_CLASSES[
        Math.abs(hash) % SERIES_BADGE_CLASSES.length
    ];
}

export function stockInPribadiForm(options = {}) {
    const lookupUrl = options.lookupUrl ?? "";
    const racks = Array.isArray(options.racks) ? options.racks : [];
    const cardConditions = options.cardConditions ?? {};
    const blisterConditions = options.blisterConditions ?? {};
    const initialItems = Array.isArray(options.initialItems)
        ? options.initialItems
        : [];
    // False kalau server baru saja mengembalikan form karena validasi gagal.
    // `old()` di situ sudah berisi apa yang harus benar, dan draft di server
    // bisa saja lebih lama -- menimpanya berarti membatalkan perbaikan Owner.
    const restorable = options.restorable !== false;

    return {
        items: [],
        pickerOpen: false,
        scanCode: "",
        scanError: "",
        lookupUrl,
        racks,
        cardConditions,
        blisterConditions,
        initialItems,
        restorable,
        highlightedIndex: -1,
        highlightTimeout: null,

        // --- Draft ---
        submitting: false,
        draftExists: false,
        saveTimer: null,
        // Isi draft terakhir yang dikonfirmasi server, sebagai JSON. Dipakai
        // supaya memulihkan draft tidak langsung menulisnya kembali, dan supaya
        // mengosongkan daftar yang memang tidak punya draft tidak membuang-buang
        // satu permintaan DELETE.
        lastSaved: null,
        // Menunggu pemulihan draft selesai sebelum menulis: menulis lebih dulu
        // bisa menimpa draft di server dengan daftar yang masih kosong.
        draftPromise: Promise.resolve(),

        /**
         * Endpoint draft, dibaca dari `data-*` seperti `lookupUrl`.
         *
         * File ini tidak bisa melihat route, jadi URL yang tertanam di markup
         * adalah satu-satunya sumber kebenarannya -- dan test bisa memberikannya
         * tanpa memalsukan lokasi. String kosong berarti "halaman ini tidak
         * memakai draft", sehingga tidak satu pun permintaan dikirim.
         */
        get draftLoadUrl() {
            return this.$root?.dataset?.draftLoad ?? "";
        },

        get draftSaveUrl() {
            return this.$root?.dataset?.draftSave ?? "";
        },

        get draftCancelUrl() {
            return this.$root?.dataset?.draftCancel ?? "";
        },

        async init() {
            this.items = initialItems.map((item) =>
                this.hydrateDraftItem(item),
            );

            this.scanCode = "";
            this.scanError = "";
            this.highlightedIndex = -1;

            this.$nextTick(() => {
                this.$refs?.scanInput?.focus?.();
            });

            this.draftPromise = this.restorable
                ? this.loadDraft()
                : Promise.resolve();

            await this.draftPromise;
        },

        /**
         * Satu baris tersimpan menjadi satu baris daftar.
         *
         * Dipakai untuk `old()` dan untuk draft, supaya keduanya melewati
         * jalur penyebaran yang sama -- kalau berbeda, daftar hasil pemulihan
         * bisa berbeda bentuk dengan daftar yang baru saja dikirim.
         *
         * @param {object} item
         * @returns {object}
         */
        hydrateDraftItem(item) {
            return {
                id: item.id ?? this.generateId(),
                product_id: item.product_id,
                casting_code: item.casting_code ?? "",
                name: item.name ?? "",
                series_code: item.series_code ?? "",
                series_name: item.series_name ?? "",
                color: item.color ?? "",
                year: item.year ?? "",
                qty: item.qty ?? 1,
                cost_price: item.cost_price ?? "",
                card_condition: item.card_condition ?? "MINT",
                blister_condition: item.blister_condition ?? "CLEAR",
                rack_id: item.rack_id ?? "",
            };
        },

        /**
         * Daftar draft dari server, kalau ada.
         *
         * Dipanggil sekali saat komponen hidup, dan isinya langsung dipasang --
         * tidak ditawarkan lagi lewat dialog. Pemulihan diam-diam seperti ini
         * membuat muat ulang terasa tidak menghapus pekerjaan; Owner yang
         * memang ingin mulai dari kosong tinggal menghapus barisnya, dan daftar
         * yang kosong tidak akan ditawarkan lagi.
         */
        async loadDraft() {
            if (this.draftLoadUrl === "" || this.items.length > 0) {
                return;
            }

            try {
                const res = await fetch(this.draftLoadUrl, {
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN": this.csrf(),
                    },
                });

                const data = await res.json();

                if (
                    !data?.has_draft ||
                    !Array.isArray(data.draft?.items) ||
                    data.draft.items.length === 0
                ) {
                    return;
                }

                // Pengguna bisa saja sudah menambahkan produk selagi menunggu.
                // Yang di tangan lebih baru daripada yang di server.
                if (this.items.length > 0) {
                    return;
                }

                this.items = data.draft.items.map((item) =>
                    this.hydrateDraftItem(item),
                );
                this.draftExists = true;
                this.lastSaved = JSON.stringify(this.draftItems());
            } catch {
                // Draft adalah kenyamanan, bukan transaksi: jaringan putus atau
                // sesi lewat berarti "tidak ada draft", bukan halaman rusak.
            }
        },

        /**
         * Daftar dalam bentuk yang dikirim ke server.
         *
         * @returns {object[]}
         */
        draftItems() {
            return this.items.map((item) => ({
                id: item.id,
                product_id: item.product_id,
                casting_code: item.casting_code,
                name: item.name,
                series_code: item.series_code,
                series_name: item.series_name,
                color: item.color,
                year: item.year,
                qty: item.qty,
                cost_price: item.cost_price,
                card_condition: item.card_condition,
                blister_condition: item.blister_condition,
                rack_id: item.rack_id,
            }));
        },

        /**
         * Jadwalkan penyimpanan setelah Owner berhenti mengetik.
         *
         * Dipanggil dari `x-effect` pada kartu Daftar Produk, jadi tidak ada
         * satu pun field form yang perlu mengingat bahwa ia perlu disimpan.
         * Membaca `items` dari dalam effect itulah yang mendaftarkan
         * dependensinya -- tanpa itu effect tidak akan pernah berjalan lagi
         * setelah sekali di awal.
         */
        scheduleSave() {
            if (this.submitting) {
                return;
            }

            const payload = this.draftItems();
            const fingerprint = JSON.stringify(payload);

            if (fingerprint === this.lastSaved) {
                return;
            }

            // Daftar kosong yang tidak pernah tersimpan bukan draft yang perlu
            // dibuang: setiap halaman kosong akan mengirim DELETE tanpa guna.
            if (payload.length === 0 && !this.draftExists) {
                return;
            }

            if (this.saveTimer !== null) {
                clearTimeout(this.saveTimer);
            }

            this.saveTimer = setTimeout(
                () => this.flushSave(payload),
                AUTOSAVE_DELAY_MS,
            );
        },

        /**
         * Kirim daftar sekarang, atau buang draftnya bila daftarnya kosong.
         *
         * @param {object[] | null} payload sudah dihitung pemanggil, supaya
         *   penyimpanan yang dijadwalkan dan penyimpanan saat submit tidak
         *   menghitung dua kali.
         */
        async flushSave(payload = null) {
            if (this.saveTimer !== null) {
                clearTimeout(this.saveTimer);
                this.saveTimer = null;
            }

            await this.draftPromise;

            const body = payload ?? this.draftItems();
            const fingerprint = JSON.stringify(body);

            if (fingerprint === this.lastSaved) {
                return;
            }

            if (body.length === 0) {
                this.lastSaved = fingerprint;

                if (this.draftExists) {
                    await this.cancelDraft();
                    this.draftExists = false;
                }

                return;
            }

            if (this.draftSaveUrl === "") {
                return;
            }

            try {
                const res = await fetch(this.draftSaveUrl, {
                    method: "POST",
                    headers: {
                        Accept: "application/json",
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": this.csrf(),
                    },
                    body: JSON.stringify({ items: body }),
                });

                if (!res.ok) {
                    return;
                }

                // Ditandai hanya bila server benar-benar menerima: bila tidak,
                // perubahan berikutnya harus mencoba lagi, bukan menganggap
                // keadaan terakhir sudah tersimpan.
                this.lastSaved = fingerprint;
                this.draftExists = true;
            } catch {
                // Draft adalah kenyamanan, bukan transaksi. Kegagalan
                // menyimpannya tidak boleh menghalangi pengisian form, dan
                // perubahan berikutnya akan menulis keadaan yang lebih baru.
            }
        },

        /**
         * Buang draft daftar produk yang sedang berjalan.
         */
        async cancelDraft() {
            if (this.draftCancelUrl === "") {
                return;
            }

            try {
                await fetch(this.draftCancelUrl, {
                    method: "DELETE",
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN": this.csrf(),
                    },
                });
            } catch {
                // Pembersihan draft; gagal pun tidak menghentikan apa pun.
            }
        },

        /**
         * Kosongkan seluruh daftar produk setelah pengguna menyetujuinya.
         *
         * Daftar panjang -- terutama hasil impor -- hanya bisa diulang dengan
         * membuang semuanya, dan menghapusnya baris demi baris berarti satu
         * dialog per baris. Dialog di sini menyebut jumlah barang dan total
         * qty yang lenyap, sama seperti baris kasir: tanpa angka itu, alasan
         * menekan "Batal" tidak ada.
         *
         * Draftnya ikut dibuang lewat `flushSave()`, bukan `DELETE` langsung:
         * daftar kosong yang tersimpan memang berarti "tidak ada draft", dan
         * `flushSave` sudah memegang aturan itu -- termasuk tidak mengirim
         * permintaan apa pun kalau draftnya belum pernah ada.
         *
         * @returns {Promise<boolean>} true bila daftar benar-benar dikosongkan
         */
        async clearConfirmed() {
            if (this.items.length === 0) {
                return false;
            }

            const notify = window.notify;

            if (typeof notify?.dangerConfirm !== "function") {
                return false;
            }

            const count = this.items.length;
            const qty = this.totalQty();

            const confirmed = await notify.dangerConfirm({
                title: "Kosongkan daftar?",
                description: `${count} barang (total qty ${qty}) akan dihapus dari daftar.`,
                confirmText: "Kosongkan",
                cancelText: "Batal",
            });

            if (!confirmed) {
                return false;
            }

            this.items = [];
            this.highlightedIndex = -1;

            if (this.highlightTimeout) {
                clearTimeout(this.highlightTimeout);
                this.highlightTimeout = null;
            }

            await this.flushSave();

            return true;
        },

        /**
         * Kirim draft lebih dulu, baru kirim formnya.
         *
         * `HTMLFormElement.submit()` tidak memancarkan event `submit`, jadi
         * tidak akan terpicu dua kali, dan validasi browser sudah selesai
         * sebelum handler ini berjalan. Yang dikejar di sini adalah urutannya:
         * tanpa `await`, draft yang mengandung baris yang sama bisa mendarat di
         * server setelah commit -- dan commit membatalkan draft, sehingga
         * draft yang telat itu akan memunculkan kembali daftar yang sudah jadi
         * stok.
         *
         * @param {SubmitEvent} event
         */
        async onSubmit(event) {
            if (this.submitting) {
                return;
            }

            this.submitting = true;

            try {
                await this.flushSave();
            } finally {
                event.target.submit();
            }
        },

        csrf() {
            return (
                document.querySelector('meta[name="csrf-token"]')?.content ?? ""
            );
        },

        generateId() {
            if (
                typeof crypto !== "undefined" &&
                typeof crypto.randomUUID === "function"
            ) {
                return crypto.randomUUID();
            }

            return `sip-${Date.now().toString(36)}-${Math.random()
                .toString(36)
                .slice(2, 10)}`;
        },

        totalQty() {
            return this.items.reduce(
                (sum, row) => sum + (Number(row.qty) || 0),
                0,
            );
        },

        totalHpp() {
            return this.items.reduce(
                (sum, row) =>
                    sum +
                    (Number(row.qty) || 0) * (Number(row.cost_price) || 0),
                0,
            );
        },

        formatRupiah(value) {
            const num = Number(value) || 0;
            return rupiah(num);
        },

        /**
         * Kelas warna badge seri untuk satu baris (lihat `seriesBadgeClass`
         * modul). Dipanggil dari blade lewat `:class`, jadi warnanya ikut
         * terpasang tanpa menambah atribut `style` per baris.
         *
         * @param {string} name
         * @returns {string}
         */
        seriesBadgeClass(name) {
            return seriesBadgeClass(name);
        },

        openPicker() {
            this.pickerOpen = true;
            this.scanError = "";

            // Fokus lewat id, bukan `$refs`: input pencarian berada di dalam
            // `x-data="productPicker(...)"`, jadi ref-nya terdaftar pada root
            // komponen anak dan tidak terlihat dari scope induk ini.
            this.$nextTick(() => {
                document
                    .getElementById("stock-in-picker-search")
                    ?.focus?.();
            });
        },

        closePicker() {
            this.pickerOpen = false;
        },

        focusScan() {
            this.scanError = "";
            this.$nextTick(() => {
                this.$refs?.scanInput?.focus?.();
            });
        },

        clearScanError() {
            this.scanError = "";
        },

        onScanKeydown(event) {
            if (event.key === "Enter") {
                event.preventDefault();
                this.addByBarcode(this.scanCode.trim());
            }
        },

        async addByBarcode(code) {
            const barcode = String(code ?? "").trim();

            if (barcode === "") {
                return;
            }

            this.scanError = "";

            try {
                const response = await fetch(this.lookupUrl, {
                    method: "POST",
                    headers: {
                        Accept: "application/json",
                        "X-CSRF-TOKEN": this.csrf(),
                    },
                    body: new URLSearchParams({ barcode }),
                });

                if (!response.ok) {
                    throw new Error("Barcode tidak ditemukan");
                }

                const data = await response.json();
                const items = Array.isArray(data.items) ? data.items : [];

                if (items.length === 0) {
                    this.scanError = "Barcode tidak ditemukan";
                    return;
                }

                if (items.length > 1) {
                    // Lebih dari satu produk cocok: memilih sendiri diam-diam
                    // bisa menerima barang yang salah, dan baris yang salah di
                    // sini berarti HPP dan label yang salah pula.
                    this.scanError =
                        "Barcode cocok dengan beberapa produk. Cari lewat tombol Tambah Produk.";
                    return;
                }

                const product = items[0];

                if (!product || !product.product_id) {
                    this.scanError = "Barcode tidak ditemukan";
                    return;
                }

                this.addProduct(product);
                this.scanCode = "";
                this.scanError = "";
            } catch (error) {
                this.scanError = "Barcode tidak ditemukan";
            }
        },

        onProductPicked(event) {
            const detail = event.detail ?? {};
            const productData = detail.item ?? detail;

            if (!productData || !productData.product_id) {
                return;
            }

            const product = {
                product_id: productData.product_id,
                casting_code: productData.casting_code ?? "",
                name: productData.name ?? "",
                series_code: productData.series_code ?? "",
                // Picker mengirim `series` berisi nama; draft dan payload lama
                // memakai `series_name`. Keduanya diterima supaya baris hasil
                // pemulihan dan baris baru berbentuk sama.
                series_name: productData.series_name ?? productData.series ?? "",
                color: productData.color ?? "",
                year: productData.year ?? "",
            };

            this.addProduct(product);

            // Tutup popup setelah pilihan diproses -- termasuk saat duplikat,
            // supaya pengguna langsung melihat barisnya (atau highlight baris
            // yang sudah ada) di belakang drawer, bukan layar kosong.
            this.closePicker();
        },

        addProduct(product) {
            if (!product || !product.product_id) {
                return;
            }

            const existingIndex = this.items.findIndex(
                (item) => item.product_id === product.product_id,
            );

            if (existingIndex !== -1) {
                this.highlightExisting(existingIndex);

                if (window.notify && typeof window.notify.warning === "function") {
                    window.notify.warning(
                        "Produk ini sudah ada di daftar. Tidak menambahkan baris baru.",
                    );
                } else if (
                    window.notify &&
                    typeof window.notify.info === "function"
                ) {
                    window.notify.info(
                        "Produk ini sudah ada di daftar. Tidak menambahkan baris baru.",
                    );
                } else {
                    alert(
                        "Produk ini sudah ada di daftar. Tidak menambahkan baris baru.",
                    );
                }

                return;
            }

            this.items.push({
                id: this.generateId(),
                product_id: product.product_id,
                casting_code: product.casting_code ?? "",
                name: product.name ?? "",
                series_code: product.series_code ?? "",
                series_name: product.series_name ?? product.series ?? "",
                color: product.color ?? "",
                year: product.year ?? "",
                qty: 1,
                cost_price: "",
                card_condition: "MINT",
                blister_condition: "CLEAR",
                rack_id: "",
            });
        },

        highlightExisting(index) {
            if (index < 0 || index >= this.items.length) {
                return;
            }

            this.highlightedIndex = index;

            // `data-row-idx`, bukan `x-ref` dinamis: Alpine membaca `x-ref`
            // sebagai string literal sehingga `:x-ref="'row-' + idx"` tidak
            // pernah mendaftarkan ref bernama `row-0`, `row-1`, dst.
            this.$nextTick(() => {
                const row = this.$root.querySelector(
                    `[data-row-idx="${index}"]`,
                );
                if (row && typeof row.scrollIntoView === "function") {
                    row.scrollIntoView({ block: "nearest", behavior: "smooth" });
                }
            });

            if (this.highlightTimeout) {
                clearTimeout(this.highlightTimeout);
            }

            this.highlightTimeout = setTimeout(() => {
                this.highlightedIndex = -1;
            }, 1500);
        },

        /**
         * Menghapus satu baris setelah pengguna menyetujuinya.
         *
         * Ikon hapus bersebelahan dengan seluruh isian baris, jadi salah klik
         * berarti kehilangan qty, HPP, dan kondisi yang barusan diisi. Dialog
         * datang lebih dulu dan barisnya baru hilang setelah "Hapus" ditekan.
         *
         * Parameternya `id`, bukan index: menunggu dialog membuat indeks yang
         * dihitung di awal bisa saja menunjuk baris lain ketika jawaban tiba.
         * Posisinya dicari ulang lewat id setelah konfirmasi, dan id yang tidak
         * ditemukan berarti barisnya sudah tidak ada -- bukan baris ke-n yang
         * harus ikut hilang.
         *
         * @param {string} id
         * @returns {Promise<boolean>} true bila baris benar-benar dihapus
         */
        async remove(id) {
            const index = this.items.findIndex((item) => item.id === id);

            if (index === -1) {
                return false;
            }

            const item = this.items[index];

            if (!(await this.confirmRemove(item))) {
                return false;
            }

            const current = this.items.findIndex((entry) => entry.id === id);

            if (current === -1) {
                return false;
            }

            this.items.splice(current, 1);

            if (this.highlightedIndex === current) {
                this.highlightedIndex = -1;
            }

            return true;
        },

        /**
         * Konfirmasi penghapusan satu baris.
         *
         * `notify.dangerConfirm` adalah jalur yang sama dengan tombol hapus di
         * tabel lain (`row-confirm`), dan `danger` di dalamnya bukan hiasan:
         * fokus mendarat di "Batal", sementara backdrop, Escape, dan tombol X
         * dimatikan -- kelimanya berarti "hapus" kalau dibiarkan hidup.
         *
         * Tanpa helper konfirmasi jawabannya `false`, bukan `true`. Halaman
         * yang tidak bisa menawarkan pertanyaan tidak boleh menghapus diam-diam
         * juga, karena itu persis kecelakaan yang diminta dialog ini cegah.
         *
         * Nominalnya ikut disebut seperti baris kasir: tanpa qty dan HPP yang
         * lenyap, alasan menekan "Batal" tidak ada. HPP boleh masih kosong --
         * form ini bisa diisi Operator -- jadi bagian nominalnya dilepas,
         * bukan dipaksakan menjadi `Rp0`.
         *
         * @param {object} item
         * @returns {Promise<boolean>}
         */
        async confirmRemove(item) {
            const notify = window.notify;

            if (typeof notify?.dangerConfirm !== "function") {
                return false;
            }

            const hpp = Number(item.cost_price || 0);
            const description =
                hpp > 0
                    ? `${item.name} — ${item.qty} × ${rupiah(hpp)} = ${rupiah(hpp * item.qty)}`
                    : `${item.name} — Qty ${item.qty}`;

            return notify.dangerConfirm({
                title: "Hapus dari daftar?",
                description,
                confirmText: "Hapus",
                cancelText: "Batal",
            });
        },

        getConditionOptions(conditions) {
            if (Array.isArray(conditions)) {
                return conditions;
            }

            if (conditions && typeof conditions === "object") {
                return Object.entries(conditions).map(([value, label]) => ({
                    value,
                    label,
                }));
            }

            return [];
        },
    };
}
