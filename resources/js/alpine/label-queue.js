/**
 * Antrean label: satu daftar, beberapa aksi yang saling tidak compatible.
 *
 * Setiap aksi hanya berlaku untuk satu status -- QUEUED bisa "sudah dicetak",
 * SENT bisa "konfirmasi" atau "gagal", FAILED bisa "coba ulang". Kalau
 * operator bisa memilih job bercampur dan menekan tombol yang tidak berlaku
 * untuk semuanya, yang terjadi hanya setengah jalan: sebagian berubah, sebagian
 * ditolak validasi, dan operator tidak tahu mana yang mana.
 *
 * Jadi setiap tombol di sini hanya aktif kalau SEMUA job yang dipilih
 * memang punya status yang boleh memakai tombol itu. Job yang dicentang
 * bersama masih bisa salah; yang berubah adalah tombolnya mati sebelum
 * operator-tekan, bukan error setelahnya.
 *
 * "Tampilkan untuk Dicetak" pengecualiannya: itu hanya membaca dan merender,
 * jadi boleh untuk status apa pun -- termasuk FAILED yang harus dicoba ulang.
 *
 * @param {Record<string, string>} statuses
 * @param {number[]} jobIds Id job yang benar-benar ada di tabel, dalam urutan
 *   yang sama seperti yang dirender. Select-all memakai daftar ini, bukan
 *   `Object.keys(statuses)`: peta status bisa berisi id yang tidak sedang
 *   tampil kalau query nanti dibatasi, dan mencentang baris yang tidak ada di
 *   halaman akan mengirim id yang tidak bisa di-render.
 */
export function labelQueue(statuses = {}, jobIds = []) {
    return {
        selected: [],
        statuses,
        jobIds,

        showFail: false,
        failMessage: '',
        failError: '',

        selectedCount() {
            return this.selected.length;
        },

        /**
         * Header checkbox punya tiga keadaan. `checked` adalah dua dari
         * antaranya; keadaan ketiga -- sebagian terpilih -- tidak punya
         * direktif Alpine, jadi di sini disimpan terpisah lalu dipasang lewat
         * `$refs` dari Blade.
         *
         * `.checked` tetap ditulis karena tanpa itu browser menampilkan kotak
         * tercentang penuh saat sebagian baris terpilih, dan itu berarti
         * semua baris sudah dipilih -- yang tidak benar.
         */
        allSelected() {
            return this.jobIds.length > 0 && this.selectedCount() === this.jobIds.length;
        },

        someSelected() {
            return this.selectedCount() > 0 && !this.allSelected();
        },

        toggleAll() {
            this.selected = this.allSelected() ? [] : [...this.jobIds];
        },

        isSelected(id) {
            return this.selected.includes(id);
        },

        /**
         * `indeterminate` adalah properti DOM, bukan atribut HTML, jadi Alpine
         * tidak bisa menuliskannya lewat binding dan harus disetel imperatif.
         *
         * Dipasang di `init` dan lewat `$watch`: mengubah pilihan baris tidak
         * menyentuh `$refs` sama sekali, jadi tanpa watcher ini header hanya
         * benar sesaat setelah halaman dimuat -- persis saat operator sedang
         * centang satu per satu.
         */
        init() {
            this.syncSelectAll();
            this.$watch('selected', () => this.syncSelectAll());
        },

        syncSelectAll() {
            if (this.$refs.selectAll) {
                this.$refs.selectAll.indeterminate = this.someSelected();
            }
        },

        countWithStatus(status) {
            return this.selected.filter((id) => this.statuses[id] === status).length;
        },

        /**
         * True hanya kalau ada yang dipilih dan semuanya berstatus sama.
         *
         * `selected.length > 0` diperiksa terpisah supaya "tidak ada pilihan"
         * tidak pernah terbaca sebagai "semua cocok", yang akan menyalakan
         * tombol untuk selection kosong.
         */
        allMatch(status) {
            return this.selected.length > 0 && this.countWithStatus(status) === this.selected.length;
        },

        canPrint() {
            return this.allMatch('QUEUED');
        },

        canConfirm() {
            return this.allMatch('SENT');
        },

        canRetry() {
            return this.allMatch('FAILED');
        },

        /**
         * Preview tidak mengubah apa pun, jadi tidak perlu dibatasi status.
         */
        canPreview() {
            return this.selected.length > 0;
        },

        /**
         * Daftar status yang ada di antara yang dipilih, untuk menjelaskan ke
         * operator kenapa tombolnya mati.
         */
        mixedStatuses() {
            return [...new Set(this.selected.map((id) => this.statuses[id]))];
        },

        openFail() {
            if (!this.canConfirm()) {
                return false;
            }

            this.failMessage = '';
            this.failError = '';
            this.showFail = true;

            return true;
        },

        closeFail() {
            this.showFail = false;
        },

        /**
         * Alasan gagal wajib diisi sebelum form dikirim.
         *
         * Dicek di sini supaya operator mendapat umpan balik di dalam dialog
         * dan tidak kehilangan isi form; `required` di server tetap ada
         * karena pemeriksaan ini bisa dilewati dari luar.
         */
        submitFail() {
            if (this.failMessage.trim() === '') {
                this.failError = 'Tuliskan apa yang salah. Tanpa alasan, percetakan ulang mengulang kesalahan yang sama.';
                return false;
            }

            this.$refs.failForm.requestSubmit();

            return true;
        },
    };
}
