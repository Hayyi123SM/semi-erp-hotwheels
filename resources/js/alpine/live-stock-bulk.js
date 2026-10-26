/**
 * State aksi halaman Live Stock: pilihan baris untuk "Pindah Rak" massal dan
 * lot yang sedang dikoreksi lewat "Kurangi Stok".
 *
 * Pilihan baris tidak disimpan di state Alpine: checkbox memakai atribut
 * `form`, sehingga baris yang terpilih adalah baris yang sedang tampil, dan
 * refresh in-place tabel otomatis mengosongkannya. Yang dipegang komponen ini
 * hanya angka untuk tombol aksi.
 *
 * Semua mutasi dipanggil dari scope halaman lewat `@live-stock:*`. Kontrol di
 * toolbar dan modal hidup di dalam `x-data` tabel yang bersarang; menulis
 * `count` dari sana memunculkan properti bayangan yang tidak pernah dibaca
 * halaman, jadi kontrol hanya `$dispatch` dan handler di bawah yang mengubah.
 */
export function liveStockBulk() {
    return {
        /** Lot yang dipilih untuk dialog pindah rak per baris (dipasang modal). */
        transfer: null,

        /** Lot yang dipilih untuk dialog koreksi stok (dipasang modal). */
        reduce: null,

        /** Jumlah baris tercentang, untuk label dan status tombol toolbar. */
        count: 0,

        init() {
            this.sync();
        },

        /**
         * Seluruh centang yang sedang tampil.
         *
         * `$root` adalah elemen halaman, bukan tabel: tabel diganti in-place,
         * jadi querinya harus dijalankan ulang setiap kali, tidak disimpan.
         */
        boxes() {
            return Array.from(this.$root.querySelectorAll('[data-bulk-lot]'));
        },

        /** Hitung ulang setelah centang berubah atau tabel diganti. */
        sync() {
            this.count = this.boxes().filter((box) => box.checked).length;
        },

        /** Centang semua baris di halaman yang sedang tampil. */
        selectVisible() {
            this.boxes().forEach((box) => {
                box.checked = true;
            });

            this.sync();
        },

        clearAll() {
            this.boxes().forEach((box) => {
                box.checked = false;
            });

            this.sync();
        },

        openBulk() {
            this.sync();

            if (this.count === 0) {
                return;
            }

            this.$dispatch('open-modal', 'pindah-rak-bulk');
        },
    };
}
