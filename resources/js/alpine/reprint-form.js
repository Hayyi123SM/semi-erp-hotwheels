/**
 * Form cetak ulang yang tahu cara mengirim tanpa kehilangan centangan.
 *
 * Halaman ini punya satu masalah yang tidak punya masalahnya di form biasa:
 * batas jumlah label bergantung pada angka yang terus bergerak, jadi server
 * bisa menolak sebuah cetakan yang tadi masih di dalam batas. Kalau form
 * mengirim seperti biasa, petugas yang lotnya sudah penuh akan melihat
 * halaman dimuat ulang dengan lot yang tadi dicentangnya sudah hilang dari
 * checkbox -- dan dia harus mencari ulang lot itu.
 *
 * Jadi form ini mengirim lewat `fetch`. Jawaban "ditolak" dibaca dari isi
 * JSON-nya dan ditampilkan sebagai pesan, tanpa memuat ulang halaman --
 * centangan petugas tidak hilang, dan dia bisa langsung mengoreksi angka
 * atau alasannya.
 *
 * Tanpa JavaScript, form tetap bekerja sebagai POST biasa. Yang berubah hanya
 * bahwa penolakan dilayani sebagai redirect dengan pesan di session, seperti
 * sebelumnya -- lebih kasar, tapi tidak lebih salah.
 */

const GENERIC_FAILURE = 'Permintaan tidak bisa diproses. Periksa kembali form dan coba lagi.';

/**
 * @param {{endpoint: string, redirectTo: string}} options
 * @param {number[]} lotIds Id lot yang sedang tampil di tabel. Pisah dari
 *   `selected` yang di `label-queue.js` karena isinya id *job*, bukan id lot:
 *   satu form yang dua tabelnya memakai satu daftar pilihan akan salah
 *   mengirim id yang salah ke endpoint yang salah.
 */
export function reprintForm({ endpoint, redirectTo }, lotIds = []) {
    return {
        endpoint,
        redirectTo,
        lotIds,

        /** Lot yang dicentang operator, bukan lot yang sedang tampil. */
        selected: [],

        busy: false,
        error: '',

        /**
         * Jumlah lot yang dicentang, untuk dibaca operator sebelum menekan
         * tombol. Tanpa ini form yang sudah memvalidasi di server tetap
         * terlihat siap kirim padahal tidak ada isinya.
         */
        selectedCount() {
            return this.selected.length;
        },

        /**
         * False saat tidak ada lot dipilih, supaya tombolnya mati di klien.
         * Validasi `required` di server tetap ada karena pemeriksaan ini bisa
         * dilewati -- `fetch` tetap jalan walau tombol mati.
         */
        canSubmit() {
            return this.selectedCount() > 0 && !this.busy;
        },

        allSelected() {
            return this.lotIds.length > 0 && this.selectedCount() === this.lotIds.length;
        },

        someSelected() {
            return this.selectedCount() > 0 && !this.allSelected();
        },

        toggleAll() {
            this.selected = this.allSelected() ? [] : [...this.lotIds];
        },

        init() {
            this.syncSelectAll();
            this.$watch('selected', () => this.syncSelectAll());
        },

        /**
         * `indeterminate` adalah properti DOM, bukan atribut HTML. Pola yang
         * sama dipakai `label-queue.js` untuk header tabel antrean.
         */
        syncSelectAll() {
            if (this.$refs.selectAll) {
                this.$refs.selectAll.indeterminate = this.someSelected();
            }
        },

        /**
         * @param {Event} event
         */
        submit(event) {
            event.preventDefault();

            return this.run();
        },

        async run() {
            if (this.busy) {
                return;
            }

            this.busy = true;
            this.error = '';

            try {
                const response = await this.send();

                if (!response.ok) {
                    this.error = (await this.firstError(response)) ?? GENERIC_FAILURE;

                    return;
                }

                // Server menjawab dengan redirect ke halaman antrean, dan di
                // sana pesannya ada di session sebagai toast. `redirectTo`
                // disalin dari Blade supaya perpindahan ini tidak bergantung
                // pada `response.url` yang sudah mengikuti redirect.
                window.location.assign(this.redirectTo);
            } catch (error) {
                // Jaringan mati membawa operator ke tempat yang berbeda untuk
                // diperbaiki, jadi pesannya harus menyebut yang mana.
                this.error = 'Tidak bisa menghubungi server. Periksa koneksi lalu coba lagi.';
            } finally {
                this.busy = false;
            }
        },

        async send() {
            return fetch(this.endpoint, {
                method: 'POST',
                headers: {
                    // Tanpa ini Laravel menjawab 302 ke halaman sebelumnya
                    // dengan pesan di session, dan `fetch` akan mengikutinya
                    // sampai ke HTML -- sehingga 422 yang dibutuhkan untuk
                    // menampilkan pesan tidak pernah terlihat.
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: new FormData(this.$el),
            });
        },

        /**
         * @returns {Promise<Record<string, string[]>>}
         */
        async errorsOf(response) {
            const data = await response.json().catch(() => ({}));

            return data?.errors ?? {};
        },

        /**
         * @returns {Promise<string|null>}
         */
        async firstError(response) {
            const errors = await this.errorsOf(response);

            return this.firstOf(errors) ?? (response.ok ? null : GENERIC_FAILURE);
        },

        firstOf(errors) {
            return Object.values(errors)
                .flat()
                .filter(Boolean)[0] ?? null;
        },
    };
}
