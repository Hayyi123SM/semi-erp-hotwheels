/**
 * Form tutup shift kasir.
 *
 * Form ini punya masalah yang tidak punya masalahnya di form biasa: PIN Owner-nya
 * bukan tetap ada atau tetap tidak ada, melainkan bergantung pada selisih antara
 * uang yang diketik kasir dan uang yang seharusnya ada -- angka yang hanya dikenal
 * setelah kasir selesai menghitung. Jadi form ini tidak bisa tahu dari sejak awal
 * apakah pengiriman berikutnya akan ditolak karena kurang PIN.
 *
 * Karena itu form dikirim lewat `fetch`. Kalau server menjawab "butuh PIN", dialog
 * PIN global dibuka, tokennya ditaruh ke field, lalu dikirim ulang SEKALI. Bukan
 * berkali-kali: kalau tokennya ditolak, percobaan kedua dengan dialog yang sama
 * membuat kasir mengira Ownernya salah, dan membuat dialog PIN terbuka sendiri di
 * depan orang lain yang sedang memakai aplikasi.
 *
 * Pratinjau selisihnya ada di sini, bukan di server, karena itu satu-satunya
 * tempat yang bisa berubah setiap kali kasir mengetik angka. Yang dipratinjau
 * HANYA untuk dibaca kasir: keputusan meminta PIN tetap milik server, dari
 * rekap yang dihitung ulang dari database. Kalau pratinjau ini yang menentukan,
 * maka mengetik angka yang kebetulan cocok akan melewati persetujuan yang
 * seharusnya diminta -- persis yang harus dicegah oleh kata "pratinjau".
 *
 * Tanpa JavaScript, form tetap bekerja sebagai POST biasa. Yang berubah hanya
 * bahwa penolakan dilayani sebagai redirect dengan pesan di session, seperti
 * sebelumnya -- lebih kasar, tapi tidak lebih salah.
 */

const PIN_ERROR_FIELD = 'pin_token';

const GENERIC_FAILURE = 'Shift tidak bisa ditutup. Periksa kembali form dan coba lagi.';

/**
 * @param {{endpoint: string, redirectTo: string, context: string, expectedCash: number, threshold: number, isOwner: boolean}} options
 */
export function shiftCloseForm({ endpoint, redirectTo, context, expectedCash, threshold, isOwner = false }) {
    return {
        endpoint,
        redirectTo,
        context,
        expectedCash: Number(expectedCash) || 0,
        threshold: Number(threshold) || 0,
        isOwner,

        /** Yang diketik kasir, sebagai angka. Kosong berarti belum diisi. */
        closing: '',

        busy: false,
        token: '',
        error: '',

        /**
         * Uang yang diketik, atau `null` kalau belum ada.
         *
         * `null` dan bukan nol: kolom yang belum diisi bukan uang nol, dan
         * membedakan keduanya mencegah "selisih Rp100.000" muncul di layar
         * sebelum kasir mengetik apa pun.
         */
        get closingCash() {
            const raw = String(this.closing).replace(/\D/g, '');

            return raw === '' ? null : Number(raw);
        },

        /**
         * Selisih seperti yang akan dihitung server, atau `null` sampai ada
         * angka yang masuk.
         */
        get difference() {
            const cash = this.closingCash;

            return cash === null ? null : cash - this.expectedCash;
        },

        /**
         * Apakah selisihnya di luar ambang Owner.
         *
         * Owner tidak termasuk: dia tidak diminta PIN untuk shiftnya sendiri,
         * jadi peringatan "akan diminta PIN" untuknya akan salah.
         */
        needsOwnerPin() {
            const difference = this.difference;

            if (difference === null || this.isOwner) {
                return false;
            }

            return Math.abs(difference) > this.threshold;
        },

        /**
         * Dipakai untuk mematikan tombolnya sebelum ada yang bisa dikirim, dan
         * sebagai kalimat "{n} transaksi" -- bukan hanya sebagai tombol yang hidup.
         */
        canSubmit() {
            return this.closingCash !== null && !this.busy;
        },

        /**
         * Penyerahan ke form biasa dilakukan di Blade, bukan di sini, lewat
         * `window.pin ? submit($event) : null`. Kalau dialognya tidak ada, form
         * tetap mengirim seperti form biasa.
         *
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

                // Server menjawab dengan redirect ke halaman shift, dan di sana
                // pesannya ada di session sebagai toast. `redirectTo` disalin dari
                // Blade supaya perpindahan ini tidak bergantung pada
                // `response.url` yang sudah mengikuti redirect.
                window.location.assign(this.redirectTo);
            } catch (error) {
                // Jaringan mati dan PIN salah membawa kasir ke dua tempat yang
                // berbeda untuk diperbaiki, jadi pesannya harus menyebut yang mana.
                this.error = 'Tidak bisa menghubungi server. Periksa koneksi lalu coba lagi.';
            } finally {
                this.busy = false;
            }
        },

        /**
         * Alasan kenapa server menolak: "butuh PIN", atau kalimat lain yang layak
         * dibacakan ke kasir.
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
                title: 'Selisih kas di luar ambang',
                description: reason,
                confirmText: 'Tutup dengan izin Owner',
            });

            // `null` berarti kasir menekan Batal. Itu bukan error, dan dialog
            // harus boleh dibuka lagi kalau dia berubah pikiran -- jadi token
            // yang gagal dicoba ikut dibuang, supaya percobaan berikutnya tidak
            // diam-diam memakai token kedaluwarsa.
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
            // sedangkan `send()` dipanggil di baris berikutnya -- jadi token yang
            // baru saja diterima dialog tidak akan ikut terkirim, dan percobaan
            // kedua akan ditolak dengan alasan yang sama seperti yang pertama.
            body.delete('pin_token');

            if (this.token !== '') {
                body.append('pin_token', this.token);
            }

            return fetch(this.endpoint, {
                method: 'POST',
                headers: {
                    // Tanpa ini Laravel menjawab 302 ke halaman sebelumnya dengan
                    // pesan di session, dan `fetch` akan mengikutinya sampai ke
                    // HTML -- sehingga 422 yang dibutuhkan untuk membuka dialog
                    // tidak pernah terlihat.
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

        /**
         * @param {Record<string, string[]>} errors
         * @returns {string|null}
         */
        firstOf(errors) {
            for (const key of Object.keys(errors)) {
                const reasons = errors[key].filter(Boolean);

                if (reasons.length > 0) {
                    return reasons.join(' ');
                }
            }

            return null;
        },

        /** Buang token yang sudah dicoba, supaya percobaan berikutnya bersih. */
        reset() {
            this.token = '';
        },
    };
}
