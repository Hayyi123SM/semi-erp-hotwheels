# Runbook: Pengujian Cetak Thermal

Panduan operasional untuk menguji alur cetak thermal dari pengembangan sampai
ke printer asli. Dua jalur: **struk** (ESC/POS + Web Bluetooth, bagian 1–9)
dan **label** (TSPL + Web Bluetooth/WebUSB, bagian Runbook Label di
bawah). Segala fakta
(nama rute, tombol, kunci setting, bentuk JSON) ditulis sesuai implementasi
saat ini, bukan rencana.

## 1. Cara kerja singkat

```
Halaman bukti terima (browser Chromium)
  └─ POST /inbound/consignment-in/{consignment}/bukti-terima/thermal
        └─ server menyusun byte ESC/POS (ReceiptRenderer, mike42)
             └─ json {ok, bytesB64, paper, width}
                  └─ resources/js/thermal.js → Web Bluetooth → printer
```

Server tidak pernah menyentuh printer. Byte disusun di server agar isi struk
selalu identik dengan yang tampil di halaman; `thermal.js` hanya jembatan byte
→ printer dan sengaja kecil supaya mudah ditukar (lihat Phase 2).

## 2. Syarat lingkungan (wajib)

Web Bluetooth **hanya jalan di Chromium (Chrome/Edge) di atas HTTPS**.
Tanpa HTTPS, `navigator.bluetooth` tidak ada dan tombol thermal langsung gagal
dengan pesan "Peramban ini tidak mendukung Web Bluetooth".

Opsi HTTPS lokal (pilih salah satu):

* **cloudflared (paling cepat)** -- URL publik dengan TLS asli tanpa konfigurasi:
  ```sh
  php artisan serve --port=8080 &
  cloudflared tunnel --url http://127.0.0.1:8080
  ```
  Buka URL `https://<random>.trycloudflare.com` di Chrome, lalu login.

* **ngrok / tunnel preview lainnya** -- sama saja: arahkan ke `http://127.0.0.1:8080`
  dan buka URL `https://...` yang diberikan.

* **Dipasang di server (VPS/Forge/Heroku)** -- pasang sertifikat TLS nyata
  (Let's Encrypt) untuk domain yang dipakai. Ini alur yang juga berlaku untuk
  produksi, dan satu-satunya yang dijamin stabil untuk Web Bluetooth.

Jangan mengandalkan flag lokal seperti `--unsafely-treat-insecure-origin-as-secure`
untuk uji yang sah: itu hanya meniru HTTPS di satu mesin dan tidak mewakili
perilaku produksi. Gunakan tunnel atau server dengan TLS asli.

Catatan: halaman Pengaturan juga harus diakses lewat origin HTTPS yang sama;
server tidak memvalidasi origin-nya, tapi `requestDevice` akan kembali ke dialog
pairing terhadap peramban, bukan ke halaman.

## 3. Persiapan setting

Origin yang dipakai browser = origin pengujian. Buka **Pengaturan → Perangkat**,
blok *Struk (bukti terima)*:

| Field | Nilai untuk uji |
| --- | --- |
| Ukuran kertas dokumen | `58 mm` atau `80 mm` (thermal). A4 tidak bisa dicetak thermal |
| Cara cetak | `Cetak Thermal` |

Kunci yang tersimpan (legacy lama tetap dibaca sebagai fallback sampai global
disimpan):

* `print.paper` → `58mm | 80mm | a4` (fallback: `receipt.paper`, `pos.receipt_paper`)
* `print.method` → `browser | thermal` (default `browser`)
* `print.post_commit_mode` → `auto_print | preview | go_to_labels`

Tombol **Uji Cetak Thermal** di halaman yang sama menyambungkan printer dan
mengirim *strip uji* (ESC/POS imaginasi, tanpa melibatkan server). Ini langkah
isolasi tercepat: jika strip keluar benar, masalah ada di penyusunan byte
dokumen, bukan di jembatan Bluetooth.

## 4. Alur uji end-to-end per dokumen

1. Siapkan titipan (consignment) lengkap dengan lot barang.
2. Tandai selesai / committed -- endpoint hanya melayani
   `Completed` dan `Committed` (selain itu `404`).
3. Buka halaman **Bukti Terima** dokumen tersebut.
4. Klik tombol **Cetak Thermal** (id `thermal-print`); kalau *Cara cetak* =
   `thermal`, halaman juga mencoba cetak thermal otomatis saat dibuka.
5. Chrome memunculkan dialog pairing: pilih printer, izinkan.
6. Klaim sukses = toast *"Cetakan terkirim ke printer thermal."*

Jika permintaan gagal di sisi server/peramban, `onFailed` dipanggil dan halaman
jatuh ke `window.print()` (cetak browser) -- jadi alur cetak browser tidak
pernah rusak oleh kegagalan thermal.

## 5. Perilaku endpoint thermal

`POST /inbound/consignment-in/{consignment}/bukti-terima/thermal`
(headers: `X-CSRF-TOKEN`, butuh login).

| Kondisi | Hasil |
| --- | --- |
| Status selain Completed/Committed | `404` |
| Kertas A4 (atau non-thermal) | `422` `{ok:false,error}` + tombol disabled |
| Renderer menolak | `422` `{ok:false,error:"Ukuran ... tidak bisa dicetak thermal."}` |
| Sukses | `200` `{ok:true, bytesB64, paper, width}` |

`width` = `58` atau `80` sesuai `escposColumnWidth()` (kolom teks default, bukan
mm). `bytesB64` di-decode `thermal.js` dan dikirim per 20 byte (aman untuk MTU
terkecil).

Error koneksi printer tidak pernah menyentuh server: banner `$thermalError`
(dari `session('thermal_print_error')`) hanya untuk kegagalan penyusunan byte.

## 6. Checklist printer yang kompatibel

Prasyarat printer, urut dari yang paling menjebak:

1. **Jenis cetak ESC/POS (receipt thermal).** Printer label (TSPL/EPL, misalnya
   TD110BT milik toko saat ini) **bukan** jalur ini -- ia mencetak label A6 dan
   tidak mengerti byte ESC/POS. Untuk TD110BT pakai jalur TSPL/WebUSB di
   bagian **"Runbook: Pengujian Cetak Label Thermal (TSPL)"** di bawah.
2. **Koneksi BLE (GATT), bukan Bluetooth Classic SPP.** Web Bluetooth hanya
   bicara BLE. Printer struk murah umumnya Classic SPP -- untuk mereka, lihat
   Phase 2 (QZ Tray). BLE yang dikenali harus membuka primary service
   `000018f0-...` atau `0000ffe0-...` dan karakteristik tulis
   `00002af1-...` / `0000ffe1-...`; kalau tidak ketemu, `thermal.js` mencari
   karakteristik tulis apa pun di service tersebut.
3. Pada kunci: nyalakan printer, pastikan tidak sedang terhubung HP lain
   (BLE hanya satu master), dan mode *discoverable/pairing* aktif sebelum
   dialog pairing dibuka.
4. Lebar rol cocok dengan setelan `print.paper` (58 mm→kolom 32, 80 mm→kolom 42;
   kirim lebar yang salah = teks terpotong/kelebihan).

Contoh yang dulu pernah dipakai arus jenis ini: Xprinter/Kassel 58/80 mm seri
USB+BT; selalu konfirmasi spec sheet menyebut *BLE* atau *Bluetooth 4.x BLE*.

## 7. Verifikasi otomatis yang relevan

```sh
# Penyusunan byte, setting kertas/method, endpoint thermal (58, 80, A4, draft, guest)
php -d memory_limit=-1 vendor/bin/phpunit \
  tests/Unit/Consignment/ReceiptRendererTest.php \
  tests/Feature/PrintSettingsTest.php \
  tests/Feature/ReceiptPrinterSettingsTest.php \
  tests/Feature/ConsignmentReceiptPrintTest.php

# Klien JS (thermal.js)
npx vitest run
```

Suites penuh: `php -d memory_limit=-1 vendor/bin/phpunit` dan `npm run build`.

## 8. Troubleshooting umum

| Gejala | Penyebab paling mungkin |
| --- | --- |
| "Peramban ini tidak mendukung Web Bluetooth" | Bukan Chromium, atau bukan HTTPS |
| Dialog pairing tidak menampilkan printer | Printer pakai Classic SPP, atau BLE-nya sedang dipakai perangkat lain / tidak discoverable |
| Toast "Printer tidak membuka layanan yang dikenali (18f0/ffe0)" | Service yang dibuka tidak cocok; cek spec BLE printer |
| Strip uji jelas, dokumen miring/terpotong | Lebar `print.paper` tidak cocok dengan rol |
| Tombol thermal disabled bertitle "Kertas A4 ..." | Kertas diset A4; ganti ke 58/80 mm di Pengaturan → Perangkat |
| Auto-print thermal tidak jalan, tapi tombol manual OK | Auto-print hanya aktif kalau *Cara cetak* = `thermal` |
| API 500 | Periksa `storage/logs/laravel.log`; laporkan stack trace |

## 9. Phase 2 (bila printer ternyata Classic SPP)

Kontrak yang dipakai sekarang (`printFromServer(url, {onFailed})` + byte dari
server) tidak berubah. Yang ditukar hanya bagian "kirim ke printer": ganti fungsi
`sendBytes()` di `resources/js/thermal.js` dengan panggilan ke QZ Tray
(`qz.websocket.connect(); qz.printers.find(); ...`) atau biarkan Web Bluetooth
untuk printer yang memang BLE. Renderer, endpoint, audite, dan halaman tidak
ikut berubah.

Jalur unduhan: printer ditemukan → verifikasi BLE vs SPP → putuskan BT atau QZ →
uji ulang dengan runbook ini.
---

# Runbook: Pengujian Cetak Label Thermal (TSPL)

Jalur cetak langsung untuk printer label **BP-TD110BT** (TSPL, 203 dpi,
media 100×150 mm, 48 stiker 15×15 mm / lembar). Berbeda dari struk: label
tidak memakai byte ESC/POS, tapi perintah teks TSPL (`SIZE`, `GAP`, `QRCODE`,
`TEXT`, `PRINT`) yang disusun server. Jembatan ke printer memakai **satu klik =
satu dialog**: tombol utama **Web Bluetooth (BLE)** dengan dialog yang
menyaring nama printer, tombol kedua **WebUSB** untuk printer yang tersambung
lewat kabel; `window.print()` hanya untuk kegagalan nyata, bukan untuk dialog
yang dibatalkan pengguna.

## A. Cara kerja singkat

```
Halaman /inbound/cetak-label (pilih job → render)
  └─ POST /inbound/cetak-label/tsp  {ids}
        └─ server menyusun teks TSPL (TspLabelJobBuilder)
             └─ json {ok, text, sheets, total, paper, columns, rows}
                  └─ resources/js/label-thermal.js
                        ├─ tombol utama  → Web Bluetooth (filter nama BP-TD110BT)
                        └─ "Cetak via USB" → WebUSB
                              → gagal nyata → window.print()
```

`text` sengaja dikirim mentah (bukan base64): TSPL murni teks ASCII tanpa
byte kontrol, jadi tidak ada alasan menyelinapkannya ke binary. `jobIds`
berasal dari tombol (`data-ids`), identik dengan job yang sedang tampil;
halaman uji cetak memakai `data-payload` (`template` + `copies`) karena tidak
punya job.

Kedua jalur membutuhkan **Chromium di atas HTTPS** (sama seperti Web
Bluetooth pada struk). Kegagalan nyata (layanan BLE tak dikenali, tanpa
interface vendor kelas 0xFF) memanggil `onFailed` → `window.print()` →
cetakan tidak pernah hilang. Dialog pemilihan perangkat yang **ditutup
pengguna** berarti "bukan sekarang": toast *"Dibatalkan."* muncul **tanpa**
membuka dialog cetak browser dan tanpa dialog lanjutan.

## B. Persiapan setting

**Pengaturan → Perangkat**, blok *Label*:

| Field | Nilai untuk uji |
| --- | --- |
| Cara cetak label | `Thermal` (default `Browser`) |

Kunci tersimpan: `label.print.method` → `browser | thermal`. Kalau form
lama tidak mengirim field ini, nilai yang tersimpan tidak disentuh (sama
seperti `max_print_width_mm`).

## C. Alur uji

1. Siapkan lot barang (stok lot asli atau lot imajiner), lalu pilih di
   halaman `Cetak Label`.
2. Set **Cara cetak label** = `Thermal`.
3. Buka halaman hasil render. Kalau setting thermal aktif, muncul dua tombol
   di samping tombol *Cetak N label* biasa: **"Cetak N label (Bluetooth)"**
   dan **"Cetak via USB"**.
4. Klik tombol utama → toast *"Pilih printer label di dialog Bluetooth…"*
   lalu dialog Web Bluetooth muncul **berisi BP-TD110BT saja** (filter nama).
   Pilih printernya dan izinkan.
5. Sukses = toast *"Cetakan terkirim ke printer label."*
6. Tutup dialog tanpa memilih → toast *"Dibatalkan."*, tidak ada dialog
   lanjutan dan `window.print()` tidak dibuka. Kegagalan nyata → `onFailed`
   → dialog cetak browser (fallback), jadi alur browser tidak pernah rusak.
   Tombol *Cetak via USB* memakai perintah yang sama lewat dialog WebUSB,
   untuk saat printer tersambung lewat kabel.

Tombol thermal di halaman render muncul saat rooting punya `jobIds` **dan**
`labelPrintMethod` = `thermal`. Halaman **uji cetak**
(`/inbound/cetak-label/uji-cetak`) juga punya kedua tombol —
"Cetak N label (Bluetooth)" dan "Cetak via USB" — tanpa syarat setting,
karena halaman itu memang alat kalibrasi dan dialog browser adalah salah satu
variabel yang sedang diukur. Tombolnya memakai `POST
/inbound/cetak-label/uji-cetak/tsp` (`template` + `copies`) dan tidak pernah
membuat job label atau menaikkan `labels_printed`.

## D. Perilaku endpoint

`POST /inbound/cetak-label/tsp` (JSON `{ids}`; headers `X-CSRF-TOKEN`,
`Content-Type: application/json`; butuh login).

| Kondisi | Hasil |
| --- | --- |
| `ids` berisi id yang tidak ada | `422` `{ok:false,message}` (`validation.exists`) |
| Total salinan > 600 (LabelPage batas) | `422` `{ok:false,message}` |
| Sukses | `200` `{ok:true, text, sheets, total, paper, columns, rows}` |

`paper` = `sheet`/`roll` sesuai mode kertas; untuk gulungan `columns`/`rows`
harus `1`. Item kosong dalam 48 lembar sheet diurutkan sesuai urutan pilihan;
urutan job mengikuti pilihan, bukan id.

## E. Verifikasi otomatis

```sh
# Renderer + job builder TSPL
php -d memory_limit=-1 vendor/bin/phpunit \
  tests/Unit/Label/TspLabelRendererTest.php \
  tests/Unit/Label/TspLabelJobBuilderTest.php

# Endpoint label tsp, uji cetak (jalur HTML + TSPL), setting print method,
# rendering label
php -d memory_limit=-1 vendor/bin/phpunit \
  tests/Feature/LabelTspPrintTest.php \
  tests/Feature/LabelTestPrintTest.php \
  tests/Feature/PrinterSettingsTest.php \
  tests/Feature/LabelRenderTest.php

# Klien JS (label-thermal.js)
npx vitest run resources/js/label-thermal.test.js
```

## F. Risiko hardware (belum terverifikasi di unit fisik)

* Apakah TD110BT terlihat/klaimable lewat `navigator.usb.requestDevice` dan
  membuka interface kelas **vendor (0xFF)** dengan endpoint OUT.
* Varian sintaks `QRCODE x,y,M,cell,A,0,"data"` pada firmware TD110BT; bila
  muncul diagonal/tidak terbaca, coba varian tingkat koreksi lain.
* Beberapa printer label TSPL menuntut perintah `SIZE` dalam satuan yang
  persis (dot vs mm); runbook ini memakai mm (`SIZE 100 mm,150 mm`).

Bagian "kirim ke printer" (`sendTsplText`) berdiri sendiri seperti
`sendBytes()` di jalur struk: kalau TD110BT ternyata lebih mudah lewat agent
(QZ), yang berubah hanya transport, bukan renderer/halaman.

## G. Bluetooth (Web Serial SPP) — TIDAK AKTIF di alur default

Fungsi `sendTsplSerial` masih ada di `label-thermal.js` tapi **tidak dipanggil
dari tombol mana pun**: rantai berurutan hanya membuat beberapa dialog muncul
berturut-turut untuk satu klik, dan `open()` port SPP ditolak macOS di unit
uji. Aktifkan kembali hanya kalau SPP terbukti di unit fisik.

TD110BT generik OEM ini memakai **Bluetooth Classic SPP** untuk jalur
serialnya -- `navigator.bluetooth` (Web Bluetooth) tidak akan pernah
menampilkannya, karena Web Bluetooth hanya bicara BLE. Jalur SPP browser-nya
lewat **Web Serial API**:

* Chrome/Edge **desktop** 117+; **bukan** Android/browser mobile.
* Device harus **dipairing dulu di OS** (macOS System Settings → Bluetooth,
  PIN pabrik biasanya `0000` atau `1234`). Chrome menampilkan kanal SPP yang
  sudah dipairing di `navigator.serial.requestPort()` tanpa perlu driver.
* `baudRate` pada `port.open()` (9600) diabaikan OS untuk port Bluetooth
  virtual.
* Chrome 130+ mengecek `port.connected` sebelum `open()`: kalau `false`
  (hanya *paired*, di luar jangkauan, atau sengaja diputus dari panel sistem)
  pengguna langsung mendapat pesan *"…belum tersambung"* tanpa `open()` yang
  pasti gagal.

## H. Bluetooth (Web Bluetooth BLE) — jalur utama

Jalur bawaan tombol utama memakai Web Bluetooth dengan pola yang sama seperti
`thermal.js` (struk), tapi memfilter **nama**, bukan layanan:

* `requestDevice({ filters: [{ namePrefix: "BP-TD110BT" }], optionalServices: [...] })`
  — dialog hanya menawarkan printer dengan nama berawalan `BP-TD110BT`.
  Kecocokan nama harfiah: kalau printer mengiklankan nama yang sedikit beda,
  dialog tampil **kosong** (bisa dicek di macOS System Settings → Bluetooth).
* Layanan yang dicoba berurutan: `18f0`, `ffe0`, `ff00`, `fff0`, Nordic UART
  (`6e400001-…`), lalu layanan apa pun yang terbuka setelah koneksi GATT.
* Tulisan per **20 byte** (MTU terkecil, aman tanpa negosiasi MTU), lalu
  koneksi diputus.

Catatan dual mode: sebagian printer hanya mengaktifkan **satu** mode pada satu
waktu. Kalau SPP sedang tersambung, iklan BLE bisa hilang (dan sebaliknya) —
kalau dialog BLE kosong, putuskan pairing SPP / matikan printer sebentar lalu
ulangi, dan pastikan spec sheet menyebut *BLE* atau *Bluetooth 4.x BLE*.

## I. Pintu masuk transport dan arti kegagalan

Transport dipilih tombol, bukan oleh rantai berurutan:

```
"Cetak N label (Bluetooth)" → Web Bluetooth (filter nama BP-TD110BT)
"Cetak via USB"             → WebUSB (filters: [], acceptAllDevices)
keduanya gagal nyata         → window.print()
```

| Yang terjadi di dialog | Arti |
| --- | --- |
| Dialog ditutup pengguna (`NotFoundError`) | Bukan kegagalan: toast *"Dibatalkan."*, tidak ada dialog lanjutan, `window.print()` **tidak** dipanggil |
| `port.open()` melempar `NetworkError` (jalur SPP, saat aktif) | OS menolak membuka port: biasanya port dipegang aplikasi lain, entri port duplikat (macOS), atau Chrome sudah usang → pesan toast menyebut langkah berikutnya |
| Gagal nyata (mis. layanan BLE tak dikenali, tanpa interface vendor 0xFF) | Toast berisi alasan, lalu `window.print()` |

Toast per langkah: *"Pilih printer label di dialog Bluetooth…"* sebelum dialog
BLE, *"Pilih printer label di dialog USB…"* sebelum dialog WebUSB, sehingga
kegagalan bisa ditelusuri ke jalurnya.

Troubleshooting dua kegagalan yang paling sering muncul:

| Gejala | Penyebab / tindakan |
| --- | --- |
| `Failed to read the 'filters' property from 'USBDeviceRequestOptions'` | Bug lama: `requestDevice` dipanggil tanpa `filters` wajib. Sudah diperbaiki (`{ filters: [], acceptAllDevices: true }`); kalau masih muncul, bundle JS belum dibangun ulang (`npm run build`) |
| `Failed to execute 'open' on 'SerialPort': Failed to open serial port` | OS menolak (jalur SPP, saat aktif): tutup terminal/monitor serial/aplikasi printer, pastikan status Bluetooth **Connected** (bukan sekadar Paired), pilih entri port lain kalau muncul dua kali, dan perbarui Chrome (bug `SerialSplitDtrAndRts` diperbaiki di 139.0.7258.128) |
| Toast *"Printer terdaftar di pilihan port tapi belum tersambung"* | `port.connected === false`: sambungkan lagi perangkat dari panel sistem, lalu ulangi |
| Dialog BLE tampil **kosong** | Nama yang diiklankan tidak berawalan `BP-TD110BT` (cocokkan di pengaturan Bluetooth sistem), BLE sedang dipakai perangkat lain, atau sedang tersambung lewat SPP (lihat catatan dual mode) |
| Dialog BLE tidak menampilkan printer | Printer bukan BLE, BLE-nya dipakai perangkat lain, atau sedang tersambung lewat SPP (lihat catatan dual mode) |

Catatan firmware: sebagian printer TSPL-SPP memakai frame ACK `7E 01 7E` per
job (labelife menyebutnya auto-negotiated dan bisa di-drop). Verifikasi di
unit fisik: kirim satu job via SPP dan pastikan hasil cetak benar tanpa perlu
membaca balasan ACK.

Uji terisolasi Bluetooth (tanpa server): HP pendamping (Android/aplikasi
cetak label) dicoba dulu untuk memastikan printer memang SPP dan PIN-nya,
baru lanjut ke Web Serial di Chrome desktop.
