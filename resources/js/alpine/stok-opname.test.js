import { afterEach, describe, it, expect, vi } from 'vitest';
import { stokOpname } from './stok-opname';

/**
 * Komponen halaman Stok Opname: pembilang di sisi klien yang berjalan di atas
 * server. Yang diuji di sini adalah kontrak terhadap `/tambah` -- apa yang
 * dikirim, apa yang dipakai dari balasan, dan apa yang tersaji ke layar --
 * bukan logika penghitungan sesi (itu milik `OpnameService`).
 */

function respondWith(body, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: () => Promise.resolve(body),
    };
}

function mockFetch(body, status = 200) {
    return vi.fn().mockResolvedValue(respondWith(body, status));
}

function flush() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

function config(overrides = {}) {
    return {
        rows: [
            { id: 1, sku: 'CN-01-HW-001' },
            { id: 2, sku: 'CD-01-DB-002' },
        ],
        counts: { 1: 4, 2: null },
        statuses: {
            1: { label: 'Menunggu Review', type: 'warning' },
            2: { label: 'Menunggu', type: 'warning' },
        },
        urls: {
            1: '/inventory/stok-opname/7/baris/1/tambah',
            2: '/inventory/stok-opname/7/baris/2/tambah',
        },
        csrf: 'token-csrf',
        ...overrides,
    };
}

describe('stokOpname', () => {
    it('melacak baris yang sudah terhitung dari state, bukan dari DOM', () => {
        const comp = stokOpname(config());

        expect(comp.counts[1]).toBe(4);
        expect(comp.progressCounted).toBe(1);
        expect(comp.progressTotal).toBe(2);
        expect(comp.progressPercent).toBe(50);
        expect(comp.countingDone).toBe(false);
    });

    it('hidup, bukan mati, begitu baris terakhir terisi', () => {
        const comp = stokOpname(config({ counts: { 1: 4, 2: 9 } }));

        expect(comp.countingDone).toBe(true);
        expect(comp.progressPercent).toBe(100);
        expect(comp.ringOffset).toBe('0.00');
    });

    it('menyaring SKU dengan mencari substring tak peka huruf', () => {
        const comp = stokOpname(config());

        expect(comp.matches('CN-01-HW-001')).toBe(true);
        expect(comp.matches('')).toBe(true);

        comp.q = 'db-002';

        expect(comp.matches('CD-01-DB-002')).toBe(true);
        expect(comp.matches('CN-01-HW-001')).toBe(false);
        expect(comp.matches('xyz')).toBe(false);
    });

    it('menolak SKU yang bukan bagian dari sesi tanpa menyentuh server', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const comp = stokOpname(config());

        await comp.addBySku('CN-99-ZZ-000');

        expect(fetchMock).not.toHaveBeenCalled();
        expect(comp.scanError).toContain('CN-99-ZZ-000');
        expect(comp.counts[1]).toBe(4);
    });

    it('kosongkan kotak setelah pindai basket, bahkan untuk SKU yang ditolak', async () => {
        vi.stubGlobal('fetch', mockFetch({}));
        const comp = stokOpname(config());

        comp.q = 'KEKOSONGAN';
        await comp.addBySku('CD-01-DB-002');

        expect(comp.q).toBe('');
    });

    it('mengirim satu kenaikan ke /tambah dan memakai balasannya', async () => {
        const fetchMock = vi.fn().mockResolvedValue(respondWith({
            line_id: 2,
            counted_qty: 1,
            status: 'COUNTED',
            status_label: 'Menunggu Review',
            status_type: 'warning',
        }));
        vi.stubGlobal('fetch', fetchMock);
        const comp = stokOpname(config());

        await comp.addBySku('cd-01-db-002');

        const [url, init] = fetchMock.mock.calls[0];

        expect(url).toBe('/inventory/stok-opname/7/baris/2/tambah');
        expect(init.method).toBe('POST');
        expect(init.headers['X-CSRF-TOKEN']).toBe('token-csrf');
        expect(init.headers.Accept).toBe('application/json');
        expect(init.body).toBeInstanceOf(URLSearchParams);

        // qty terbaru dan status baris pindah dari balasan ke layar; angka
        // sistem tidak pernah ikut.
        expect(comp.counts[2]).toBe(1);
        expect(comp.statuses[2]).toEqual({ label: 'Menunggu Review', type: 'warning' });
        expect(comp.progressCounted).toBe(2);
        expect(comp.countingDone).toBe(true);
    });

    it('meng-queue dua pindai cepat untuk lot yang sama sampai keduanya jalan', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(respondWith({ counted_qty: 5, status_label: 'Menunggu Review', status_type: 'warning' }))
            .mockResolvedValueOnce(respondWith({ counted_qty: 6, status_label: 'Menunggu Review', status_type: 'warning' }));
        vi.stubGlobal('fetch', fetchMock);
        const comp = stokOpname(config());

        await Promise.all([comp.addBySku('CN-01-HW-001'), comp.addBySku('CN-01-HW-001')]);

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(comp.counts[1]).toBe(6);
    });

    it('membaca alasan server saat satu kenaikan ditolak dan membiarkan baris utuh', async () => {
        vi.stubGlobal('fetch', mockFetch(
            { errors: { opname: ['Sesi ini sudah diajukan.'] } },
            422,
        ));
        const comp = stokOpname(config());

        await comp.addBySku('cn-01-hw-001');

        expect(comp.scanError).toContain('Sesi ini sudah diajukan');
        expect(comp.counts[1]).toBe(4);
    });

    it('memberi tahu bahwa jaringan mati, bukan menyalahkan SKU', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
        const comp = stokOpname(config());

        await comp.addBySku('cn-01-hw-001');

        expect(comp.scanError).toContain('server');
        expect(comp.scanError).not.toContain('tidak ada');
    });

    it('menyajikan label, warna, dan ikon status dari peta tipe server', () => {
        const comp = stokOpname(config());

        expect(comp.rowStatusLabel(1)).toBe('Menunggu Review');
        expect(comp.rowStatusClass(1)).toContain('bg-warning-bg');
        expect(comp.rowStatusIcon(1)).toContain('M12 9v2');

        expect(comp.rowStatusLabel(9)).toBe('');
        expect(comp.rowStatusClass(9)).toContain('bg-canvas');
    });

    it('menggeser badge dari Menunggu ke Menunggu Review setelah satu pindai', async () => {
        vi.stubGlobal('fetch', mockFetch({ counted_qty: 1, status_label: 'Menunggu Review', status_type: 'warning' }));
        const comp = stokOpname(config());

        expect(comp.rowStatusLabel(2)).toBe('Menunggu');
        expect(comp.counts[2]).toBe(null);

        await comp.addBySku('cd-01-db-002');

        expect(comp.rowStatusLabel(2)).toBe('Menunggu Review');
        expect(comp.rowStatusClass(2)).toContain('bg-warning-bg');
        expect(comp.counts[2]).toBe(1);
    });

    it('menggeser badge ke status persetujuan bila hitungan tanpa selisih', async () => {
        vi.stubGlobal('fetch', mockFetch({ counted_qty: 2, status_label: 'Sesuai', status_type: 'success' }));
        const comp = stokOpname(config());

        await comp.addBySku('cd-01-db-002');

        expect(comp.rowStatusLabel(2)).toBe('Sesuai');
        expect(comp.rowStatusClass(2)).toContain('bg-success-bg');
        expect(comp.rowStatusIcon(2)).toContain('M5 13l4 4L19 7');
    });

    describe('dialog review', () => {
        const reviewTargets = {
            1: {
                id: 1,
                url: '/inventory/stok-opname/7/baris/1/review',
                sku: 'CN-01-HW-001',
                diff: -2,
                positive: false,
            },
        };

        it('mengambil payload dari peta server berdasarkan id', () => {
            const comp = stokOpname(config({ reviewTargets }));

            comp.openReview(1);

            expect(comp.review).toEqual(reviewTargets[1]);
        });

        it('membiarkan dialog tertutup untuk id yang tidak dikenal', () => {
            const dispatch = vi.spyOn(window, 'dispatchEvent');
            const comp = stokOpname(config({ reviewTargets }));

            comp.openReview(999);

            expect(comp.review).toBe(null);
            expect(dispatch).not.toHaveBeenCalled();
            dispatch.mockRestore();
        });

        it('membuka kembali baris yang gagal divalidasi saat halaman dimuat', () => {
            const dispatch = vi.spyOn(window, 'dispatchEvent');
            const comp = stokOpname(config({ reviewTargets, failedReviewId: 1 }));

            comp.init();

            expect(comp.review).toEqual(reviewTargets[1]);
            expect(dispatch).toHaveBeenCalledWith(
                expect.objectContaining({ detail: 'review-baris' }),
            );
            dispatch.mockRestore();
        });
    });

    describe('picker SKU', () => {
        const items = [
            {
                sku: 'CN-01-HW-001',
                name: 'Nissan Skyline GT-R R34',
                series: 'Hot Wheels',
                rack: 'A-01',
                color: 'Blue',
                year: 1999,
            },
        ];

        function picker(overrides = {}) {
            return stokOpname(config({
                skuUrl: '/inventory/stok-opname/cari-sku',
                ...overrides,
            }));
        }

        afterEach(() => {
            vi.useRealTimers();
            vi.unstubAllGlobals();
        });

        it('mengirim istilah dan CSRF ke server lalu menyimpan hasilnya', async () => {
            const fetchMock = mockFetch({ items });
            vi.stubGlobal('fetch', fetchMock);
            const comp = picker();

            comp.skuTerm = 'skyline';
            comp.searchSku();
            await flush();

            const [url, init] = fetchMock.mock.calls[0];

            expect(url).toBe('/inventory/stok-opname/cari-sku');
            expect(init.method).toBe('POST');
            expect(init.headers.Accept).toBe('application/json');
            expect(init.headers['X-CSRF-TOKEN']).toBe('token-csrf');
            expect(init.body.get('q')).toBe('skyline');
            expect(comp.skuItems).toEqual(items);
            expect(comp.skuHasResults).toBe(true);
            expect(comp.skuLoading).toBe(false);
        });

        it('menunda pencarian sampai ketikan berhenti', async () => {
            vi.useFakeTimers();
            const fetchMock = mockFetch({ items });
            vi.stubGlobal('fetch', fetchMock);
            const comp = picker();

            comp.skuTerm = 'sky';
            comp.onSkuInput();

            await vi.advanceTimersByTimeAsync(249);
            expect(fetchMock).not.toHaveBeenCalled();

            await vi.advanceTimersByTimeAsync(1);
            expect(fetchMock).toHaveBeenCalledTimes(1);
        });

        it('tidak mengirim permintaan kedua saat Enter mendahului debounce', async () => {
            vi.useFakeTimers();
            const fetchMock = mockFetch({ items });
            vi.stubGlobal('fetch', fetchMock);
            const comp = picker();

            comp.skuTerm = 'sky';
            comp.onSkuInput();
            comp.submitSkuSearch();

            expect(fetchMock).toHaveBeenCalledTimes(1);

            await vi.advanceTimersByTimeAsync(250);

            expect(fetchMock).toHaveBeenCalledTimes(1);
        });

        it('tidak menyentuh server untuk istilah di bawah dua huruf', async () => {
            vi.useFakeTimers();
            const fetchMock = mockFetch({ items });
            vi.stubGlobal('fetch', fetchMock);
            const comp = picker();

            comp.skuTerm = 's';
            comp.onSkuInput();
            await vi.advanceTimersByTimeAsync(250);

            expect(fetchMock).not.toHaveBeenCalled();
            expect(comp.skuItems).toEqual([]);
            expect(comp.skuSearched).toBe(false);
        });

        it('mengabaikan jawaban lama yang tiba setelah istilah berubah', async () => {
            let resolveFirst;
            const first = new Promise((resolve) => {
                resolveFirst = resolve;
            });
            const second = [{ ...items[0], sku: 'CD-01-DB-002', name: 'Mazda RX-7' }];
            const fetchMock = vi.fn()
                .mockReturnValueOnce(first)
                .mockResolvedValueOnce(respondWith({ items: second }));
            vi.stubGlobal('fetch', fetchMock);
            const comp = picker();

            comp.skuTerm = 'skyline';
            comp.searchSku();
            comp.skuTerm = 'mazda';
            comp.searchSku();
            await flush();

            expect(comp.skuItems).toEqual(second);

            resolveFirst(respondWith({ items }));
            await flush();

            expect(comp.skuItems).toEqual(second);
            expect(comp.skuLoading).toBe(false);
        });

        it('memilih hasil dengan keyboard, menyimpan SKU, dan membersihkan daftar', () => {
            const comp = picker();

            comp.scope = 'SKU';
            comp.skuTerm = 'sky';
            comp.skuItems = items;
            comp.moveSku(1);

            expect(comp.skuActiveIndex).toBe(0);
            expect(comp.canStartOpname).toBe(false);

            comp.submitSkuSearch();

            expect(comp.selectedSku).toEqual(items[0]);
            expect(comp.skuTerm).toBe(items[0].sku);
            expect(comp.skuItems).toEqual([]);
            expect(comp.canStartOpname).toBe(true);
        });

        it('mengembalikan tombol Mulai Sesi ke keadaan mati saat pilihan diganti', () => {
            const comp = picker();

            comp.scope = 'SKU';
            comp.chooseSku(items[0]);

            expect(comp.canStartOpname).toBe(true);

            comp.clearSku();

            expect(comp.selectedSku).toBe(null);
            expect(comp.skuTerm).toBe('');
            expect(comp.canStartOpname).toBe(false);
        });

        it('memakai alasan validasi server apa adanya', async () => {
            vi.stubGlobal('fetch', mockFetch(
                { errors: { q: ['Istilah pencarian terlalu panjang.'] } },
                422,
            ));
            const comp = picker();

            comp.skuTerm = 'skyline';
            comp.searchSku();
            await flush();

            expect(comp.skuError).toBe('Istilah pencarian terlalu panjang.');
        });

        it('menyebut masalah koneksi, bukan menyalahkan istilah', async () => {
            vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
            const comp = picker();

            comp.skuTerm = 'skyline';
            comp.searchSku();
            await flush();

            expect(comp.skuError).toContain('server');
            expect(comp.skuError).not.toContain('offline');
        });
    });
});
