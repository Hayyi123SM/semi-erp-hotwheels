/**
 * Dropzone untuk langkah "Unggah" pada alur impor.
 *
 * Area yang dipakai di halaman create memang sengaja terlihat seperti
 * dropzone -- garis putus-putus, ikon berkas, teks "pilih" -- tapi itu murni
 * hiasan. `input[type=file]` disembunyikan dengan `sr-only` dan dibungkus
 * `<label>`, jadi yang benar-benar bekerja hanya KLIK. Melempar berkas ke
 * area itu tidak menjalankan apa pun: browser memperlakukan berkas yang
 * dijatuhkan di halaman sebagai tautan, mengarahkan halaman ke berkas itu,
 * dan seluruh isian form hilang bersama berkasnya.
 *
 * Komponen ini menambahkan jalan kedua menuju input yang sama, tanpa
 * menggantinya. Dua hal sengaja dijaga supaya tidak merusak apa yang
 * sudah benar:
 *
 * 1. Input native TETAP ada, tetap bernama `import_file`, tetap `required`,
 *    dan tetap berada di dalam `<label for=...>`. Artinya klik tetap
 *    membuka dialog picker oleh dirinya sendiri, dan tanpa JavaScript
 *    seluruh formulir tetap POST seperti biasa. Dropzone ini lapisan
 *    tambahan, bukan pengganti.
 * 2. Yang ditulis ke `input.files` adalah `FileList` asli dari drop, bukan
 *    objek tiruan. Jadi apa pun yang nanti dibaca server -- nama berkas,
 *    ukuran, isi -- persis berkas yang memang dilepas pengguna.
 *
 * Validasi ekstensi dan ukuran sengaja diulang di sini meski server sudah
 * punya aturannya sendiri. Server memang akan menolak, tapi penolakannya
 * sampai ke pengguna sebagai reload halaman plus toast di tempat yang
 * berbeda dari tempat dia menjatuhkan berkasnya; mengatakannya di tempat
 * yang sama menghemat satu putaran bolak-balik.
 */

const DEFAULT_ACCEPT = '.xlsx,.xls,.csv';
const DEFAULT_MAX_BYTES = 4 * 1024 * 1024;

export function importDropzone({ accept, maxBytes } = {}) {
    const extensions = String(accept ?? DEFAULT_ACCEPT)
        .split(',')
        .map((part) => part.trim().replace(/^\./, '').toLowerCase())
        .filter(Boolean);

    return {
        extensions,
        maxBytes: Number(maxBytes) || DEFAULT_MAX_BYTES,

        dragging: false,

        /**
         * Berapa banyak anak elemen yang sedang dilalui pointer.
         *
         * `dragenter` dan `dragleave` menyala untuk SETIAP elemen yang
         * dilewati, jadi satu gerakan pointer melewati isi dropzone akan
         * menyalakan keduanya -- dan tanpa penghitung ini state "sedang
         * diseret" akan berkedip di tengah gerakan yang biasa. Hitungan
         * naik-turun, bukan tombol boolean: yang benar-benar menentukan
         * "selesai diseret" adalah pointer keluar semua anak, bukan keluar
         * satu anak.
         */
        depth: 0,

        fileName: '',
        fileSize: '',
        error: '',

        dragEnter() {
            this.depth += 1;
            this.dragging = true;
        },

        /**
         * `dragover` wajib `preventDefault()`. Tanpa itu browser tidak akan
         * memunculkan peristiwa `drop` sama sekali -- aturan HTML, bukan
         * pilihan gaya. Dia juga yang mengizinkan kursor menunjukkan salinan.
         *
         * @param {DragEvent} event
         */
        dragOver(event) {
            event.preventDefault();

            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'copy';
            }
        },

        dragLeave() {
            this.depth = Math.max(0, this.depth - 1);

            if (this.depth === 0) {
                this.dragging = false;
            }
        },

        /**
         * @param {DragEvent} event
         */
        drop(event) {
            event.preventDefault();
            this.dragging = false;
            this.depth = 0;

            const files = event.dataTransfer?.files;

            if (!files || files.length === 0) {
                return;
            }

            if (files.length > 1) {
                this.clear();
                this.error = 'Pilih satu berkas saja per impor.';

                return;
            }

            // Ditulis ke input lebih dulu, lalu diperiksa. Kalau ditolak,
            // `clear()` yang mengosongkan -- jadi input yang tidak bisa
            // dibaca tidak pernah sempat terkirim ke server.
            this.$refs.input.files = files;

            if (!this.accepts(files[0])) {
                this.clear();
            }
        },

        /**
         * Berkas dipilih lewat dialog picker bawaan, bukan dijatuhkan.
         *
         * @param {Event} event
         */
        picked(event) {
            if (!this.accepts(event.target.files?.[0] ?? null)) {
                this.clear();
            }
        },

        /**
         * @param {File|null} file
         * @returns {boolean} true kalau berkas boleh dipakai
         */
        accepts(file) {
            this.error = '';

            if (!file) {
                return false;
            }

            const name = String(file.name ?? '');
            const extension = name.includes('.') ? name.split('.').pop().toLowerCase() : '';

            if (!this.extensions.includes(extension)) {
                this.error = `Berkas harus berformat .${this.extensions.join(', .')}.`;

                return false;
            }

            if (file.size > this.maxBytes) {
                this.error = `Ukuran berkas ${this.formatBytes(file.size)} melebihi batas ${this.formatBytes(this.maxBytes)}.`;

                return false;
            }

            this.fileName = name;
            this.fileSize = this.formatBytes(file.size);

            return true;
        },

        /**
         * Mengosongkan input sekaligus tampilannya, supaya berkas yang
         * ditolak tidak menggantung sebagai nama file di layar.
         */
        clear() {
            this.$refs.input.value = '';
            this.fileName = '';
            this.fileSize = '';
        },

        /**
         * @param {number} bytes
         */
        formatBytes(bytes) {
            if (!bytes) {
                return '0 B';
            }

            const units = ['B', 'KB', 'MB', 'GB'];
            const power = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
            const value = bytes / 1024 ** power;

            return `${value.toFixed(power === 0 ? 0 : 1).replace('.', ',')} ${units[power]}`;
        },
    };
}