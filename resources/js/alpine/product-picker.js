/**
 * Panel pencarian produk untuk layar kasir.
 *
 * Berdiri sendiri di dalam dialog, jadi tidak punya akses ke scope keranjang
 * yang memanggilnya: `notify.templateModal()` memindahkan markup-nya ke dalam
 * popup, dan `initTree()` membangunnya sebagai pohon Alpine sendiri. Karena itu
 * pilihan produk dikirim sebagai event window, bukan dipanggil langsung pada
 * keranjang. `row-confirm` sudah memakai jalan yang sama, dan alasannya sama:
 * pemanggilnya berada di pohon lain.
 *
 * Yang dikembalikan panel ini bukan lot, melainkan SKU-nya. Lot bisa habis antara
 * dialog dibuka dan tombolnya ditekan, jadi harga dan status yang tampil di sini
 * bisa sudah basi; keranjang menyimpan SKU-nya lalu menanyakan ulang ke server,
 * dan server yang memutuskan lagi harga, stok, dan boleh-jualnya ketika penjualan
 * disimpan. Meneruskan seluruh baris lot ke keranjang berarti keranjang menyimpan
 * angka yang sudah usang tanpa ada yang memperbaruinya.
 */

import { number, rupiah, text } from "../format";
import { readTemplateSeed } from "./template-seed";

/** Berapa lama menunggu setelah ketikan terakhir sebelum query. */
const TYPED_LATENCY_MS = 250;

/**
 * Panjang minimum untuk mengetik.
 *
 * Harus sama dengan batas server. Kalau lebih pendek di sini, kasir mengetik satu
 * huruf, mendapat "ketik minimal dua huruf", lalu mengetik satu huruf lagi --
 * dua kali ditolak untuk barang yang jelas ada. Kalau lebih panjang, kasir
 * menekan Enter pada kode yang lengkap dan mendapat nol hasil tanpa tahu kenapa.
 */
const MIN_TERM_LENGTH = 2;

/**
 * Template yang berisi panel ini di halaman kasir.
 *
 * Disebut eksplisit, bukan diteruskan lewat `options`: template hanya ada di markup
 * halaman kasir, dan nama elemen itu sudah jadi milik komponen ini.
 */
const TEMPLATE_ID = "pos-product-picker";

/**
 * Nama event yang didengarkan `cart.js`.
 *
 * Ditulis di kedua berkas, bukan diambil dari satu modul bersama: picker
 * tidak mengimpor apa pun dari keranjang dan sebaliknya. Keduanya hanya
 * sepakat pada satu nama, seperti `row-confirm` di view dan di
 * `row-confirm.js`.
 */
const ADD_ITEM_EVENT = "pos:add-item";

export function productPicker(options = {}) {
    const url = options.url ?? "";
    const notify = options.notify ?? window.notify;
    const emitEvent = options.emitEvent ?? ADD_ITEM_EVENT;

    const csrf = () =>
        document.querySelector('meta[name="csrf-token"]')?.content ?? "";

    return {
        term: "",
        items: [],
        view: "card",
        loading: false,
        error: "",
        searched: false,
        timer: null,
        emitEvent,

        /**
         * Hasil yang sedang disorot, atau -1 kalau tidak ada.
         *
         * Tidak semua kasir memakai scanner. Banyak yang mengetik, lalu memilih dari
         * daftar berarti menggulir dengan jari sementara tangan yang lain masih
         * memegang barang. Panah atas dan bawah tersedia tanpa menyentuh layar.
         *
         * -1, bukan 0, untuk keadaan tanpa sorotan: nol akan berarti "yang
         * pertama", jadi Enter tanpa sengaja akan menjual barang yang tidak pernah
         * dipilih. Enter harus selalu berarti pilihan yang disengaja.
         */
        activeIndex: -1,

        /**
         * Nomor urut penanda hasil yang sedang dibaca.
         *
         * Setiap pencarian punya nomornya sendiri, dan hanya nomor yang
         * sedang berlaku yang boleh menulis `items`. Tanpa itu, pencarian yang
         * lambat -- satu kata yang cocok banyak, lalu satu yang lebih sedikit
         * -- bisa tiba belakangan dan menimpa daftar yang lebih sempit dengan
         * hasilnya sendiri: kasir melihat barang yang tidak dia cari, lalu
         * menjual barang itu.
         */
        seq: 0,

        get minLengthReached() {
            return this.term.trim().length >= MIN_TERM_LENGTH;
        },

        get hasResults() {
            return this.items.length > 0;
        },

        init() {
            const seeded = readTemplateSeed(TEMPLATE_ID, "term");

            if (seeded !== "") {
                this.term = seeded;
            }

            this.$nextTick(() => this.$refs.search?.focus());

            if (seeded !== "") {
                this.search();
            }
        },

        /**
         * Set setelah pencarian yang benar-benar menjawab, bukan saat pengetikan
         * dimulai. "Tidak ditemukan" yang muncul sebelum orang selesai mengetik
         * adalah keluhan, bukan jawaban.
         */
        search() {
            const term = this.term.trim();

            this.activeIndex = -1;

            if (term.length < MIN_TERM_LENGTH) {
                this.items = [];
                this.searched = false;
                this.error = "";

                return;
            }

            this.loading = true;
            this.error = "";
            this.searched = true;

            const ticket = (this.seq += 1);

            fetch(url, {
                method: "POST",
                headers: { Accept: "application/json", "X-CSRF-TOKEN": csrf() },
                body: new URLSearchParams({ q: term }),
            })
                .then(async (res) => {
                    if (!res.ok) {
                        throw new Error(await this.reason(res));
                    }

                    return res.json();
                })
                .then((data) => {
                    if (ticket !== this.seq) {
                        return;
                    }

                    this.items = data.items ?? [];
                    this.loading = false;
                })
                .catch((e) => {
                    if (ticket !== this.seq) {
                        return;
                    }

                    this.items = [];
                    this.loading = false;
                    this.error = e.message;
                });
        },

        /** Debounce di sisi pemanggil, bukan di atas `search()`. */
        searchSoon() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.search(), TYPED_LATENCY_MS);
        },

        /**
         * Geser sorotan satu langkah, dan berhenti di ujung daftar.
         *
         * Berhenti, bukan memutar: daftar yang hanya satu barang tidak mungkin punya
         * "barang berikutnya", dan memutar berarti panah bawah di akhir daftar
         * melempar kasir ke baris paling atas tanpa disengaja.
         *
         * Tanpa hasil, panah tidak melakukan apa-apa. Daftar kosong adalah jawaban
         * dari server, bukan pilihan, dan membuat Enter memilih baris
         * yang tidak ada hanya menambah satu langkah yang tidak berguna.
         */
        move(delta) {
            if (!this.hasResults) {
                return;
            }

            const next = this.activeIndex + delta;

            if (next < 0 || next >= this.items.length) {
                return;
            }

            this.activeIndex = next;
        },

        /**
         * Pilih hasil yang sedang disorot.
         *
         * Mengembalikan boolean supaya Enter bisa memutuskan: benar berarti ada
         * yang dipilih, salah berarti tidak ada sorotan dan Enter seharusnya
         * menjalankan pencarian, seperti sebelumnya.
         */
        chooseActive() {
            const item = this.items[this.activeIndex];

            if (!item) {
                return false;
            }

            this.choose(item);

            return true;
        },

        /** Enter: pilih yang aktif kalau ada, kalau tidak baru cari. */
        submit() {
            if (this.chooseActive()) {
                return;
            }

            this.search();
        },

        /**
         * Pilih satu barang dan tutup dialog.
         *
         * Lot yang habis atau tidak boleh dijual tetap diteruskan ke pemanggil, yang
         * menolaknya dengan alasan. Menolaknya di sini membuat tombolnya seakan
         * tidak hidup, dan kasir akan menekan ulang tanpa tahu bahwa yang salah
         * bukan jarinya.
         *
         * Hanya SKU yang ikut. Lihat catatan di kepala berkas ini.
         */
        choose(item) {
            window.dispatchEvent(
                new CustomEvent(this.emitEvent ?? ADD_ITEM_EVENT, {
                    detail: { sku: item.sku, item },
                }),
            );
            notify.close();
        },

        /**
         * Alasan kegagalan dari server, kalau ada.
         *
         * Pesan validasi dibaca dari `errors`; untuk sisanya jawabannya berupa
         * HTML dari halaman error, dan mengembalikannya mentah ke layar akan
         * menampilkan exception beserta jejaknya.
         */
        async reason(res) {
            const data = await res.json().catch(() => ({}));

            if (data.errors) {
                return Object.values(data.errors).flat().join(" ");
            }

            return "Pencarian produk gagal. Coba lagi.";
        },

        /**
         * Format yang dipakai template-nya.
         *
         * Dibawa ke sini, bukan ditulis sebagai panggilan `rupiah(...)` di markup:
         * `x-text` mengeksekusi ekspresi di scope komponen, bukan di modul, jadi
         * fungsi yang diimpor di sini tidak akan terlihat dari sana. Dan angka yang
         * ditulis dua kali dengan aturan berbeda -- `format.rupiah()` di satu kolom,
         * `toLocaleString()` di kolom lain -- terbaca sebagai dua angka berbeda.
         */
        format: { number, rupiah, text },

        reset() {
            clearTimeout(this.timer);
            this.term = "";
            this.items = [];
            this.searched = false;
            this.error = "";
            this.loading = false;
            this.seq = 0;
            this.activeIndex = -1;
        },
    };
}
