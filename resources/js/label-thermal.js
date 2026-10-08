/**
 * Cetak label langsung ke printer TSPL lewat Web Bluetooth (jalur utama) atau
 * WebUSB (jalur sekunder).
 *
 * Server menyusun perintah TSPL (lihat `TspLabelJobBuilder`), halaman yang
 * memegang ujung kabel yang memilih ke mana perintah itu pergi -- jadi modul
 * ini sengaja hanya jembatan teks → printer, tanpa tahu apa isi label
 * (urutan, salinan, harga sudah ditentukan server di endpoint `/tsp`).
 *
 * Satu klik tombol = satu dialog, seperti halaman struk POS (`thermal.js`).
 * Transport dipilih halaman lewat atribut tombol, bukan lewat rantai yang
 * mencoba semua jalur:
 *   - `data-thermal-label` tanpa `data-transport` -- Web Bluetooth (default).
 *     Dialognya menyaring nama printer `BP-TD110BT`, jadi yang tampil hanya
 *     printer, bukan seluruh perangkat BLE di sekitar.
 *   - `data-transport="usb"` -- WebUSB, untuk saat printer tersambung lewat
 *     kabel dan Bluetooth tidak relevan.
 *
 * Pembatalan dibedakan dari kegagalan: menutup dialog pemilihan
 * (`isPickerCancel`) hanya menampilkan "Dibatalkan." -- cetak browser tidak
 * dipaksa terbuka, karena orang yang menutup dialog tidak sedang meminta
 * cetak. Kegagalan nyata memanggil `onFailed`, sehingga halaman bisa jatuh ke
 * `window.print()` dan cetakan tidak pernah hilang diam-diam.
 *
 * Web Serial (Bluetooth Classic SPP) masih ada sebagai `sendTsplSerial` tapi
 * tidak dipanggil dari jalur mana pun: unit di lapangan ditolak OS saat
 * membuka portnya, dan rantai berurutan hanya membuat beberapa dialog muncul
 * berturut-turut untuk satu klik. Aktifkan kembali kalau SPP terbukti.
 */

const VENDOR_INTERFACE_CLASS = 0xff;
const CHUNK = 64;
const SERIAL_BAUD = 9600;
const SERIAL_CHUNK = 512;

/** Lebar tulis BLE aman: kompatibel dengan MTU terkecil sekalipun. */
const BLE_CHUNK = 20;

/**
 * Layanan BLE yang umum dipakai printer label/struk.
 *
 * Daftar ini dua kali dipakai: sebagai `optionalServices` (izin membuka
 * layanan) dan sebagai urutan percobaan saat mencari karakteristik tulis.
 */
const BLE_SERVICES = [
    "000018f0-0000-1000-8000-00805f9b34fb", // POS service (printer BLE umum)
    "0000ffe0-0000-1000-8000-00805f9b34fb", // service "barang murah" umum
    "0000ff00-0000-1000-8000-00805f9b34fb", // service label BLE umum
    "0000fff0-0000-1000-8000-00805f9b34fb", // service label BLE umum (varian)
    "6e400001-b5a3-f393-e0a9-e50e24dcca9e", // Nordic UART (NUS)
];

/**
 * Nama Bluetooth yang diiklankan printer label (BP-TD110BT).
 *
 * Dipakai sebagai `namePrefix` filter dialog Web Bluetooth supaya daftarnya
 * hanya berisi printer ini, persis tujuan filter layanan di `thermal.js`.
 * Kalau dialog tampil kosong, yang pertama dicocokkan adalah nama ini dengan
 * yang tercatat di pengaturan Bluetooth sistem -- kecocokannya harfiah, jadi
 * nama yang berbeda sedikit saja membuat printer tidak muncul.
 */
const BLE_NAME_PREFIX = "BP-TD110BT";

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
 * Apakah penolakan ini berarti pengguna menutup dialog pemilihan perangkat?
 *
 * Chrome memakai `NotFoundError` (WebUSB "No device selected", Web Bluetooth
 * "No Bluetooth devices chosen", Web Serial "No port selected") dan beberapa
 * versi memakai `CancelError`. Membedakannya dari kegagalan nyata penting:
 * menutup dialog berarti "bukan sekarang", bukan "printer rusak" -- jadi cetak
 * browser tidak perlu dipaksa terbuka setelah pembatalan.
 */
export function isPickerCancel(error) {
    if (!error) {
        return false;
    }
    if (error.name === "NotFoundError" || error.name === "CancelError") {
        return true;
    }
    return /no (device|port|bluetooth device)s? (selected|chosen)/i.test(
        error.message ?? "",
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

    toast("Pilih printer label di dialog Bluetooth…");

    // `filters` wajib menurut spesifikasi WebUSB walau `acceptAllDevices` true
    // -- tanpanya Chrome melempar TypeError "Required member is undefined"
    // sebelum dialog pemilihan sempat terbuka. Daftar kosong + acceptAll
    // berarti semua perangkat USB ditawarkan, karena printer label memakai
    // VID/PID masing-masing.
    const device = await navigator.usb.requestDevice({
        filters: [],
        acceptAllDevices: true,
    });

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
 * Kirim teks perintah TSPL ke printer lewat Web Serial (Bluetooth Classic SPP).
 *
 * TIDAK AKTIF di alur default (lihat kepala berkas): tombol cetak label hanya
 * memanggil Web Bluetooth dan WebUSB. Fungsi disimpan utuh karena printer dual
 * mode tetap bisa lewat SPP kalau jalur ini terbukti di unit yang benar.
 *
 * Printer label pasar berbahasa TSPL memakai Bluetooth Classic (SPP/RFCOMM),
 * bukan BLE -- jadi Web Bluetooth tidak bisa menyentuhnya. Setelah device
 * dipairing di OS (macOS System Settings → Bluetooth, PIN pabrik biasanya
 * `0000`/`1234`), kanal SPP muncul di `navigator.serial.requestPort()`
 * (Chrome 117+ desktop). `baudRate` diabaikan OS untuk port Bluetooth virtual.
 *
 * Yang gagal di sini hampir selalu OS, bukan perintah: port sudah tercatat di
 * picker tapi macOS menolak membukanya. Kegagalan itu diterjemahkan ke pesan
 * yang bisa ditindaklanjuti, karena teks mentah Chrome ("Failed to open serial
 * port") tidak menyebut satu pun penyebab yang bisa diperiksa operator.
 */
export async function sendTsplSerial(text) {
    if (!("serial" in navigator)) {
        throw new Error(
            "Peramban ini tidak mendukung Web Serial (Bluetooth SPP). Pakai Chrome/Edge 117+ di desktop pada HTTPS.",
        );
    }

    const port = await navigator.serial.requestPort();

    // Chrome 130+: `false` berarti perangkat nirkabel terpasang di daftar tapi
    // tidak tersambung secara logis (sekadar paired, di luar jangkauan, atau
    // sengaja diputus dari panel sistem). `open()` pada port seperti ini pasti
    // gagal dengan NetworkError yang tidak menjelaskan apa-apa, jadi
    // diperiksa dulu supaya pesannya bisa berisi tindakan yang benar.
    if (port.connected === false) {
        throw new Error(
            "Printer terdaftar di pilihan port tapi belum tersambung. Nyalakan/nyambungkan lagi printer di pengaturan Bluetooth sistem, lalu ulangi.",
        );
    }

    try {
        await port.open({ baudRate: SERIAL_BAUD });
    } catch (error) {
        if (error?.name === "NetworkError") {
            throw new Error(
                "Port Bluetooth gagal dibuka oleh sistem. Biasanya port masih dipegang aplikasi lain (terminal/monitor serial/aplikasi printer), entri port duplikat di sistem, atau Chrome sudah usang. Tutup aplikasi lain, pilih entri port yang berbeda kalau ada, perbarui Chrome, lalu ulangi.",
            );
        }
        throw error;
    }

    try {
        const bytes = new TextEncoder().encode(text);
        const writer = port.writable.getWriter();

        try {
            for (let i = 0; i < bytes.length; i += SERIAL_CHUNK) {
                await writer.write(bytes.slice(i, i + SERIAL_CHUNK));
            }
        } finally {
            writer.releaseLock();
        }
    } finally {
        try {
            await port.close();
        } catch {
            // Port mungkin sudah tertutup OS (device dicabut); penyebab
            // aslinya yang lebih penting.
        }
    }
}

/**
 * Cari karakteristik tulis pada printer BLE yang sudah terhubung GATT.
 *
 * Urutannya: layanan yang dikenali dulu (daftar `BLE_SERVICES`), baru layanan
 * apa pun yang berhasil dibuka aplikasi ini. Banyak printer murah tidak
 * menyebut UUID layanan di data iklannya, jadi layanan baru terlihat setelah
 * koneksi terbuka.
 */
async function findBleWritableCharacteristic(server) {
    for (const id of BLE_SERVICES) {
        try {
            const service = await server.getPrimaryService(id);
            const characteristic = await writableOn(service);
            if (characteristic) {
                return characteristic;
            }
        } catch {
            // Bukan layanan ini (atau tidak diizinkan); coba berikutnya.
        }
    }

    // Layanan yang tidak ada di daftar tetap boleh dicoba: izinnya datang dari
    // `optionalServices`, dan banyak printer menyebut UUID-nya hanya setelah
    // koneksi terbuka.
    const services =
        typeof server.getPrimaryServices === "function"
            ? await server.getPrimaryServices().catch(() => [])
            : [];
    for (const service of services) {
        const characteristic = await writableOn(service).catch(() => null);
        if (characteristic) {
            return characteristic;
        }
    }

    throw new Error(
        "Printer BLE tidak membuka layanan tulis yang dikenali (18f0/ffe0/ff00/fff0/NUS). Cek mode Bluetooth printer di spec sheet-nya.",
    );
}

/**
 * Satu karakteristik yang bisa ditulis pada sebuah layanan, atau null.
 */
async function writableOn(service) {
    const characteristics = await service.getCharacteristics();
    return (
        characteristics.find(
            (entry) => entry.properties.write || entry.properties.writeWithoutResponse,
        ) ?? null
    );
}

/**
 * Kirim teks perintah TSPL ke printer lewat Web Bluetooth (BLE) -- jalur
 * utama tombol cetak label.
 *
 * Satu klik = satu dialog, sama seperti halaman struk POS (`thermal.js`).
 * Dialognya menyaring `BLE_NAME_PREFIX` supaya hanya printer yang tampil:
 * `acceptAllDevices` memang tidak pernah kosong, tapi memaksa operator
 * memilih di antara mouse, jam, dan headphone -- sedangkan di sini pilihan
 * yang benar cuma satu.
 *
 * `optionalServices` tetap `BLE_SERVICES` (layanan yang boleh dibuka setelah
 * perangkat dipilih). Banyak printer murah tidak menyebut UUID layanan di
 * data iklannya, jadi layanan baru terlihat setelah koneksi GATT terjalin.
 *
 * Satu-satunya kelemahan filter nama: kalau printer mengiklankan nama yang
 * berbeda dari `BLE_NAME_PREFIX`, dialog tampil kosong. Nama yang benar
 * tercatat di pengaturan Bluetooth sistem, bukan di sini.
 */
export async function sendTsplBle(text) {
    if (!("bluetooth" in navigator)) {
        throw new Error(
            "Peramban ini tidak mendukung Web Bluetooth. Pakai Chrome/Edge pada HTTPS.",
        );
    }

    toast("Pilih printer label di dialog Bluetooth…");

    const device = await navigator.bluetooth.requestDevice({
        filters: [{ namePrefix: BLE_NAME_PREFIX }],
        optionalServices: BLE_SERVICES,
    });

    let server = null;
    try {
        server = await device.gatt.connect();
        const characteristic = await findBleWritableCharacteristic(server);
        const withoutResponse = characteristic.properties.writeWithoutResponse;

        const bytes = new TextEncoder().encode(text);
        for (let i = 0; i < bytes.length; i += BLE_CHUNK) {
            const slice = bytes.slice(i, i + BLE_CHUNK);
            if (withoutResponse) {
                await characteristic.writeValueWithoutResponse(slice);
            } else {
                await characteristic.writeValue(slice);
            }
        }
    } finally {
        if (server?.connected) {
            try {
                await device.gatt.disconnect();
            } catch {
                // Koneksi mungkin sudah putus sendiri (perangkat keluar
                // jangkauan); tulisan yang tadi sudah terkirim tetap sah.
            }
        }
    }
}

/**
 * Minta perintah TSPL lalu kirim ke printer.
 *
 * `url` berasal dari halaman, bukan ditulis di sini: modul ini tidak boleh
 * tahu rute aplikasi. `onFailed` dipanggil kalau perintah gagal disusun atau
 * tidak berhasil terkirim -- pemanggil biasanya jatuh ke `window.print()`,
 * jadi cetakan tidak pernah hilang diam-diam. Satu pengecualian: dialog
 * pemilihan perangkat yang ditutup pengguna (`isPickerCancel`) hanya
 * menampilkan "Dibatalkan.", tanpa `onFailed`.
 *
 * Dua bentuk badan request, dipilih oleh halaman:
 *   - `{ ids }`      -- jalur antrean label: job, urutan, dan salinan sudah
 *                       dipegang server lewat `label_print_jobs`.
 *   - `payload` apapun -- jalur tanpa job (uji cetak), yang mengirim
 *                       `template` + `copies` karena uji cetak sengaja tidak
 *                       pernah membuat job.
 *
 * `transport` dimasukkan supaya test memakai pengganti dan jalur QZ/agent
 * tinggal menggantikan ini nanti. Bawaannya Web Bluetooth; tombol bertanda
 * `data-transport="usb"` menyuntik `sendTsplText` lewat `initLabelThermal`.
 *
 * Alur persis POS: tombol diklik → dialog pemilihan perangkat dibuka langsung
 * oleh transport. Untuk menjaga urutan itu, toast penjelas "Pilih printer…"
 * dipindahkan ke masing-masing transport.
 */
export async function printLabelThermal(
    { url, ids, payload, csrf = readCsrf(), onFailed } = {},
    transport = sendTsplBle,
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
            body: JSON.stringify(payload ?? { ids }),
        });

        body = await res.json().catch(() => ({}));

        if (!res.ok || !body.ok) {
            throw new Error(body.message || "Perintah TSPL gagal disusun.");
        }

        await transport(body.text);
        toast("Cetakan terkirim ke printer label.");
    } catch (error) {
        // Pengguna menutup dialog pemilihan perangkat: bukan kegagalan, jadi
        // tidak ada alasan memunculkan dialog cetak browser yang tidak diminta.
        if (isPickerCancel(error)) {
            toast("Dibatalkan.");
            return;
        }
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
 * endpoint TSPL dan salah satu dari:
 *   - `data-ids`    daftar job JSON (antrean label), atau
 *   - `data-payload` badan request alternatif (uji cetak: `template` +
 *     `copies`, karena uji cetak tidak punya job untuk diminta).
 *
 * Transport dipilih per tombol lewat `data-transport`: `"usb"` memakai
 * WebUSB, selain itu (termasuk ketiadaannya) memakai Web Bluetooth. Keduanya
 * bisa disuntik lewat `transports` supaya test tidak menyentuh API peramban
 * yang asli.
 *
 * Halaman label sengaja tidak memuat Alpine/bundle aplikasi, jadi
 * inisialisasi dilakukan di sini.
 */
export function initLabelThermal(root = document, transports = {}) {
    root.querySelectorAll("[data-thermal-label]").forEach((button) => {
        let ids;
        try {
            ids = JSON.parse(button.dataset.ids || "[]");
        } catch {
            ids = [];
        }

        let payload = null;
        if (button.dataset.payload) {
            try {
                payload = JSON.parse(button.dataset.payload);
            } catch {
                // Atribut rusak bukan alasan untuk mencetak apa pun: jatuh ke
                // `{ ids }` yang paling tidak masih meminta pekerjaan yang
                // benar ke server.
                payload = null;
            }
        }

        const transport =
            button.dataset.transport === "usb"
                ? transports.usb ?? sendTsplText
                : transports.ble ?? sendTsplBle;

        button.addEventListener("click", () => {
            printLabelThermal(
                {
                    url: button.dataset.url || "",
                    ids,
                    payload: payload ?? undefined,
                    csrf: readCsrf(),
                    onFailed: () => window.print(),
                },
                transport,
            );
        });
    });
}

document.addEventListener("DOMContentLoaded", () => initLabelThermal());

window.__labelThermalPrint = ({ url, ids, payload, onFailed }) =>
    printLabelThermal({ url, ids, payload, onFailed });