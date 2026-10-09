import { describe, it, expect, vi } from 'vitest';
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
});