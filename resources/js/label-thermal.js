/**
 * Cetak label langsung ke printer TSPL lewat WebUSB.
 *
 * Server menyusun perintah TSPL (lihat `TspLabelJobBuilder`), halaman yang
 * memegang ujung kabel yang memilih ke mana perintah itu pergi -- jadi modul
 * ini sengaja hanya jembatan teks → printer, tanpa tahu apa isi label
 * (urutan, salinan, harga sudah ditentukan server di endpoint `/tsp`).
 *
 * PWM denyut yang sama dengan `thermal.js` (struk): transport dimasukkan
 * sebagai parameter fungsi, bagian "kirim" bisa diganti tanpa menyentuh
 * halaman/controller. Printer label BLE merchant memakai Bluetooth Classic
 * (SPP) yang tidak bisa dijamah Web Bluetooth, jadi jalur ini memakai USB
 * terlebih dulu; begitu QZ Tray/agent masuk, yang berubah hanya transport.
 */

const VENDOR_INTERFACE_CLASS = 0xff;
const CHUNK = 64;

function toast(message, tone = "info") {
    const store = window.Alpine?.store("toast");
    if (typeof store?.push === "function") {
        store.push(message, tone);
        return;
    }
    window.alert(message);
}

export function readCsrf() {
    return (
        window.csrfToken ??
        document.querySelector('meta[name="csrf-token"]')?.content ??
        ""
    );
}

/**
 * Kirim teks perintah TSPL ke printer lewat WebUSB.
 *
 * Tidak ada vendor ID tertentu karena printer label pasar memakai VID/PID
 * masing-masing; yang diminta adalah alat apa pun, lalu interface kelas
 * vendor (0xFF) dicari dan endpoint OUT-nya dipakai. Kegagalan memberitahu
 * pemanggil (yang lalu jatuh ke dialog cetak browser) -- alat yang memakai
 * driver vendor atau USB serial (CDC ACM) akan gagal di sini dan itu bukan
 * error `sendTsplText`, itu fakta dukungan WebUSB.
 */
export async function sendTsplText(text) {
    if (!("usb" in navigator)) {
        throw new Error(
            "Peramban ini tidak mendukung WebUSB. Pakai Chrome/Edge pada HTTPS.",
        );
    }

    const device = await navigator.usb.requestDevice({ acceptAllDevices: true });

    try {
        await device.open();

        const config = device.configuration ?? device.configurations[0];
        if (!config) {
            throw new Error("Alat tidak membuka konfigurasi.");
        }

        await device.selectConfiguration(config.configurationValue);

        const iface = config.interfaces.find(
            (entry) =>
                entry.interfaceClass === VENDOR_INTERFACE_CLASS ||
                entry.interfaceSubclass === VENDOR_INTERFACE_CLASS,
        );
        if (!iface) {
            throw new Error(
                "Alat tidak membuka interface vendor (kelas FF) yang bisa dipakai label.",
            );
        }

        await device.claimInterface(iface.interfaceNumber);

        const endpoints = iface.alternate?.endpoints ?? [];
        const out = endpoints.find((entry) => entry.direction === "out");
        if (!out) {
            throw new Error("Interface vendor tidak punya endpoint OUT.");
        }

        const bytes = new TextEncoder().encode(text);

        for (let i = 0; i < bytes.length; i += CHUNK) {
            await device.transferOut(out.endpointNumber, bytes.slice(i, i + CHUNK));
        }

        await device.close();
    } catch (error) {
        try {
            if (device.opened) {
                await device.close();
            }
        } catch {
            // Alat mungkin sudah tertutup atau tidak bisa lagi dijamah;
            // penyebab aslinya yang lebih penting.
        }

        throw error;
    }
}

/**
 * Minta perintah TSPL untuk `ids` lalu kirim ke printer.
 *
 * `url` berasal dari halaman, bukan ditulis di sini: modul ini tidak boleh
 * tahu rute aplikasi. `onFailed` dipanggil kalau perintah gagal disusun atau
 * tidak berhasil terkirim -- pemanggil biasanya jatuh ke `window.print()`,
 * jadi cetakan tidak pernah hilang diam-diam.
 *
 * `transport` dimasukkan supaya test memakai pengganti dan jalur QZ/agent
 * tinggal menggantikan ini nanti.
 */
export async function printLabelThermal(
    { url, ids, csrf = readCsrf(), onFailed } = {},
    transport = sendTsplText,
) {
    let body;
    try {
        const res = await fetch(url, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-CSRF-TOKEN": csrf,
            },
            body: JSON.stringify({ ids }),
        });

        body = await res.json().catch(() => ({}));

        if (!res.ok || !body.ok) {
            throw new Error(body.message || "Perintah TSPL gagal disusun.");
        }

        toast("Hubungkan perangkat ke printer…");
        await transport(body.text);
        toast("Cetakan terkirim ke printer label.");
    } catch (error) {
        toast(error?.message || "Cetak label thermal gagal.", "error");
        if (typeof onFailed === "function") {
            onFailed();
        }
    }
}

/**
 * Aktifkan semua tombol `[data-thermal-label]` pada halaman.
 *
 * Tombolnya ditulis server (lihat `label-print.blade.php`) dengan `data-url`
 * endpoint TSPL dan `data-ids` daftar job JSON. Halaman label sengaja tidak
 * memuat Alpine/bundle aplikasi, jadi inisialisasi dilakukan di sini.
 */
export function initLabelThermal(root = document) {
    root.querySelectorAll("[data-thermal-label]").forEach((button) => {
        let ids;
        try {
            ids = JSON.parse(button.dataset.ids || "[]");
        } catch {
            ids = [];
        }

        button.addEventListener("click", () => {
            printLabelThermal({
                url: button.dataset.url || "",
                ids,
                csrf: readCsrf(),
                onFailed: () => window.print(),
            });
        });
    });
}

document.addEventListener("DOMContentLoaded", () => initLabelThermal());

window.__labelThermalPrint = ({ url, ids, onFailed }) =>
    printLabelThermal({ url, ids, onFailed });