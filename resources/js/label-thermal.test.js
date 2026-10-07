import { beforeEach, afterEach, describe, expect, it, vi } from "vitest";

/**
 * label-thermal menghubungkan halaman label ke printer lewat WebUSB.
 *
 * Test memakai `transport` yang dimasukkan langsung, jadi yang diuji adalah
 * kontrak modul: URL diambil dari halaman, ids dikirim ke server, teks yang
 * kembali diteruskan ke transport, dan setiap kegagalan memanggil `onFailed`
 * (blade lalu jatuh ke `window.print()`) -- cetakan tidak pernah hilang
 * diam-diam.
 */

const TEXT = 'SIZE 100 mm,150 mm\nCLS\nQRCODE 31,5,M,2,A,0,"CN01"\nPRINT 1';

function freshModule() {
    vi.resetModules();
    return import("./label-thermal");
}

describe("label-thermal", () => {
    beforeEach(() => {
        window.alert = vi.fn();
        document.body.innerHTML =
            '<meta name="csrf-token" content="token-abc"><div id="root"></div>';
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        document.body.innerHTML = "";
    });

    it("mengirim ids ke url dan meneruskan teks ke transport", async () => {
        const module = await freshModule();
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ ok: true, text: TEXT }),
        });
        vi.stubGlobal("fetch", fetchMock);

        const transport = vi.fn().mockResolvedValue();
        const onFailed = vi.fn();

        await module.printLabelThermal(
            { url: "/inbound/cetak-label/tsp", ids: [3, 1], onFailed },
            transport,
        );

        expect(fetchMock).toHaveBeenCalledWith(
            "/inbound/cetak-label/tsp",
            expect.objectContaining({
                method: "POST",
                headers: expect.objectContaining({
                    "X-CSRF-TOKEN": "token-abc",
                }),
            }),
        );
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ ids: [3, 1] });
        expect(transport).toHaveBeenCalledWith(TEXT);
        expect(onFailed).not.toHaveBeenCalled();
    });

    it("jatuh ke onFailed saat transport menolak", async () => {
        const module = await freshModule();
        vi.stubGlobal("fetch", vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ ok: true, text: TEXT }),
        }));

        const transport = vi.fn().mockRejectedValue(new Error("Usb tidak tersedia."));
        const onFailed = vi.fn();

        await module.printLabelThermal(
            { url: "/x", ids: [1], onFailed },
            transport,
        );

        expect(onFailed).toHaveBeenCalledTimes(1);
    });

    it("memberitahu onFailed saat server menolak request", async () => {
        const module = await freshModule();
        vi.stubGlobal("fetch", vi.fn().mockResolvedValue({
            ok: false,
            status: 422,
            json: async () => ({ ok: false, message: "Terlalu banyak label." }),
        }));

        const onFailed = vi.fn();

        await module.printLabelThermal(
            { url: "/x", ids: [1], onFailed },
            vi.fn().mockResolvedValue(),
        );

        expect(window.alert).toHaveBeenCalledWith("Terlalu banyak label.");
        expect(onFailed).toHaveBeenCalledTimes(1);
    });

    it("transport menerima teks penuh, bukan memecah-pecah perintah", async () => {
        const module = await freshModule();
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ ok: true, text: TEXT }),
        });
        vi.stubGlobal("fetch", fetchMock);

        const written = [];
        const transport = vi.fn().mockImplementation(async (text) => {
            written.push(text);
        });

        await module.printLabelThermal({ url: "/x", ids: [7, 8] }, transport);

        // Transport hanya boleh menerima teks lengkap; koordinat sudah dihitung
        // server di `TspLabelJobBuilder`. Kalau transport ikut mengurai,
        // tidak ada pengurai yang lebih benar dari server tempat perintah lahir.
        expect(written).toEqual([TEXT]);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});