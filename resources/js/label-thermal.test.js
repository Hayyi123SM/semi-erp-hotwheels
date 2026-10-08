import { beforeEach, afterEach, describe, expect, it, vi } from "vitest";

/**
 * label-thermal menghubungkan halaman label ke printer lewat Web Bluetooth
 * (transport bawaan) atau WebUSB (tombol `data-transport="usb"`).
 *
 * Test memakai `transport` yang dimasukkan langsung, jadi yang diuji adalah
 * kontrak modul: URL diambil dari halaman, ids dikirim ke server, teks yang
 * kembali diteruskan ke transport, dan setiap kegagalan memanggil `onFailed`
 * (blade lalu jatuh ke `window.print()`) -- cetakan tidak pernah hilang
 * diam-diam. Pembatalan dialog dibedakan dari kegagalan: yang satu hanya
 * menampilkan "Dibatalkan." tanpa cetak browser.
 */

const TEXT = 'SIZE 100 mm,150 mm\nCLS\nQRCODE 31,5,M,2,A,0,"CN01"\nPRINT 1';

function freshModule() {
    vi.resetModules();
    return import("./label-thermal");
}

beforeEach(() => {
    window.alert = vi.fn();
    // `initLabelThermal` menulis `onFailed` sendiri (`window.print`), jadi
    // jalur DOM tidak bisa menyuntikkan transport pengganti di setiap tes --
    // happy-dom tidak punya `window.print`, dan tanpa ini kegagalan transport
    // berubah jadi unhandled rejection, bukan assertion.
    window.print = vi.fn();
    document.body.innerHTML =
        '<meta name="csrf-token" content="token-abc"><div id="root"></div>';
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    document.body.innerHTML = "";
});

describe("label-thermal", () => {
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

    it("mengirim payload penuh saat diberikan, bukan { ids }", async () => {
        const module = await freshModule();
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ ok: true, text: TEXT }),
        });
        vi.stubGlobal("fetch", fetchMock);

        await module.printLabelThermal(
            {
                url: "/inbound/cetak-label/uji-cetak/tsp",
                // Uji cetak tidak punya job: yang dikirim template + salinan.
                payload: { template: "3x2", copies: 2 },
                ids: [],
                onFailed: vi.fn(),
            },
            vi.fn().mockResolvedValue(),
        );

        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({
            template: "3x2",
            copies: 2,
        });
    });

    it("tombol bercatatan data-payload mengirim payloadnya ke server", async () => {
        const module = await freshModule();
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ ok: true, text: TEXT }),
        });
        vi.stubGlobal("fetch", fetchMock);

        document.getElementById("root").innerHTML =
            '<button data-thermal-label' +
            ' data-url="/inbound/cetak-label/uji-cetak/tsp"' +
            ' data-payload=\'{"template":"3x2","copies":2}\'>' +
            "</button>";

        module.initLabelThermal();
        document.querySelector("[data-thermal-label]").click();

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({
            template: "3x2",
            copies: 2,
        });
    });

    it("data-payload yang rusak jatuh ke { ids }, bukan mematikan tombol", async () => {
        const module = await freshModule();
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ ok: true, text: TEXT }),
        });
        vi.stubGlobal("fetch", fetchMock);

        document.getElementById("root").innerHTML =
            '<button data-thermal-label data-url="/x"' +
            ' data-ids="[5]" data-payload="bukan-json"></button>';

        module.initLabelThermal();
        document.querySelector("[data-thermal-label]").click();

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ ids: [5] });
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

    it("tidak memaksa cetak browser saat pengguna menutup dialog pemilihan", async () => {
        const module = await freshModule();
        vi.stubGlobal("fetch", vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ ok: true, text: TEXT }),
        }));

        const onFailed = vi.fn();

        await module.printLabelThermal(
            { url: "/x", ids: [1], onFailed },
            vi
                .fn()
                .mockRejectedValue(
                    new DOMException(
                        "No Bluetooth devices chosen.",
                        "NotFoundError",
                    ),
                ),
        );

        // Menutup dialog pemilihan bukan permintaan cetak: dialog cetak
        // browser yang muncul sendiri justru mengganggu.
        expect(onFailed).not.toHaveBeenCalled();
        expect(window.alert).toHaveBeenCalledWith("Dibatalkan.");
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

describe("sendTsplText", () => {
    afterEach(() => {
        delete window.navigator.usb;
    });

    it("meminta perangkat dengan filters wajib supaya dialog sempat terbuka", async () => {
        const module = await freshModule();
        const requestDevice = vi.fn(async () => {
            // Pengguna menutup dialog: yang diuji hanya argumennya.
            throw new DOMException("No device selected.", "NotFoundError");
        });
        Object.defineProperty(window.navigator, "usb", {
            configurable: true,
            value: { requestDevice },
        });

        await expect(module.sendTsplText(TEXT)).rejects.toThrow();

        // Spesifikasi WebUSB mewajibkan `filters` walau `acceptAllDevices`
        // true; tanpanya Chrome melempar TypeError sebelum dialog muncul --
        // bug yang membuat jalur USB mustahil dipakai.
        expect(requestDevice).toHaveBeenCalledWith({
            filters: [],
            acceptAllDevices: true,
        });
    });

    it("menolak saat peramban tidak mendukung WebUSB", async () => {
        const module = await freshModule();

        await expect(module.sendTsplText(TEXT)).rejects.toThrow(
            /tidak mendukung WebUSB/,
        );
    });
});

describe("sendTsplSerial", () => {
    function writeableAiPort(acc) {
        return {
            open: vi.fn(async () => {}),
            close: vi.fn(async () => {}),
            writable: {
                getWriter: () => ({
                    write: async (bytes) => {
                        acc.push(new TextDecoder().decode(bytes));
                    },
                    releaseLock: vi.fn(),
                }),
            },
        };
    }

    function stubSerial(port) {
        Object.defineProperty(window.navigator, "serial", {
            configurable: true,
            value: { requestPort: vi.fn(async () => port) },
        });
    }

    afterEach(() => {
        delete window.navigator.serial;
    });

    it("membuka port SPP dan menulis seluruh teks TSPL", async () => {
        const module = await freshModule();
        const received = [];
        const port = writeableAiPort(received);

        stubSerial(port);
        await module.sendTsplSerial(TEXT);

        expect(port.open).toHaveBeenCalledWith({ baudRate: 9600 });
        expect(received.join("")).toBe(TEXT);
        expect(port.close).toHaveBeenCalledTimes(1);
    });

    it("menolak saat peramban tidak mendukung Web Serial", async () => {
        const module = await freshModule();

        await expect(module.sendTsplSerial(TEXT)).rejects.toThrow(
            /tidak mendukung Web Serial/,
        );
    });

    it("menolak dengan pesan tindakan saat port tercatat tapi belum tersambung", async () => {
        const module = await freshModule();
        const port = writeableAiPort([]);
        // Chrome 130+: port nirkabel masih tampil di picker walau perangkat
        // hanya paired / sudah diputus dari panel sistem.
        port.connected = false;

        stubSerial(port);

        await expect(module.sendTsplSerial(TEXT)).rejects.toThrow(
            /belum tersambung/,
        );
        expect(port.open).not.toHaveBeenCalled();
    });

    it("menerjemahkan penolakan OS saat membuka port jadi langkah yang bisa ditindaklanjuti", async () => {
        const module = await freshModule();
        const port = writeableAiPort([]);
        port.open = vi.fn(async () => {
            throw new DOMException("Failed to open serial port.", "NetworkError");
        });

        stubSerial(port);

        const error = await module.sendTsplSerial(TEXT).catch((caught) => caught);

        // Teks mentah Chrome tidak menyebut satu pun penyebab yang bisa
        // diperiksa operator; pesannya harus menunjuk tindakan berikutnya.
        expect(error.message).toMatch(/aplikasi lain/);
        expect(error.message).toMatch(/perbarui Chrome/);
        expect(port.close).not.toHaveBeenCalled();
    });

    it("menutup port hanya kalau berhasil terbuka", async () => {
        const module = await freshModule();
        const received = [];
        const port = writeableAiPort(received);
        port.open = vi.fn(async () => {
            throw new Error("Port dipakai alat lain.");
        });

        stubSerial(port);

        await expect(module.sendTsplSerial(TEXT)).rejects.toThrow(
            "Port dipakai alat lain.",
        );
        expect(port.close).not.toHaveBeenCalled();
    });
});

describe("sendTsplBle", () => {
    const NUS_SERVICE = "6e400001-b5a3-f393-e0a9-e50e24dcca9e";

    function characteristic(acc) {
        return {
            properties: { write: false, writeWithoutResponse: true },
            writeValueWithoutResponse: vi.fn(async (bytes) => {
                acc.push(new TextDecoder().decode(bytes));
            }),
            writeValue: vi.fn(),
        };
    }

    /**
     * Perangkat BLE dengan layanan `serviceId` yang punya karakteristik tulis.
     * Layanan lain ditolak, persis perilaku GATT asli.
     */
    function bleDevice(acc, serviceId) {
        const tx = characteristic(acc);
        const server = {
            connected: true,
            getPrimaryService: vi.fn(async (id) => {
                if (id !== serviceId) {
                    throw new Error(`Layanan ${id} tidak ada.`);
                }
                return { getCharacteristics: vi.fn(async () => [tx]) };
            }),
            getPrimaryServices: vi.fn(async () => []),
        };
        const gatt = {
            connect: vi.fn(async () => server),
            disconnect: vi.fn(async () => {
                server.connected = false;
            }),
        };
        return { gatt, server, tx };
    }

    function stubBluetooth(device) {
        Object.defineProperty(window.navigator, "bluetooth", {
            configurable: true,
            value: { requestDevice: vi.fn(async () => device) },
        });
    }

    afterEach(() => {
        delete window.navigator.bluetooth;
    });

    it("mengirim seluruh teks TSPL per chunk aman lalu memutus koneksi", async () => {
        const module = await freshModule();
        const received = [];
        const device = bleDevice(received, NUS_SERVICE);

        stubBluetooth(device);
        await module.sendTsplBle(TEXT);

        expect(received.join("")).toBe(TEXT);
        // Chunk 20 byte: MTU terkecil yang selalu aman tanpa negosiasi MTU.
        expect(received.every((chunk) => chunk.length <= 20)).toBe(true);
        // Filter nama, bukan semua perangkat: daftar dialog hanya berisi
        // printer ini, persis tujuan filter layanan di `thermal.js`.
        expect(device.gatt.connect).toHaveBeenCalledTimes(1);
        expect(window.navigator.bluetooth.requestDevice).toHaveBeenCalledWith({
            filters: [{ namePrefix: "BP-TD110BT" }],
            optionalServices: expect.arrayContaining([NUS_SERVICE]),
        });
        expect(device.gatt.disconnect).toHaveBeenCalledTimes(1);
    });

    it("memakai layanan apa pun yang terbuka saat tidak ada di daftar", async () => {
        const module = await freshModule();
        const received = [];
        // Banyak printer menyebut UUID layanan hanya setelah koneksi terbuka,
        // jadi `getPrimaryService` pada daftar gagal semua dan pencarian
        // jatuh ke daftar layanan yang benar-benar terbuka.
        const tx = characteristic(received);
        const server = {
            connected: true,
            getPrimaryService: vi.fn(async () => {
                throw new Error("Tidak ada di daftar.");
            }),
            getPrimaryServices: vi.fn(async () => [
                { getCharacteristics: vi.fn(async () => [tx]) },
            ]),
        };
        const device = {
            gatt: {
                connect: vi.fn(async () => server),
                disconnect: vi.fn(async () => {
                    server.connected = false;
                }),
            },
        };

        stubBluetooth(device);
        await module.sendTsplBle(TEXT);

        expect(received.join("")).toBe(TEXT);
        expect(server.getPrimaryServices).toHaveBeenCalledTimes(1);
    });

    it("menolak saat peramban tidak mendukung Web Bluetooth", async () => {
        const module = await freshModule();

        await expect(module.sendTsplBle(TEXT)).rejects.toThrow(
            /tidak mendukung Web Bluetooth/,
        );
    });
});

describe("transport bawaan", () => {
    afterEach(() => {
        delete window.navigator.bluetooth;
    });

    /**
     * Tanpa transport yang disuntik, `printLabelThermal` harus membuka dialog
     * Web Bluetooth -- satu-satunya jalur bawaan, bukan rantai yang menguji
     * semuanya. Pembatalan di tengah (`NotFoundError`) sekaligus membuktikan
     * jalurnya dan bahwa pembatalan tidak memanggil `onFailed`.
     */
    it("membuka dialog Web Bluetooth, dan pembatalan tidak memanggil onFailed", async () => {
        const module = await freshModule();
        vi.stubGlobal(
            "fetch",
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ ok: true, text: TEXT }),
            }),
        );

        const requestDevice = vi.fn(async () => {
            throw new DOMException(
                "No Bluetooth devices chosen.",
                "NotFoundError",
            );
        });
        Object.defineProperty(window.navigator, "bluetooth", {
            configurable: true,
            value: { requestDevice },
        });

        const onFailed = vi.fn();
        await module.printLabelThermal({ url: "/x", ids: [1], onFailed });

        expect(requestDevice).toHaveBeenCalledTimes(1);
        expect(onFailed).not.toHaveBeenCalled();
        expect(window.alert).toHaveBeenCalledWith("Dibatalkan.");
    });

    /**
     * Sebaliknya, kegagalan nyata tetap memanggil `onFailed` supaya halaman
     * jatuh ke `window.print()` dan cetakan tidak hilang diam-diam.
     */
    it("memanggil onFailed saat Web Bluetooth tidak tersedia", async () => {
        const module = await freshModule();
        vi.stubGlobal(
            "fetch",
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ ok: true, text: TEXT }),
            }),
        );

        const onFailed = vi.fn();
        await module.printLabelThermal({ url: "/x", ids: [1], onFailed });

        expect(onFailed).toHaveBeenCalledTimes(1);
        expect(window.alert).toHaveBeenCalledWith(
            expect.stringMatching(/tidak mendukung Web Bluetooth/),
        );
    });
});

describe("initLabelThermal", () => {
    beforeEach(() => {
        vi.stubGlobal(
            "fetch",
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ ok: true, text: TEXT }),
            }),
        );
    });

    function mountButton(extraAttribute = "") {
        document.getElementById("root").innerHTML =
            `<button data-thermal-label data-url="/x" data-ids="[9]"` +
            `${extraAttribute}></button>`;
    }

    it("tombol tanpa data-transport mengirim lewat Web Bluetooth", async () => {
        const module = await freshModule();
        const ble = vi.fn().mockResolvedValue();
        const usb = vi.fn().mockResolvedValue();

        mountButton();
        module.initLabelThermal(document, { ble, usb });
        document.querySelector("[data-thermal-label]").click();

        await vi.waitFor(() => expect(ble).toHaveBeenCalledWith(TEXT));
        expect(usb).not.toHaveBeenCalled();
    });

    it('tombol data-transport="usb" mengirim lewat WebUSB', async () => {
        const module = await freshModule();
        const ble = vi.fn().mockResolvedValue();
        const usb = vi.fn().mockResolvedValue();

        mountButton(' data-transport="usb"');
        module.initLabelThermal(document, { ble, usb });
        document.querySelector("[data-thermal-label]").click();

        await vi.waitFor(() => expect(usb).toHaveBeenCalledWith(TEXT));
        expect(ble).not.toHaveBeenCalled();
    });
});
