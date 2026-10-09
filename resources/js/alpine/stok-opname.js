/**
 * Halaman Stok Opname (FR-IC-20..23).
 *
 * Sebagian besar halaman ini hidup di server: sesi, baris, blind count, dan
 * keputusan. Yang hidup di sini adalah dua hal yang harus bergerak tanpa
 * reload:
 *
 * 1. **Penghitungan beralur pindai.** Kotak cari menjadi bar pindai saat sesi
 *    menghitung: `addBySku` dikirim lewat `x-scan="addBySku($event)"`, dan tiap
 *    SKU menambah satu ke `counts[id]` lewat route `/tambah`. Servernya yang
 *    menghitung (`counted_qty + 1`) di dalam baris terkunci, jadi dua pindai
 *    cepat untuk lot yang sama tetap berujung dua angka -- yang diantre di
 *    sini (`scanQueue` per id) hanyalah permintaan-permintaan peramban itu,
 *    bukan nilainya. Angka sistem tetap tidak pernah masuk ke peramban; dari
 *    server hanya datang `counted_qty` dan status baris.
 * 2. **Badan halaman ikut bergerak** berdasar balasan tiap pindai: qty yang
 *    tampil (`counts[id]`), badge status (`statuses[id]`), progress ring,
 *    jumlah baris terhitung, dan tombol "Ajukan ke Owner" yang hidup begitu
 *    baris terakhir selesai.
 */

/**
 * Warna dan ikon sel status, dikunci per tipe (`status_type`) yang dikirim
 * server -- senada dengan kelas implementasi `x-ui.badge-status`.
 */
const STATUS_TYPE_CLASSES = {
    success: 'bg-success-bg text-success-text border-success-border',
    error: 'bg-error-bg text-error-text border-error-border',
    warning: 'bg-warning-bg text-warning-text border-warning-border',
    info: 'bg-info-bg text-info-text border-info-border',
    neutral: 'bg-canvas text-text-muted border-border-subtle',
};

const STATUS_TYPE_ICONS = {
    success: 'M5 13l4 4L19 7',
    error: 'M6 18L18 6M6 6l12 12',
    warning: 'M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z',
    info: 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
};

/**
 * @param {object} [config]
 * @param {Array<{id: number|string, sku: string}>} [config.rows] baris sesi
 * @param {object} [config.counts] id baris → `counted_qty` (boleh null)
 * @param {object} [config.statuses] id baris → `{label, type}` dari server
 * @param {object} [config.urls] id baris → URL `/tambah`
 * @param {string} [config.csrf] token CSRF untuk panggilan fetch
 */
export function stokOpname(config = {}) {
    const rows = config.rows ?? [];
    const counts = config.counts ?? {};

    return {
        review: null,
        scope: 'ALL',
        q: '',
        scanError: '',
        csrf: config.csrf ?? '',
        counts,
        statuses: config.statuses ?? {},
        urls: config.urls ?? {},
        // SKU persis seperti pada label tidak boleh ambigu: dua baris tidak
        // pernah berbagi SKU dalam satu sesi (SKU unik per lot).
        idBySku: Object.fromEntries(rows.map((row) => [row.sku, row.id])),
        // Counter per id untuk serialisasi /tambah; lihat addBySku.
        scanQueue: {},

        matches(sku) {
            const q = this.q.trim().toUpperCase();

            return q === '' || String(sku).toUpperCase().includes(q);
        },

        get progressCounted() {
            return Object.values(this.counts).filter(
                (value) => value !== null && value !== undefined,
            ).length;
        },

        get progressTotal() {
            return Object.keys(this.counts).length;
        },

        get progressPercent() {
            return this.progressTotal > 0
                ? Math.round((this.progressCounted * 100) / this.progressTotal)
                : 0;
        },

        get ringOffset() {
            return (175.9 * (1 - this.progressPercent / 100)).toFixed(2);
        },

        get countingDone() {
            return this.progressTotal > 0 && this.progressCounted >= this.progressTotal;
        },

        rowStatusLabel(id) {
            return this.statuses[id]?.label ?? '';
        },

        rowStatusClass(id) {
            return STATUS_TYPE_CLASSES[this.statuses[id]?.type] ?? STATUS_TYPE_CLASSES.neutral;
        },

        rowStatusIcon(id) {
            return STATUS_TYPE_ICONS[this.statuses[id]?.type] ?? STATUS_TYPE_ICONS.neutral;
        },

        /**
         * Pindai/Enter sebuah SKU: cocokkan persis, tambah satu, lalu kosongkan
         * kotak agar pindai berikutnya mulai dari halaman bersih.
         *
         * @param {string|KeyboardEvent} code SKU, atau `$event` dari `x-scan`
         */
        async addBySku(code) {
            const sku = (typeof code === 'string' ? code : code?.value ?? '')
                .trim()
                .toUpperCase();

            this.q = '';

            if (sku === '') {
                return;
            }

            const id = this.idBySku[sku];

            if (id === undefined) {
                this.scanError = `SKU ${sku} tidak ada di sesi opname ini.`;

                return;
            }

            // Permintaan-permintaan untuk baris yang sama diluruskan satu per
            // satu: tiap pindai berhak satu kenaikan, jadi tiap pindai dikirim
            // dan di-await secara berurutan, bukan berbondong-bondong menimpa.
            this.scanQueue[id] = (this.scanQueue[id] ?? 0) + 1;

            while (this.scanQueue[id] > 0) {
                this.scanQueue[id] -= 1;
                await this.increment(id);
            }
        },

        /**
         * Kirim satu kenaikan ke server, lalu pakai balasannya untuk menggeser
         * qty dan status baris. Gagal = pesan di layar; baris tidak disentuh.
         */
        async increment(id) {
            this.scanError = '';

            try {
                const response = await window.fetch(this.urls[id], {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrf,
                    },
                    body: new URLSearchParams(),
                });

                const data = await response.json().catch(() => ({}));

                if (!response.ok) {
                    const reasons = Object.values(data?.errors ?? {})
                        .flat()
                        .filter(Boolean);

                    this.scanError = reasons.join(' ') || 'Gagal menambah hitungan. Ulangi pindai SKU ini.';

                    return;
                }

                if (typeof data.counted_qty === 'number') {
                    this.counts[id] = data.counted_qty;
                }

                if (typeof data.status_label === 'string' && typeof data.status_type === 'string') {
                    this.statuses[id] = {
                        label: data.status_label,
                        type: data.status_type,
                    };
                }
            } catch {
                this.scanError = 'Tidak bisa menghubungi server. Periksa koneksi lalu ulangi pindai.';
            }
        },
    };
}