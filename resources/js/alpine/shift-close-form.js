/**
 * Form tutup shift kasir.
 *
 * Form ini punya masalah yang tidak punya masalahnya di form biasa: hasil
 * pengirimannya bukan satu keadaan tetap. Selisih di dalam ambang langsung
 * menutup shift, sedangkan selisih di luar ambang yang diajukan Staff berhenti
 * sebagai `PENDING_APPROVAL` dan menunggu keputusan Owner. Kasir perlu tahu
 * yang mana sebelum menekan tombol, karena yang kedua berarti lacinya terkunci
 * sampai Owner memutuskan.
 *
 * Karena itu form dikirim lewat `fetch`: jawaban "ditolak" dibaca dari JSON dan
 * ditampilkan tanpa memuat ulang halaman, sehingga angka yang barusan diketik
 * tidak hilang. Tanpa JavaScript, form tetap bekerja sebagai POST biasa -- yang
 * berubah hanya penolakan yang muncul sebagai redirect dengan pesan di session.
 *
 * Pratinjau selisihnya ada di sini karena itu satu-satunya tempat yang berubah
 * setiap kali kasir mengetik angka. Yang menentukan apakah persetujuan dibutuhkan
 * tetap server, dari rekap yang dihitung ulang dari database: angka di layar bisa
 * berasal dari tab yang sudah lama terbuka.
 */

const GENERIC_FAILURE = 'Shift tidak bisa ditutup. Periksa kembali form dan coba lagi.';

/**
 * @param {{endpoint: string, redirectTo: string, expectedCash: number, threshold: number, isOwner: boolean}} options
 */
export function shiftCloseForm({ endpoint, redirectTo, expectedCash, threshold, isOwner = false }) {
    return {
        endpoint,
        redirectTo,
        expectedCash: Number(expectedCash) || 0,
        threshold: Number(threshold) || 0,
        isOwner,

        /** Yang diketik kasir, sebagai angka. Kosong berarti belum diisi. */
        closing: '',

        busy: false,
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
         * Apakah hitungan ini akan berhenti menunggu Owner.
         *
         * Owner tidak termasuk: dia boleh menutup shift dengan selisih berapa
         * pun, jadi peringatan "menunggu persetujuan" untuknya akan salah.
         */
        needsApproval() {
            const difference = this.difference;

            if (difference === null || this.isOwner) {
                return false;
            }

            return Math.abs(difference) > this.threshold;
        },

        canSubmit() {
            return this.closingCash !== null && !this.busy;
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

                // Server menjawab dengan redirect ke halaman shift, dan di sana
                // pesannya ada di session sebagai toast. `redirectTo` disalin dari
                // Blade supaya perpindahan ini tidak bergantung pada
                // `response.url` yang sudah mengikuti redirect.
                window.location.assign(this.redirectTo);
            } catch (error) {
                this.error = 'Tidak bisa menghubungi server. Periksa koneksi lalu coba lagi.';
            } finally {
                this.busy = false;
            }
        },

        async send() {
            return fetch(this.endpoint, {
                method: 'POST',
                headers: {
                    // Tanpa ini Laravel menjawab 302 ke halaman sebelumnya dengan
                    // pesan di session, dan `fetch` akan mengikutinya sampai ke
                    // HTML -- sehingga 422 yang dibutuhkan untuk menampilkan
                    // pesan tidak pernah terlihat.
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
    };
}
