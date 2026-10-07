/**
 * Mencetak byte ESC/POS ke printer thermal lewat Web Bluetooth.
 *
 * Server tidak pernah menyentuh printer -- byte disusun di server (lihat
 * `App\Services\Print\Thermal\ReceiptRenderer`), halaman yang memegang ujung
 * kabel yang memilih ke mana byte itu pergi. Modul ini hanya jembatan Byte
 * → printer, dan tubuhnya sengaja kecil supaya gampang ditukar: kalau printer
 * pasar ternyata hanya mendukung Bluetooth Classic (QZ Tray), yang berubah
 * hanya bagian "kirim ke printer" di sini, bukan renderer atau halaman.
 *
 * Uji cetak thermal justru TIDAK minta byte ke server: strip uji bukan dokumen,
 * jadi tidak punya isi yang perlu konsisten dengan halaman. Strip itu disusun
 * di sini dengan perintah ESC/POS yang sama yang dipakai renderer.
 */

const PRINTER_SERVICES = [
    "000018f0-0000-1000-8000-00805f9b34fb", // POS service (printer BLE umum)
    "0000ffe0-0000-1000-8000-00805f9b34fb", // service "barang murah" umum
];

const TX_CHARACTERISTICS = [
    "00002af1-0000-1000-8000-00805f9b34fb",
    "0000ffe1-0000-1000-8000-00805f9b34fb",
];

/** Lebar tulis aman: kompatibel dengan MTU terkecil sekalipun. */
const CHUNK = 20;

function toast(message, tone = "info") {
    const store = window.Alpine?.store("toast");
    if (typeof store?.push === "function") {
        store.push(message, tone);
        return;
    }
    window.alert(message);
}

/**
 * Cari karakteristik tulis pada perangkat yang sudah terhubung.
 */
async function findWritableCharacteristic(device) {
    const server = await device.gatt.connect();

    let service = null;
    for (const id of PRINTER_SERVICES) {
        try {
            service = await server.getPrimaryService(id);
            break;
        } catch {
            // Bukan layanan ini; coba berikutnya.
        }
    }
    if (!service) {
        const services = await server.getPrimaryServices?.();
        throw new Error(
            "Printer tidak membuka layanan yang dikenali (18f0/ffe0).",
        );
    }

    let characteristic = null;
    for (const id of TX_CHARACTERISTICS) {
        try {
            characteristic = await service.getCharacteristic(id);
            break;
        } catch {
            // Bukan karakteristik ini; coba berikutnya.
        }
    }
    if (!characteristic) {
        const chars = await service.getCharacteristics();
        characteristic = chars.find(
            (c) => c.properties.write || c.properties.writeWithoutResponse,
        );
    }
    if (!characteristic) {
        throw new Error(
            "Printer tidak menyediakan alur tulis yang bisa dipakai.",
        );
    }

    return characteristic;
}

/**
 * Sambungkan (dengan dialog pairing peramban) dan kirim byte.
 */
async function sendBytes(bytes) {
    if (!("bluetooth" in navigator)) {
        throw new Error(
            "Peramban ini tidak mendukung Web Bluetooth. Pakai Chrome/Edge pada HTTPS.",
        );
    }

    const device = await navigator.bluetooth.requestDevice({
        filters: PRINTER_SERVICES.map((service) => ({ services: [service] })),
        optionalServices: PRINTER_SERVICES,
    });

    const characteristic = await findWritableCharacteristic(device);
    const withoutResponse = characteristic.properties.writeWithoutResponse;

    for (let i = 0; i < bytes.length; i += CHUNK) {
        const slice = bytes.slice(i, i + CHUNK);
        if (withoutResponse) {
            await characteristic.writeValueWithoutResponse(slice);
        } else {
            await characteristic.writeValue(slice);
        }
    }
}

/**
 * Panggil endpoint thermal halaman lalu kirim byte-nya ke printer.
 *
 * `url` berasal dari halaman, bukan ditulis di sini: modul ini tidak boleh
 * tahu rute aplikasi. `onFailed` dipanggil kalau byte tidak berhasil dibuat
 * atau tidak berhasil terkirim, supaya pemanggil bisa jatuh ke cetak browser.
 */
export async function printFromServer(url, { onFailed } = {}) {
    const res = await fetch(url, {
        method: "POST",
        headers: {
            "X-CSRF-TOKEN":
                window.csrfToken ??
                document.querySelector('meta[name="csrf-token"]')?.content ??
                "",
        },
    });

    const body = await res.json().catch(() => ({}));

    if (!res.ok || !body.ok) {
        const message = body.error || "Byte thermal gagal disusun.";
        toast(message, "error");
        if (typeof onFailed === "function") {
            onFailed();
        }
        return;
    }

    try {
        const bytes = Uint8Array.from(atob(body.bytesB64), (c) =>
            c.charCodeAt(0),
        );
        toast("Hubungkan perangkat ke printer…");
        await sendBytes(bytes);
        toast("Cetakan terkirim ke printer thermal.");
    } catch (error) {
        toast(error?.message || "Cetak thermal gagal.", "error");
        if (typeof onFailed === "function") {
            onFailed();
        }
    }
}

/**
 * Strips uji yang dipakai tombol "Uji Cetak Thermal".
 */
export function stripBytes() {
    const esc = 0x1b;
    const bytes = [esc, 0x40]; // ESC @ — sama seperti yang dipakai renderer.

    const pushText = (text) => {
        for (let i = 0; i < text.length; i += 1) {
            bytes.push(text.charCodeAt(i));
        }
        bytes.push(0x0a);
    };

    pushText("HOT WHEELS STORE");
    pushText("");
    pushText("Uji cetak thermal");
    pushText("Baris ini keluar dari printer.");
    pushText("Kalau teks miring atau terpotong, cek kanan-kiri.");
    pushText("");
    pushText("OK - cetak thermal siap.");

    bytes.push(esc, 0x64, 0x03); // feed 3 baris
    bytes.push(esc, 0x69); // cut

    return Uint8Array.from(bytes);
}

/**
 * Uji cetak: sambungkan lalu kirim strip uji.
 */
export async function testPrint() {
    try {
        toast("Uji cetak: hubungkan printer…");
        await sendBytes(stripBytes());
        toast("Strip uji terkirim. Cek gulungan printer.");
    } catch (error) {
        toast(error?.message || "Uji cetak gagal.", "error");
    }
}
window.__thermalPrint = (url, opts) => printFromServer(url, opts);
window.__thermalTest = () => testPrint();
