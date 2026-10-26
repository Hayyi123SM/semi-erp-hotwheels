/**
 * Halaman Stok Opname (FR-IC-20..23).
 *
 * Sebagian besar halaman ini hidup di server: sesi, baris, blind count, dan
 * keputusan. Yang hidup di sini adalah tiga hal yang harus bergerak tanpa
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
 * 3. **Picker SKU di modal Mulai Sesi.** Cakupan Per SKU tidak lagi menuntut
 *    kode yang diketik persis: hasil pencarian datang dari server, dan yang
 *    dipilih masuk ke form sebagai satu-satunya nilai yang sah.
 */

/** Berapa lama menunggu setelah ketikan terakhir sebelum query picker. */
const SKU_SEARCH_LATENCY_MS = 250;

/** Harus sama dengan batas server; di bawah ini belum ada yang bisa dijawab. */
const SKU_MIN_TERM_LENGTH = 2;

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
 * @param {object} [config.reviewTargets] id baris → payload dialog Review
 * @param {number|string|null} [config.failedReviewId] baris yang gagal divalidasi
 * @param {string} [config.skuUrl] URL pencarian SKU untuk modal Mulai Sesi
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
        // Payload dialog Review datang utuh dari server; tombol hanya mengirim
        // id. Baris yang gagal divalidasi membuka kembali dialognya dari sini.
        reviewTargets: config.reviewTargets ?? {},
        failedReviewId: config.failedReviewId ?? null,
        // SKU persis seperti pada label tidak boleh ambigu: dua baris tidak
        // pernah berbagi SKU dalam satu sesi (SKU unik per lot).
        idBySku: Object.fromEntries(rows.map((row) => [row.sku, row.id])),
        // Counter per id untuk serialisasi /tambah; lihat addBySku.
        scanQueue: {},

        skuUrl: config.skuUrl ?? '',
        selectedSku: null,
        skuTerm: '',
        skuItems: [],
        skuLoading: false,
        skuError: '',
        skuSearched: false,
        skuActiveIndex: -1,
        skuTimer: null,
        skuSeq: 0,

        init() {
            if (this.failedReviewId !== null && this.reviewTargets[this.failedReviewId]) {
                this.openReview(this.failedReviewId);
            }
        },

        /**
         * Buka dialog keputusan untuk satu baris.
         *
         * Id yang tidak dikenal tidak membuka apa pun: lebih baik tombol diam
         * daripada dialog yang mengirim ke alamat yang salah.
         *
         * @param {number|string} id id baris opname
         */
        openReview(id) {
            this.review = this.reviewTargets[id] ?? null;

            if (this.review !== null) {
                window.dispatchEvent(new CustomEvent('open-modal', { detail: 'review-baris' }));
            }
        },

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

        get skuMinLengthReached() {
            return this.skuTerm.trim().length >= SKU_MIN_TERM_LENGTH;
        },

        get skuHasResults() {
            return this.skuItems.length > 0;
        },

        get canStartOpname() {
            return this.scope !== 'SKU' || this.selectedSku !== null;
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
         * Ketikan baru membatalkan pilihan lama dan hasil yang masih terbang.
         *
         * Tanpa `skuSeq += 1`, jawaban untuk kata sebelumnya bisa tiba setelah
         * pengguna sudah mengetik lebih jauh dan mengisi daftar dengan hasil
         * yang tidak lagi cocok dengan kotak pencarian.
         */
        onSkuInput() {
            this.selectedSku = null;
            this.skuSeq += 1;
            this.skuItems = [];
            this.skuLoading = false;
            this.skuError = '';
            this.skuSearched = false;
            this.skuActiveIndex = -1;
            this.searchSkuSoon();
        },

        searchSku() {
            clearTimeout(this.skuTimer);

            const term = this.skuTerm.trim();

            this.skuActiveIndex = -1;

            if (term.length < SKU_MIN_TERM_LENGTH) {
                this.skuItems = [];
                this.skuSearched = false;
                this.skuError = '';
                this.skuLoading = false;

                return;
            }

            this.skuLoading = true;
            this.skuError = '';
            this.skuSearched = true;

            const ticket = (this.skuSeq += 1);

            window.fetch(this.skuUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                body: new URLSearchParams({ q: term }),
            })
                .then(async (response) => {
                    if (!response.ok) {
                        throw Object.assign(new Error(await this.skuReason(response)), { server: true });
                    }

                    return response.json();
                })
                .then((data) => {
                    if (ticket !== this.skuSeq) {
                        return;
                    }

                    this.skuItems = data.items ?? [];
                    this.skuLoading = false;
                })
                .catch((error) => {
                    if (ticket !== this.skuSeq) {
                        return;
                    }

                    this.skuItems = [];
                    this.skuLoading = false;
                    this.skuError = error.server
                        ? error.message
                        : 'Tidak bisa menghubungi server. Periksa koneksi lalu coba lagi.';
                });
        },

        searchSkuSoon() {
            clearTimeout(this.skuTimer);
            this.skuTimer = setTimeout(() => this.searchSku(), SKU_SEARCH_LATENCY_MS);
        },

        moveSku(delta) {
            if (!this.skuHasResults) {
                return;
            }

            const next = this.skuActiveIndex + delta;

            if (next < 0 || next >= this.skuItems.length) {
                return;
            }

            this.skuActiveIndex = next;
        },

        chooseSkuActive() {
            const item = this.skuItems[this.skuActiveIndex];

            if (!item) {
                return false;
            }

            this.chooseSku(item);

            return true;
        },

        submitSkuSearch() {
            if (this.chooseSkuActive()) {
                return;
            }

            this.searchSku();
        },

        chooseSku(item) {
            clearTimeout(this.skuTimer);
            this.skuSeq += 1;
            this.selectedSku = item;
            this.skuTerm = item.sku;
            this.skuItems = [];
            this.skuLoading = false;
            this.skuError = '';
            this.skuSearched = false;
            this.skuActiveIndex = -1;
        },

        clearSku() {
            clearTimeout(this.skuTimer);
            this.selectedSku = null;
            this.skuTerm = '';
            this.skuItems = [];
            this.skuLoading = false;
            this.skuError = '';
            this.skuSearched = false;
            this.skuActiveIndex = -1;
            this.skuSeq = 0;
            this.$nextTick?.(() => this.$refs.skuSearch?.focus());
        },

        async skuReason(response) {
            const data = await response.json().catch(() => ({}));

            if (data.errors) {
                return Object.values(data.errors).flat().filter(Boolean).join(' ');
            }

            return 'Pencarian SKU gagal. Coba lagi.';
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