/**
 * Form cetak ulang yang tahu kapan harus minta PIN Owner (FR-IB-22).
 *
 * Halaman ini punya satu masalah yang tidak punya masalahnya di form biasa:
 * batas jumlah label bergantung pada angka yang terus bergerak, jadi form
 * tidak bisa tahu apakah cetakan ini akan ditolak. Kalau form mengirim seperti
 * biasa, petugas yang lotnya sudah penuh akan melihat halaman dimuat ulang
 * dengan lot yang tadi dicentangnya sudah hilang dari checkbox -- dan dia harus
 * mencari ulang lot itu.
 *
 * Jadi form ini mengirim lewat `fetch`, dan saat server menjawab "butuh PIN",
 * dialog PIN global dibuka, tokennya ditaruh ke field, lalu dikirim ulang
 * SEKALI. Bukan berkali-kali: kalau tokennya ditolak, satu percobaan lagi
 * dengan dialog yang sama akan membuat operator mengira Ownernya salah, dan
 * membuat dialog PIN terbuka sendiri di depan orang lain yang sedang memakai
 * aplikasi.
 *
 * Tanpa JavaScript, form tetap bekerja sebagai POST biasa. Yang berubah hanya
 * bahwa penolakan dilayani sebagai redirect dengan pesan di session, seperti
 * sebelumnya -- lebih kasar, tapi tidak lebih salah.
 */

/** Field yang kesalahannya berarti "cetak ini perlu otorisasi Owner". */
const PIN_ERROR_FIELD = 'pin_token';

const GENERIC_FAILURE = 'Permintaan tidak bisa diproses. Periksa kembali form dan coba lagi.';

/**
 * @param {{endpoint: string, redirectTo: string, context: string}} options
 * @param {number[]} lotIds Id lot yang sedang tampil di tabel. Pisah dari
 *   `selected` yang di `label-queue.js` karena isinya id *job*, bukan id lot:
 *   satu form yang dua tabelnya memakai satu daftar pilihan akan salah
 *   mengirim id yang salah ke endpoint yang salah.
 */
export function reprintForm({ endpoint, redirectTo, context }, lotIds = []) {
    return {
        endpoint,
        redirectTo,
        context,
        lotIds,

        /** Lot yang dicentang operator, bukan lot yang sedang tampil. */
        selected: [],

        busy: false,
        token: '',
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
            // Penyerahan ke form biasa dilakukan di Blade, bukan di sini, lewat
            // `window.pin ? submit($event) : null`. Poinnya: kalau dialognya
            // tidak ada, form tetap mengirim seperti form biasa.
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
                let response = await this.send();

                if (response.status === 422) {
                    const verdict = await this.negotiate(response);

                    if (verdict !== 'retry') {
                        this.error = verdict;

                        return;
                    }

                    response = await this.send();

                    if (response.status === 422) {
                        this.error = await this.firstError(response);
                        this.reset();

                        return;
                    }
                }

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
                // Jaringan mati dan PIN salah membawa operator ke dua tempat
                // yang berbeda untuk diperbaiki, jadi pesannya harus menyebut
                // yang mana.
                this.error = 'Tidak bisa menghubungi server. Periksa koneksi lalu coba lagi.';
            } finally {
                this.busy = false;
            }
        },

        /**
         * Alasan kenapa server menolak: "butuh PIN", atau kalimat lain yang
         * layak dibacakan ke operator.
         *
         * @returns {Promise<'retry'|string>}
         */
        async negotiate(response) {
            const errors = await this.errorsOf(response);
            const reason = this.firstOf(errors) ?? GENERIC_FAILURE;

            if (!Object.prototype.hasOwnProperty.call(errors, PIN_ERROR_FIELD)) {
                return reason;
            }

            const grant = await window.pin?.request({
                context: this.context,
                title: 'Cetak label melebihi batas',
                description: reason,
                confirmText: 'Cetak dengan izin Owner',
            });

            // `null` berarti operator menekan Batal. Itu bukan error, dan
            // dialog harus boleh dibuka lagi kalau dia berubah pikiran --
            // jadi token yang gagal dicoba ikut dibuang, supaya percobaan
            // berikutnya tidak diam-diam memakai token kedaluwarsa.
            if (grant === null) {
                this.reset();

                return reason;
            }

            this.token = grant.token;

            return 'retry';
        },

        async send() {
            const body = new FormData(this.$el);

            // Token ditaruh di sini, bukan dibaca dari `this.$el` seperti field
            // lain. `x-model` baru menulis ke input setelah Alpine flushing,
            // sedangkan `send()` dipanggil di baris berikutnya -- jadi token
            // yang baru saja diterima dialog tidak akan ikut terkirim, dan
            // percobaan kedua akan ditolak dengan alasan yang sama seperti
            // yang pertama.
            body.delete('pin_token');

            if (this.token !== '') {
                body.append('pin_token', this.token);
            }

            return fetch(this.endpoint, {
                method: 'POST',
                headers: {
                    // Tanpa ini Laravel menjawab 302 ke halaman sebelumnya
                    // dengan pesan di session, dan `fetch` akan mengikutinya
                    // sampai ke HTML -- sehingga 422 yang dibutuhkan untuk
                    // membuka dialog tidak pernah terlihat.
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body,
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

        reset() {
            this.token = '';
        },
    };
}
