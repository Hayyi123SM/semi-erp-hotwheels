import { rupiah, number } from "../format";
import { readTemplateSeed, seedTemplate } from "./template-seed";

/** Template di halaman kasir yang isinya dipakai dialog ini. */
const UNKNOWN_TEMPLATE_ID = "pos-unknown-sku";
const PICKER_TEMPLATE_ID = "pos-product-picker";

/** Nama event yang dikirim `productPicker.choose()`. */
const ADD_ITEM_EVENT = "pos:add-item";

/**
 * Kunci idempoten baru, huruf dan tanda hubung saja.
 *
 * `alpha_dash` di sisi server, jadi UUID cocok -- tapi kalau `crypto.randomUUID`
 * tidak ada (WebView lama, test yang memalsukan `window`), cadangannya harus
 * tetap memenuhi aturan yang sama. Dua kunci yang tidak pernah berbenturan tidak
 * bisa membedakan "percobaan ulang" dari "penjualan kedua", dan itulah satu-satunya
 * hal yang kunci ini ada untuk dilakukan.
 *
 * @returns {string}
 */
function newClientSaleId() {
    if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
        return crypto.randomUUID();
    }

    return `cs-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

/**
 * Keranjang kasir: yang terlihat di layar, dan yang menjawab ketika ada kode.
 *
 * Semua harga di sini adalah salinan dari `stock_lots.list_price` pada saat baris
 * ditambahkan, jadi bisa basi -- harga diubah di Master Data, atau lot yang sama
 * masuk keranjang dua kali sementara penjualan lain mengambil unit terakhir.
 * Karena itu keranjang tidak pernah mengesahkan harga: penjualan menyimpan ulang
 * lot dari database dan memutuskan lagi harga, stok, dan boleh-jualnya. Angka di
 * layar bisa salah; struk tidak.
 */
export function registerCart(Alpine) {
    /**
     * Badan dialog "SKU tidak ditemukan".
     *
     * Komponen terpisah, bukan `x-data="{ code: ... }"` di markup. Isi template
     * dipindahkan ke dalam popup dan `data-*` di `<template>` ikut hilang
     * bersamanya, jadi komponen yang sama seperti picker harus membaca kodenya
     * dari template -- dan itu perlu `init()` yang bisa memanggil helper.
     */
    Alpine.data("posUnknownDialog", () => ({
        code: readTemplateSeed(UNKNOWN_TEMPLATE_ID, "code"),
    }));

    Alpine.data("posCart", (initialLots = []) => ({
        items: [],
        paymentMethod: "TUNAI",

        /**
         * Uang diterima, sebagai digit saja.
         *
         * String, bukan number, dan itu bukan sekadar soal gaya: `''` is
         * nothing entered and `'0'` is zero entered, and those are different
         * screens. A number collapses them, so an empty cash field and a field
         * showing `0` would be one value to anything reading it -- and the field
         * itself would flash `0` on every reset after a sale.
         *
         * The grouping the reader sees is added by `x-money-model` on the input;
         * nothing else in here ever sees a separator. `amount()` is the one place
         * that turns it into something arithmetic.
         */
        tender: "",

        /**
         * Benar selama pembayaran sedang dikirim ke server.
         *
         * Tombol Bayar dan pintasan F8 berdua lewat `pay()`, dan F8 menekan
         * pintasan itu tanpa peduli tombolnya sedang `disabled` atau tidak.
         * Tanpa penjaga ini, satu transaksi yang lambat dan satu jari yang
         * menekan F8 dua kali akan mengirim keranjang yang sama dua kali.
         *
         * Keduanya tetap aman walau penjaganya lolos -- `client_sale_id` membuat
         * server mengembalikan nota yang sama -- tapi mencegah jauh lebih murah
         * daripada membiarkan dua permintaan berlomba di dalam transaksi yang
         * sama.
         */
        paying: false,

        /**
         * Kunci idempotensi untuk isi keranjang ini.
         *
         * Dibuat saat pembayaran pertama dikirim dan dipakai ulang oleh setiap
         * percobaan ulang dengan isi yang sama, sehingga timeout yang dibalas
         * dua kali tetap satu nota. Dihitung ulang begitu isinya berubah: kunci
         * lama menandai keranjang lama, dan kirim kuncinya untuk keranjang yang
         * sudah berbeda akan membuat server mengembalikan nota lama yang tidak
         * lagi cocok dengan yang ada di layar.
         */
        clientSaleId: "",
        cartSignature: "",

        /**
         * Hasil lookup terakhir.
         *
         * Dipisah dari `items` karena baris keranjang hanya berisi lot yang sudah
         * lolos. Menjawab "apakah SKU ini milik kita" dari sana selalu berhasil dan
         * tidak pernah menolak apa pun; pemeriksaan harusnya melihat lot yang
         * *ditolak*, dan itu hanya ada di sini.
         */
        known: [],

        /** Kode terakhir yang gagal, ditampilkan di dialog dan disemai ke picker. */
        unknownCode: "",

        /** Pesan singkat di bawah kolom scan. Dialog punya alasan untuk muncul. */
        lastError: "",

        /**
         * Endpoint pencarian, dibaca dari `data-lookup-url` di elemen pembungkus.
         *
         * Bukan konstanta di sini: file ini tidak bisa melihat route. Membacanya
         * dari markup membuat satu sumber kebenaran, dan test bisa memberikannya
         * tanpa memalsukan lokasi.
         */
        get lookupUrl() {
            return this.$root?.dataset?.lookupUrl ?? "";
        },

        /**
         * Endpoint penyelesaian pembayaran, dibaca dari `data-checkout-url`.
         *
         * Alasan yang sama dengan `lookupUrl`: file ini tidak bisa melihat route,
         * dan URL yang tertanam di markup membuat satu sumber kebenaran antara
         * halaman dan test.
         */
        get checkoutUrl() {
            return this.$root?.dataset?.checkoutUrl ?? "";
        },

        get notify() {
            return window.notify ?? null;
        },

        /**
         * Picker mengirim pilihannya sebagai event window, bukan sebagai panggilan.
         *
         * Picker hidup di pohon Alpine yang sendiri: `notify.templateModal()`
         * memindahkan markup-nya ke dalam popup dan `initTree()` membangunnya di
         * sana, jadi `this` di dalam sana bukan keranjang ini. Itulah alasan
         * event, dan alasan yang sama sudah dipakai `row-confirm`.
         *
         * Yang dikirim hanya SKU-nya, bukan lot-nya. Lot bisa habis antara dialog
         * dibuka dan tombolnya ditekan, jadi harga dan status yang tampil di
         * picker bisa sudah basi. Keranjang menanyakan ulang lewat `addBySku`,
         * jalur yang sama dengan scanner. Dua jalan masuk, satu keputusan.
         */
        init() {
            if (Array.isArray(initialLots) && initialLots.length > 0) {
                this.known = initialLots;
            }

            this.onItemPicked = (event) => {
                this.addBySku(event.detail?.sku);
            };

            window.addEventListener(ADD_ITEM_EVENT, this.onItemPicked);
        },

        /**
         * Alpine memanggil `destroy()` saat elemennya dilepas dari DOM.
         *
         * Tanpa ini listener-nya tetap menempel di `window` sambil memegang
         * komponen yang sudah tidak ada: setiap pilihan berikutnya di picker
         * menambah barang ke keranjang yang sudah tidak terlihat, dan tidak ada
         * yang bisa memberi tahu kasir soal barang yang tak terlihat itu.
         */
        destroy() {
            window.removeEventListener(ADD_ITEM_EVENT, this.onItemPicked);
        },

        get subtotal() {
            return this.items.reduce(
                (sum, item) => sum + item.price * item.qty,
                0,
            );
        },

        /**
         * Uang diterima sebagai angka.
         *
         * `Number('')` is 0, so an untouched field costs nothing to read: the change
         * is zero until something is entered, which is the answer a register should
         * give rather than an error.
         */
        get tenderAmount() {
            return Number(this.tender) || 0;
        },

        get change() {
            return Math.max(0, this.tenderAmount - this.subtotal);
        },

        get cartCount() {
            return this.items.reduce((sum, item) => sum + item.qty, 0);
        },

        /**
         * Satu lot per baris, dengan `lotId` yang akan ditagih server.
         *
         * Menambah lot yang sudah ada di keranjang menaikkan qty-nya, bukan
         * membuat baris kedua: satu lot adalah satu unit fisik, dan dua baris untuk
         * lot yang sama membuat "hapus" tidak pernah berarti apa pun.
         */
        addItem(lot) {
            const existing = this.items.find((item) => item.sku === lot.sku);

            if (existing) {
                if (!existing.canIncrease) {
                    this.$store.toast.push(
                        `Stok ${lot.sku} tinggal ${existing.stock}.`,
                        "warning",
                    );

                    return false;
                }

                existing.qty += 1;

                return true;
            }

            this.items.push({
                lotId: lot.id,
                sku: lot.sku,
                name: lot.name,
                price: lot.price,
                ownership: lot.ownership,
                consignor: lot.consignor,
                qty: 1,
                stock: lot.stock,
                get canIncrease() {
                    return this.qty < this.stock;
                },
            });

            this.$store.toast.push(`+ ${lot.name}`, "success");

            return true;
        },

        /**
         * Menaikkan qty, menolak di batas stok lot yang diketahui.
         *
         * Ditolak di sini, bukan menunggu server: melebihi batas akan menampilkan
         * jumlah yang pasti ditolak saat disimpan, dan kasir baru tahu setelah
         * menghitung kembalian.
         */
        increment(sku) {
            const item = this.items.find((i) => i.sku === sku);

            if (!item) return false;

            if (!item.canIncrease) {
                this.$store.toast.push(
                    `Stok ${sku} tinggal ${item.stock}.`,
                    "warning",
                );

                return false;
            }

            item.qty += 1;

            return true;
        },

        /**
         * Mengurangi satu, dan menghapus barisnya kalau sudah habis.
         *
         * Tidak pernah bertanya. Tombol `−` adalah kendali yang sengaja ditekan dan
         * kelihatan besar, jadi kalau satu klik lagi untuk menghapus satu baris,
         * setiap penyesuaian qty jadi dua klik -- dan penyesuaian qty jauh lebih
         * sering terjadi daripada menghapus. Yang bertanya adalah `removeItem()`,
         * untuk target kecil di tepi baris yang tidak sengaja sering kena.
         */
        decrement(sku) {
            const item = this.items.find((i) => i.sku === sku);
            if (!item) return;
            item.qty -= 1;
            if (item.qty <= 0) this.drop(sku);
        },

        /**
         * Menghapus satu baris, apa pun qty-nya.
         *
         * Dipisah dari `removeItem()` supaya pemanggil yang tidak bertanya -- Like
         * `decrement()` dan `clear()` -- tidak pernah ikut bertanya karena lupa
         * memanggil yang ini.
         *
         * @returns {boolean} true kalau barisnya ada dan dihapus
         */
        drop(sku) {
            if (!this.items.some((i) => i.sku === sku)) return false;

            this.items = this.items.filter((i) => i.sku !== sku);

            return true;
        },

        /**
         * Menghapus satu baris setelah kasir menyetujuinya.
         *
         * Pertanyaan menampilkan nama, jumlah, dan total baris, bukan sekadar
         * "hapus?": tanpa nominalnya, alasan menekan yang benar tidak ada.
         *
         * `danger: true` bukan hiasan. Itu yang membuat `notify.ask()` menutup
         * jalan keluar yang tidak sengaja: fokus mendarat di "Batal", klik
         * backdrop, Escape, dan tombol X semuanya dimatikan. Question biasa
         * membiarkan semuanya -- dan di sini semuanya berarti "ya, hapus".
         *
         * Kalau helper-nya tidak ada, jawabannya tetap tidak: menghapus tanpa
         * bisa bertanya lebih berbahaya daripada tidak menghapus.
         *
         * @returns {Promise<boolean>} true kalau baris benar-benar dihapus
         */
        async removeItem(sku) {
            const item = this.items.find((i) => i.sku === sku);

            if (!item) return false;

            const confirmed = await this.notify?.ask({
                title: "Hapus dari keranjang?",
                description: `${item.name} — ${item.qty} × ${rupiah(item.price)} = ${rupiah(item.price * item.qty)}`,
                confirmText: "Hapus",
                cancelText: "Batal",
                danger: true,
            });

            if (!confirmed) return false;

            return this.drop(sku);
        },

        clear() {
            this.items = [];
            this.tender = "";
            // Keranjang kosong adalah keranjang yang belum pernah ada, jadi kunci
            // idempotensinya ikut hilang. Memakainya lagi untuk penjualan berikutnya
            // akan membuat dua nota berbeda berbagi satu kunci, dan yang kedua
            // akan "tersimpan" sebagai duplikat dari yang pertama.
            this.clientSaleId = "";
            this.cartSignature = "";
        },

        /**
         * Isi field uang dengan nominal yang sudah ditentukan.
         *
         * Disimpan sebagai digit, bukan sebagai angka, supaya quick cash yang nilainya
         * nol tidak terlihat berbeda dari field yang dikosongkan: keduanya tampil
         * kosong. `x-money-model` yang menggabungkan angkanya setelah itu.
         */
        quickTender(amount) {
            this.tender = amount > 0 ? String(amount) : "";
        },

        /**
         * Pecahkan kode menjadi lot, lalu masukkan ke keranjang.
         *
         * Kedua jalan masuk -- scanner dan picker -- berakhir di sini, jadi ini
         * satu-satunya tempat yang memutuskan apa yang terjadi pada kode yang tidak
         * dikenal. Peristiwa itu adalah yang paling sering terjadi di meja kasir,
         * dan menjawabnya dengan diam saja membuat layar terbaca rusak.
         *
         * Kode yang diketik manual diperlakukan sama seperti hasil pindai, dengan
         * satu perbedaan: barcode dari scanner sudah selesai,
         * sedangkan yang diketik orang mungkin belum. Keduanya ditanyakan lewat
         * endpoint yang sama, jadi kode yang bisa ditemukan picker pasti bisa
         * ditemukan juga di sini -- dua pencarian untuk lot yang sama adalah cara
         * kedua ujungnya berbeda.
         */
        async addBySku(sku) {
            const code = String(sku ?? "").trim();

            this.lastError = "";

            if (code === "") {
                return false;
            }

            const lot = await this.lookup(code);

            if (lot === null) {
                this.unknownCode = code;
                this.lastError = `${code} tidak ditemukan.`;
                this.thenOpenPicker(code);

                return false;
            }

            this.known = [lot];

            if (!lot.sellable) {
                this.lastError = `${lot.sku} ${this.reason(lot)}.`;
                this.$store.toast.push(this.lastError, "warning");

                return false;
            }

            this.lastError = "";
            this.unknownCode = "";

            return this.addItem(lot);
        },

        /**
         * Ask what to do about a code nobody knows, with the code on screen.
         *
         * Two ways out, because a cashier holding an unknown barcode has two real
         * options and one of them is not "give up": look it up by name, or have the
         * goods quarantined until someone with authority identifies them. A plain
         * error message offers neither, so the cashier puts the goods down and
         * asks a person, which is slower for everyone and loses the record that it
         * happened.
         *
         * The code is repeated in the dialog because it was read off a label, and a
         * cashier who has just watched the lookup fail cannot tell from the
         * original whether the screen got it right.
         */
        askUnknown(code) {
            const notify = this.notify;

            if (notify === null) {
                return Promise.resolve(false);
            }

            // Ditanam sebelum dialog dibuka: `initTree()` berjalan di `didOpen`, jadi
            // komponen di dalam template baru ada setelah panggilan ini selesai.
            seedTemplate(UNKNOWN_TEMPLATE_ID, "code", code);

            // `modal()` mengembalikan objek hasil SweetAlert2 apa adanya, bukan
            // boolean seperti `ask()`, jadi jawabannya dibaca dari `isConfirmed`.

            return notify
                .modal({
                    title: "SKU tidak ditemukan",
                    description:
                        "Tidak ada barang dengan kode ini di daftar toko.",
                    html: this.dialogBody(UNKNOWN_TEMPLATE_ID, code),
                    size: "md",
                    showConfirmButton: true,
                    confirmText: "Cari manual",
                    cancelText: "Tutup",
                })
                .then((result) => result?.isConfirmed === true);
        },

        /**
         * Badan dialog dari `<template>`, atau teks polos kalau template hilang.
         *
         * Dialog tanpa isi terbaca sebagai halaman rusak, jadi tetap ada teks yang
         * menyebut kodenya. `notify.templateModal` sengaja membuka apa pun ketika
         * template tidak ada; dialog ini membutuhkannya untuk dua arah keluar, dan
         * kode yang hilang dari layar adalah informasi yang salah, bukan nihil.
         *
         * Bedanya dengan `templateModal`: isinya dirangkai ke dalam `html` dan
         * komponennya membaca sendiri dari template. `templateModal` tidak punya
         * tempat menitipkan nilai, dan dialog ini justru butuh satu.
         */
        dialogBody(templateId, code = "") {
            const template = document.getElementById(templateId);

            if (template === null) {
                return `<p class="font-mono text-body-lg font-semibold">${code}</p>`;
            }

            return template.innerHTML;
        },

        /**
         * Buka picker dengan kode tadi, setelah dialognya benar-benar tertutup.
         *
         * Kedua dialog berbagi satu host. Membuka yang kedua sementara yang pertama
         * masih hidup membuat focus trap yang pertama memegang simpul yang sudah
         * tidak ada di halaman, dan layarnya terbaca sebagai dialog yang tidak bisa
         * ditutup.
         */
        thenOpenPicker(term) {
            this.askUnknown(term).then((searched) => {
                if (searched) {
                    this.openPicker(term);
                }
            });
        },

        /**
         * Open the picker, seeded with the code that just failed.
         *
         * Seeding it is what makes "cari manual" usable: the cashier already has the
         * code in their hand, and asking them to type it again is asking them to do
         * the same work twice.
         *
         * The default seed is the text sitting in the scan field, read here rather
         * than mirrored into a `query` state. `x-scan` empties that field
         * directly when a scan lands, so a state mirror would go stale exactly when
         * it matters: the button pressed right after a failed scan is reading a
         * field that has already been cleared.
         */
        openPicker(term = this.$refs.scanField?.value ?? "") {
            const notify = this.notify;

            if (notify === null) {
                return;
            }

            seedTemplate(PICKER_TEMPLATE_ID, "term", term.trim());

            notify.templateModal(PICKER_TEMPLATE_ID, {
                title: "Cari Produk",
                description:
                    "Cari SKU atau nama produk, lalu pilih untuk dimasukkan ke keranjang.",
                size: "xl",
            });
        },

        /**
         * Satu request, dengan token CSRF yang dibaca setiap kali.
         *
         * Sengaja memakai endpoint yang sama dengan picker. Kalau scanner punya
         * jalurnya sendiri, memperbaiki satu perbaikan tidak akan berlaku di yang
         * lain, dan kasir yang memindai akan melihat barang yang hilang padahal
         * barang itu ada.
         */
        async lookup(code) {
            const res = await fetch(this.lookupUrl, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "X-CSRF-TOKEN": this.csrf(),
                },
                body: new URLSearchParams({ barcode: code }),
            });

            if (!res.ok) {
                return null;
            }

            const data = await res.json().catch(() => ({}));

            return data.lot ?? null;
        },

        csrf() {
            return (
                document.querySelector('meta[name="csrf-token"]')?.content ?? ""
            );
        },

        /**
         * Kenapa lot tidak bisa dijual, dalam bahasa kasir.
         *
         * "Tidak bisa dijual" saja mengirimnya mencari barcode yang hilang ketika
         * masalah sebenarnya ada di rak.
         */
        reason(lot) {
            if (lot.stock <= 0) {
                return "sudah habis";
            }

            return lot.pendingLabels > 0
                ? "labelnya belum keluar dari printer"
                : "belum tersedia untuk dijual";
        },

        /**
         * Kunci idempoten untuk isi keranjang yang sedang ada.
         *
         * Tanda tangannya isi keranjangnya, bukan waktunya: selama lot dan qty
         * tidak berubah, percobaan ulang berapa pun memakai kunci yang sama dan
         * server akan menjawab dengan nota yang sama. Begitu satu baris
         * ditambah, dikurangi, atau dihapus, tanda tangannya berbeda dan kunci
         * baru dibuat -- kunci lama menandai keranjang yang sudah tidak ada di
         * layar.
         *
         * @returns {string}
         */
        saleId() {
            const signature = this.items
                .map((item) => `${item.lotId}x${item.qty}`)
                .join(",");

            if (this.clientSaleId === "" || this.cartSignature !== signature) {
                this.clientSaleId = newClientSaleId();
                this.cartSignature = signature;
            }

            return this.clientSaleId;
        },

        /**
         * Isi permintaan pembayaran.
         *
         * Tidak ada harga di sini, dan itu bukan kekurangan: server membaca
         * ulang `stock_lots` dan memutuskan lagi harganya. Angka yang dikirim
         * hanya yang tidak bisa diketahui server -- lot mana, berapa qty, uang
         * berapa yang dipegang kasir.
         *
         * @returns {object}
         */
        payload() {
            return {
                client_sale_id: this.saleId(),
                items: this.items.map((item) => ({
                    lot_id: item.lotId,
                    qty: item.qty,
                    // Picker dan scanner berakhir di `addBySku` yang sama, jadi
                    // keranjang tidak bisa membedakan keduanya. Menuliskan
                    // "MANUAL" untuk salah satu akan menaruh label yang salah
                    // pada baris nota, dan tidak ada yang bisa membetulkannya
                    // sesudah nota tersimpan.
                    input_method: "SCAN",
                })),
                payments: [
                    {
                        method: this.paymentMethod,
                        amount: this.subtotal,
                    },
                ],
                // Uang tunai hanya untuk kasir yang memegang uang itu. QRIS dan
                // EDC tidak menghasilkan kembalian, dan mengirim `tender` untuk
                // keduanya membuat server membandingkan uang yang tidak pernah
                // dipegang siapa pun.
                tender: this.paymentMethod === "TUNAI" ? this.tenderAmount : null,
            };
        },

        /**
         * Satu kalimat kenapa pembayaran ditolak, dalam bahasa kasir.
         *
         * Pesan validasi Laravel datang per field, dan field-nya berisi istilah
         * framework (`items`, `payments`, `checkout`). Yang dibutuhkan di layar
         * hanya satu kalimat yang bisa ditindaklanjuti, jadi fieldnya dipilih
         * di sini, bukan ditampilkan apa adanya.
         *
         * @param {Response} res
         * @param {object} data
         * @returns {string}
         */
        payError(res, data) {
            const errors = data?.errors ?? {};
            const first =
                errors.items?.[0] ??
                errors.payments?.[0] ??
                errors.tender?.[0] ??
                errors.checkout?.[0] ??
                data?.message;

            if (first) {
                return first;
            }

            if (res.status === 419) {
                return "Sesi halaman sudah kedaluwarsa. Muat ulang halaman kasir.";
            }

            if (res.status === 401 || res.status === 403) {
                return "Sesi berakhir. Masuk kembali sebelum menerima pembayaran.";
            }

            return "Pembayaran gagal disimpan. Coba sekali lagi.";
        },

        /**
         * Terima pembayaran.
         *
         * Keranjang hanya dikosongkan setelah server menjawab sukses. Kegagalan
         * -- jaringan putus, stok habis di tangan kasir lain, sesi lewat --
         * meninggalkan isi keranjang apa adanya: yang hilang dari layar tidak
         * bisa dikembalikan, sedangkan keranjang yang masih utuh bisa langsung
         * diperbaiki atau dikirim ulang.
         *
         * @returns {Promise<void>}
         */
        async pay() {
            if (this.paying) {
                return;
            }

            if (this.items.length === 0) {
                this.$store.toast.push("Keranjang kosong", "warning");
                return;
            }

            if (this.subtotal < 1) {
                this.$store.toast.push("Total belanja masih Rp0.", "warning");
                return;
            }

            // Pintasan F8 tetap hidup walau tombol Bayar disabled, jadi
            // pemeriksaan uang ada di sini juga, bukan hanya di markup.
            if (this.paymentMethod === "TUNAI" && this.tenderAmount < this.subtotal) {
                this.$store.toast.push(
                    `Uang diterima ${rupiah(this.tenderAmount)} kurang dari total ${rupiah(this.subtotal)}.`,
                    "warning",
                );
                return;
            }

            this.paying = true;

            try {
                const res = await fetch(this.checkoutUrl, {
                    method: "POST",
                    headers: {
                        Accept: "application/json",
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": this.csrf(),
                    },
                    body: JSON.stringify(this.payload()),
                });

                const data = await res.json().catch(() => ({}));

                if (!res.ok) {
                    this.$store.toast.push(this.payError(res, data), "error");
                    return;
                }

                // Kembalian dibaca dari jawaban server, bukan dari `change`
                // di layar: kalau server mengoreksi harga, uang yang harus
                // dikembalikan ke pelanggan ikut berubah, dan angka yang tadi
                // sempat tertera di layar sudah basi.
                const change = Number(data.change) || 0;

                this.$store.toast.push(
                    change > 0
                        ? `Tersimpan · ${data.receipt_no} · kembalian ${rupiah(change)}`
                        : `Tersimpan · ${data.receipt_no}`,
                    "success",
                );

                this.clear();
            } catch {
                this.$store.toast.push(
                    "Tidak tersambung ke server. Penjualan belum tercatat, coba lagi.",
                    "error",
                );
            } finally {
                this.paying = false;
            }
        },

        format: { rupiah, number },
    }));
}
