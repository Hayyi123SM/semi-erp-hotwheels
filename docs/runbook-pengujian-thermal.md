# Runbook: Pengujian Cetak Thermal

Panduan operasional untuk menguji alur cetak thermal dari pengembangan sampai
ke printer asli. Dua jalur: **struk** (ESC/POS + Web Bluetooth, bagian 1–9)
dan **label** (TSPL + WebUSB, bagian Runbook Label di bawah). Segala fakta
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
`TEXT`, `PRINT`) yang disusun server, dan jembatan ke printer memakai
**WebUSB** (bukan Web Bluetooth) karena printer label umumnya Bluetooth
Classic SPP yang tidak bisa dijamah Web Bluetooth.

## A. Cara kerja singkat

```
Halaman /inbound/cetak-label (pilih job → render)
  └─ POST /inbound/cetak-label/tsp  {ids}
        └─ server menyusun teks TSPL (TspLabelJobBuilder)
             └─ json {ok, text, sheets, total, paper, columns, rows}
                  └─ resources/js/label-thermal.js → WebUSB → printer
```

`text` sengaja dikirim mentah (bukan base64): TSPL murni teks ASCII tanpa
byte kontrol, jadi tidak ada alasan menyelinapkannya ke binary. `jobIds`
berasal dari tombol (`data-ids`), identik dengan job yang sedang tampil.

WebUSB hanya jalan di **Chromium di atas HTTPS** (sama seperti Web Bluetooth).
Kalau gagal (tanpa WebUSB, tanpa interface vendor kelas 0xFF, tanpa endpoint
OUT), `onFailed` dipanggil → `window.print()` → cetakan tidak pernah hilang.

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
3. Buka halaman hasil render. Kalau setting thermal aktif, muncul tombol
   **"Cetak N label thermal"** di samping tombol *Cetak N label* biasa.
4. Klik → Chrome memunculkan dialog WebUSB: pilih TD110BT (terhubung USB),
   izinkan. Toast *"Cetakan terkirim ke printer label."*.
5. Gagal apa pun → jatuh ke dialog cetak browser (fallback), jadi alur
   browser tidak pernah rusak.

Tombol thermal hanya muncul saat rooting punya `jobIds` dan `labelPrintMethod`
= `thermal`; halaman uji cetak (`testPrint`) tidak punya job → tetap browser.

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

# Endpoint label tsp, setting print method, rendering label
php -d memory_limit=-1 vendor/bin/phpunit \
  tests/Feature/LabelTspPrintTest.php \
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
