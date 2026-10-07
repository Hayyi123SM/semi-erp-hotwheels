<?php

use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\Auth\PinController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Master\ConsignorController;
use App\Http\Controllers\Master\ImportController;
use App\Http\Controllers\Master\ProductController;
use App\Http\Controllers\Master\ProductSeriesController;
use App\Http\Controllers\Master\RackController;
use App\Http\Controllers\Pages\InboundController;
use App\Http\Controllers\Pages\InventoryController;
use App\Http\Controllers\Pages\OpnameController;
use App\Http\Controllers\Pages\PosController;
use App\Http\Controllers\Pages\ReportController;
use App\Http\Controllers\Pages\RtvController;
use App\Http\Controllers\Pages\SettingController;
use App\Http\Controllers\Pos\ProductLookupController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// Pengalihan ke dashboard memakai `Route::redirect`, bukan closure. Route
// berbentuk closure tidak bisa diserialisasi sehingga `route:cache` — yang
// dipasang entrypoint container produksi — akan gagal.
Route::redirect('/', '/dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // ===== 1. Master Data =====
    Route::get('/master/penitip', [ConsignorController::class, 'index'])->name('master.penitip');
    Route::get('/master/penitip/create', [ConsignorController::class, 'create'])->name('master.penitip.create');
    Route::post('/master/penitip', [ConsignorController::class, 'store'])->name('master.penitip.store');
    Route::get('/master/penitip/{consignor}/edit', [ConsignorController::class, 'edit'])->name('master.penitip.edit');
    Route::put('/master/penitip/{consignor}', [ConsignorController::class, 'update'])->name('master.penitip.update');
    Route::patch('/master/penitip/{consignor}/archive', [ConsignorController::class, 'archive'])->name('master.penitip.archive');
    Route::patch('/master/penitip/{consignor}/restore', [ConsignorController::class, 'restore'])->name('master.penitip.restore');

    Route::get('/master/katalog-produk', [ProductController::class, 'index'])->name('master.katalog-produk');
    Route::get('/master/katalog-produk/create', [ProductController::class, 'create'])->name('master.katalog-produk.create');
    Route::post('/master/katalog-produk', [ProductController::class, 'store'])->name('master.katalog-produk.store');
    Route::get('/master/katalog-produk/{product}/edit', [ProductController::class, 'edit'])->name('master.katalog-produk.edit');
    Route::put('/master/katalog-produk/{product}', [ProductController::class, 'update'])->name('master.katalog-produk.update');
    Route::patch('/master/katalog-produk/{product}/approve', [ProductController::class, 'approve'])->name('master.katalog-produk.approve');
    Route::delete('/master/katalog-produk/{product}', [ProductController::class, 'destroy'])->name('master.katalog-produk.destroy');

    Route::get('/master/lokasi-rak', [RackController::class, 'index'])->name('master.lokasi-rak');
    Route::get('/master/lokasi-rak/create', [RackController::class, 'create'])->name('master.lokasi-rak.create');
    Route::post('/master/lokasi-rak', [RackController::class, 'store'])->name('master.lokasi-rak.store');
    Route::get('/master/lokasi-rak/{rack}/edit', [RackController::class, 'edit'])->name('master.lokasi-rak.edit');
    Route::put('/master/lokasi-rak/{rack}', [RackController::class, 'update'])->name('master.lokasi-rak.update');
    Route::patch('/master/lokasi-rak/{rack}/toggle', [RackController::class, 'toggleActive'])->name('master.lokasi-rak.toggle');
    Route::delete('/master/lokasi-rak/{rack}', [RackController::class, 'destroy'])->name('master.lokasi-rak.destroy');
    // Label rak tidak butuh hak Owner: Staff yang menyusun rak juga yang
    // memasang labelnya (FR-MD-21). Form dipisah dari daftar supaya pencarian
    // tabel tidak ikut menyaring daftar rak yang bisa dicetak.
    Route::get('/master/lokasi-rak/cetak-label', [RackController::class, 'labelForm'])->name('master.lokasi-rak.label-form');
    Route::post('/master/lokasi-rak/cetak-label', [RackController::class, 'printLabels'])->name('master.lokasi-rak.print-labels');

    // Seri produk (dipakai modal Kelola Seri di halaman Katalog)
    Route::get('/master/seri', [ProductSeriesController::class, 'index'])->name('master.seri.index');
    Route::post('/master/seri', [ProductSeriesController::class, 'store'])->name('master.seri.store')->middleware('owner');
    Route::put('/master/seri/{series}', [ProductSeriesController::class, 'update'])->name('master.seri.update')->middleware('owner');
    Route::delete('/master/seri/{series}', [ProductSeriesController::class, 'destroy'])->name('master.seri.destroy')->middleware('owner');

    // Impor massal (Owner-only)
    Route::prefix('/master/import')->middleware('owner')->name('master.import.')->group(function () {
        // `template` WAJIB lebih dulu dari `{token}`. Keduanya GET dengan empat
        // segmen dan sama-sama punya satu parameter di posisi ketiga, jadi
        // tanpa urutan ini wildcard `{token}` akan menelan permintaan template
        // dan membawanya ke `mapping()` dengan token bernama "template".
        Route::get('/{module}/template', [ImportController::class, 'template'])->name('template');

        Route::post('/{module}/upload', [ImportController::class, 'upload'])->name('upload');
        Route::get('/{module}/{token}', [ImportController::class, 'mapping'])->name('mapping');

        // Validasi dulu, baru tulis. `preview` hanya membaca dan mengecek --
        // tidak menyentuh database -- lalu menyimpan mapping-nya ke sesi di
        // balik token yang sama. `commit` tidak menerima mapping dari
        // formulir: ia memakai mapping yang sudah ditampilkan dan disetujui
        // orang di langkah sebelumnya, supaya yang tersimpan persis yang
        // sudah dilihat.
        Route::post('/{module}/{token}/preview', [ImportController::class, 'preview'])->name('preview');
        Route::post('/{module}/{token}/commit', [ImportController::class, 'commit'])->name('commit');

        Route::delete('/{module}/{token}', [ImportController::class, 'cancel'])->name('cancel');
    });

    // ===== 2. Inbound =====
    Route::get('/inbound/stock-in-pribadi', [InboundController::class, 'stockInPribadi'])->name('inbound.stock-in-pribadi');
    Route::post('/inbound/stock-in-pribadi', [InboundController::class, 'stockInPribadiStore'])->name('inbound.stock-in-pribadi.store');
    Route::get('/inbound/consignment-in', [InboundController::class, 'consignmentIn'])->name('inbound.consignment-in');
    Route::post('/inbound/consignment-in', [InboundController::class, 'consignmentStore'])->name('inbound.consignment-in.store');
    Route::post('/inbound/consignment-in/drafts', [InboundController::class, 'draftStore'])->name('inbound.consignment-in.drafts.store');
    Route::get('/inbound/consignment-in/drafts/{draftId}', [InboundController::class, 'draftShow'])->name('inbound.consignment-in.drafts.show');
    Route::patch('/inbound/consignment-in/drafts/{draftId}', [InboundController::class, 'draftUpdate'])->name('inbound.consignment-in.drafts.update');
    Route::delete('/inbound/consignment-in/drafts/{draftId}', [InboundController::class, 'draftDestroy'])->name('inbound.consignment-in.drafts.destroy');
    Route::get('/inbound/consignment-in/riwayat', [InboundController::class, 'consignmentHistory'])->name('inbound.consignment-in.riwayat');
    Route::get('/inbound/consignment-in/{consignment}', [InboundController::class, 'consignmentDetail'])->name('inbound.consignment-in.detail');
    // E-receipt WA (FR-IB-16). Kirim ulang tidak butuh hak Owner: yang menekan
    // biasanya Staff yang menerima barang, dan-notifikasi ini tidak mengubah
    // stok maupun dokumen.
    /**
     * Bukti terima titipan yang dicetak dan ditandatangani penitip.
     *
     * Route model `{consignment}` tetap pakai ID, bukan `doc_no`, sama seperti
     * route detail. `doc_no` itu nomor dokumen yang dibaca orang, dan bisa berisi karakter
     * yang harus di-encode di URL; ID-nya yang selalu aman
     * dan selalu milik dokumen yang benar.
     *
     * `?auto=1` dipakai alur commit supaya struk langsung keluar. Halaman yang
     * sama tanpa parameter itu tidak pernah mencetak dirinya sendiri.
     */
    Route::get('/inbound/consignment-in/{consignment}/bukti-terima', [InboundController::class, 'consignmentReceiptPrint'])
        ->name('inbound.consignment-in.bukti-terima');

    /**
     * Catat bukti terima sudah diserahkan ke penitip.
     *
     * `POST`, bukan `GET`, karena ini perubahan catatan. Kalau jadi `GET`, satu
     * muat ulang halaman karena koneksi putus akan menambah satu catatan
     * penyerahan, dan tidak ada yang bisa melihat jumlah aslinya lagi.
     */
    Route::post('/inbound/consignment-in/{consignment}/bukti-terima/serahkan', [InboundController::class, 'consignmentReceiptHandedOver'])
        ->name('inbound.consignment-in.bukti-terima.serahkan');

    /**
     * Minta byte ESC/POS bukti terima untuk cetak thermal.
     *
     * `POST`, bukan `GET`, karena halaman ini menekan tombol "Cetak Thermal"
     * dan byte yang dihasilkan tidak boleh masuk cache browser: struk yang lama
     * dari byte yang ter-cache akan mencetak angka dokumen yang sudah lewat.
     *
     * Pengiriman byte ke printer dilakukan dari perangkat yang membuka halaman
     * (Web Bluetooth), jadi endpoint ini tidak butuh akses ke printer apa pun.
     */
    Route::post('/inbound/consignment-in/{consignment}/bukti-terima/thermal', [InboundController::class, 'consignmentReceiptPrintThermal'])
        ->name('inbound.consignment-in.bukti-terima.thermal');

    Route::post('/inbound/consignment-in/{consignment}/e-receipt', [InboundController::class, 'consignmentReceiptSend'])->name('inbound.consignment-in.e-receipt');
    Route::get('/inbound/cetak-label', [InboundController::class, 'cetakLabel'])->name('inbound.cetak-label');
    Route::post('/inbound/cetak-label', [InboundController::class, 'cetakLabelStore'])->name('inbound.cetak-label.store');
    Route::post('/inbound/cetak-label/render', [InboundController::class, 'renderLabels'])->name('inbound.cetak-label.render');
    // Cetak langsung ke printer TSPL (jalur thermal). Sepasang dengan
    // `renderLabels`: job, urutan, salinan, dan payload sama, bedanya
    // response-nya perintah TSPL yang dikirim device tanpa dialog browser.
    Route::post('/inbound/cetak-label/tsp', [InboundController::class, 'renderLabelsTsp'])->name('inbound.cetak-label.tsp');
    Route::post('/inbound/cetak-label/konfirmasi', [InboundController::class, 'confirmLabels'])->name('inbound.cetak-label.confirm');
    Route::post('/inbound/cetak-label/gagal', [InboundController::class, 'failLabels'])->name('inbound.cetak-label.fail');
    Route::post('/inbound/cetak-label/ulangi', [InboundController::class, 'retryLabels'])->name('inbound.cetak-label.retry');
    Route::post('/inbound/cetak-label/reprint', [InboundController::class, 'reprintLabels'])->name('inbound.cetak-label.reprint');
    // Uji cetak (FR-IB-25): hanya GET dan tidak menyentuh stok, jadi tidak
    // membuat job label apa pun.
    Route::get('/inbound/cetak-label/uji-cetak', [InboundController::class, 'testPrint'])->name('inbound.cetak-label.test-print');

    // ===== 3. Inventory =====
    Route::get('/inventory/live-stock', [InventoryController::class, 'liveStock'])->name('inventory.live-stock');
    Route::get('/inventory/live-stock/ekspor', [InventoryController::class, 'ekspor'])->name('inventory.live-stock.ekspor');
    Route::patch('/inventory/live-stock/{lot}/rak', [InventoryController::class, 'pindahRak'])->name('inventory.live-stock.rak');
    Route::get('/inventory/kartu-stok/{lot}', [InventoryController::class, 'kartuStok'])->name('inventory.kartu-stok');
    Route::get('/inventory/karantina', [InventoryController::class, 'karantina'])->name('inventory.karantina');
    // Siklus opname (FR-IC-20..23). Empat perubahan keadaan memakai POST biasa
    // supaya setiap tombol tetap berbentuk form: ada CSRF, ada galat validasi
    // yang kembali ke halaman yang sama, dan ada riwayat yang bisa dibaca
    // pengguna lewat tombol kembali peramban.
    Route::get('/inventory/stok-opname', [OpnameController::class, 'index'])->name('inventory.stok-opname');
    Route::post('/inventory/stok-opname', [OpnameController::class, 'store'])->name('inventory.stok-opname.store');
    // `scopeBindings()` pada dua route bersarang: tanpanya `{line}` diambil
    // dari `opname_lines` mana pun yang id-nya cocok, sehingga baris sesi lama
    // bisa dihitung atau diputuskan lewat URL sesi yang sedang berjalan. Dengan
    // scoping, baris hanya ditemukan bila ia memang milik `{opname}` di URL.
    Route::post('/inventory/stok-opname/{opname}/baris/{line}/hitung', [OpnameController::class, 'hitung'])->scopeBindings()->name('inventory.stok-opname.hitung');
    Route::post('/inventory/stok-opname/{opname}/ajukan', [OpnameController::class, 'ajukan'])->name('inventory.stok-opname.ajukan');
    Route::post('/inventory/stok-opname/{opname}/baris/{line}/review', [OpnameController::class, 'review'])->scopeBindings()->name('inventory.stok-opname.review');
    Route::post('/inventory/stok-opname/{opname}/batal', [OpnameController::class, 'batal'])->name('inventory.stok-opname.batal');
    Route::get('/inventory/retur-rtv', [RtvController::class, 'index'])->name('inventory.retur-rtv');
    Route::post('/inventory/retur-rtv', [RtvController::class, 'store'])->name('inventory.retur-rtv.store');
    Route::post('/inventory/retur-rtv/{rtv}/staging', [RtvController::class, 'staging'])->name('inventory.retur-rtv.staging');
    Route::post('/inventory/retur-rtv/{rtv}/scan', [RtvController::class, 'scan'])->name('inventory.retur-rtv.scan');
    Route::post('/inventory/retur-rtv/{rtv}/setujui', [RtvController::class, 'approve'])->name('inventory.retur-rtv.approve');
    Route::post('/inventory/retur-rtv/{rtv}/batal', [RtvController::class, 'batal'])->name('inventory.retur-rtv.batal');

    // ===== 4. POS / Kasir =====
    Route::get('/pos/kasir', [PosController::class, 'kasir'])->name('pos.kasir');
    Route::get('/pos/riwayat-transaksi', [PosController::class, 'riwayat'])->name('pos.riwayat');

    /**
     * Satu nota, dibaca utuh.
     *
     * Route model `{sale}` memakai ID, bukan `receipt_no`, sama seperti route
     * detail di tempat lain: `receipt_no` yang dibaca orang dan bisa berisi
     * karakter yang harus di-encode, sedangkan ID selalu aman dan selalu
     * menunjuk nota yang benar.
     *
     * Tanpa middleware `owner`. Batasnya bukan peran, melainkan kepemilikan --
     * sama persis dengan tabel riwayatnya: kasir hanya boleh membuka nota dari
     * shift miliknya sendiri, dan pemiliknya diputuskan di controller. Menaruh
     * middleware `owner` di sini akan membuat kasir tidak bisa membuka nota
     * sendiri, sementara menaruhnya di controller tetap membiarkan Owner
     * membuka semua nota untuk audit.
     */
    Route::get('/pos/nota/{sale}', [PosController::class, 'nota'])->name('pos.nota');

    /**
     * Penyelesaian pembayaran dari layar kasir.
     *
     * `POST`, bukan `GET`: endpoint ini memotong stok, mencatat uang, dan
     * menerbitkan nomor struk. Menjadikannya `GET` berarti satu refresh karena
     * koneksi terputus akan menjual barang yang sama dua kali.
     *
     * Tanpa middleware `owner`, sama seperti siklus shift: menerima uang adalah
     * pekerjaan kasir. Yang dibatasi bukan peran, melainkan shift -- kasir tanpa
     * shift terbuka ditolak di dalam layanan, bukan lewat PIN.
     *
     * Tanpa `throttle` juga: berbeda dengan pencarian produk yang dipanggil
     * setiap kali kasir mengetik, endpoint ini dipaling banyak sekali per
     * pelanggan, dan pembatas laju akan menolak penjualan yang sah pada jam
     * ramai.
     */
    Route::post('/pos/transaksi', [PosController::class, 'store'])->name('pos.transaksi.store');

    /**
     * Halaman shift kasir. `GET` karena ini halaman, bukan aksi: tidak ada
     * yang berubah hanya karena seseorang membuka tautannya.
     */
    Route::get('/pos/shift-kasir', [PosController::class, 'shiftKasir'])->name('pos.shift-kasir');

    /**
     * Siklus shift. Staff maupun Owner, tanpa middleware owner.
     *
     * Membuka dan menutup shift adalah pekerjaan kasir, bukan hak Owner: kalau
     * hanya Owner yang boleh menutup shift, kasir yang pulang tidak bisa
     * menyerahkan laci kecuali menunggu Owner datang. Otorisasi yang dipakai di
     * sini bukan peran, melainkan PIN Owner -- dan itu hanya diminta saat selisih
     * kas melewati ambang, di `CloseShiftRequest`.
     *
     * `POST`, bukan `GET`, untuk keduanya. Menutup shift mengubah angka kas yang
     * dipakai laporan, jadi tidak boleh terjadi karena seseorang membuka tautan.
     */
    Route::post('/pos/shift-kasir/buka', [PosController::class, 'openShift'])->name('pos.shift-kasir.open');
    Route::post('/pos/shift-kasir/{shift}/tutup', [PosController::class, 'closeShift'])->name('pos.shift-kasir.close');

    /**
     * Pencarian produk untuk layar kasir.
     *
     * `POST`, bukan `GET`, karena isinya pencarian yang dibaca orang, bukan
     * alamat yang layak di-bookmark atau di-share. `GET` untuk pencarian juga
     * membuat teks yang diketik kasir masuk ke access log dan history browser,
     * padahal isinya tidak pernah layak disimpan.
     *
     * `throttle` bukan pilihan: halaman kasir memanggil ini setiap kali kasir
     * mengetik, jadi tanpa pembatas satu kasir yang menahan tombol bisa membuat
     * seratus query berat dalam hitungan detik -- persis skenario yang membuat
     * tablet terasa berat di area Inbound.
     */
    Route::post('/pos/produk/cari', ProductLookupController::class)
        ->middleware('throttle:120,1')
        ->name('pos.produk.cari');

    // ===== 5. Reports & Analisis =====
    Route::get('/reports/consignor-settlement', [ReportController::class, 'settlement'])->name('report.settlement');
    Route::get('/reports/profit-margin', [ReportController::class, 'margin'])->name('report.margin');
    Route::get('/reports/laporan-penjualan-stok', [ReportController::class, 'laporan'])->name('report.laporan');
    Route::get('/reports/audit-log', [ReportController::class, 'auditLog'])->name('report.audit-log');

    // ===== 6. Pengaturan =====
    // Managing users is Owner-only across the board -- including the index.
    // The sidebar already hides the entry from Staff, but a hidden menu is not
    // access control: the route was reachable directly, and `index()` has no
    // gate of its own, so Staff could read every user's name, username, email
    // and role by typing the URL. The whole group is wrapped so the answer is
    // readable in one place instead of per-method `abort_unless` calls that
    // can be forgotten.
    Route::prefix('/settings/pengguna-role')->middleware('owner')->group(function (): void {
        Route::get('/', [UserManagementController::class, 'index'])->name('setting.pengguna');
        Route::get('/create', [UserManagementController::class, 'create'])->name('setting.pengguna.create');
        Route::post('/', [UserManagementController::class, 'store'])->name('setting.pengguna.store');
        Route::get('/{user}/edit', [UserManagementController::class, 'edit'])->name('setting.pengguna.edit');
        Route::put('/{user}', [UserManagementController::class, 'update'])->name('setting.pengguna.update');
        Route::patch('/{user}/toggle', [UserManagementController::class, 'toggleActive'])->name('setting.pengguna.toggle');
    });

    Route::get('/settings/perangkat', [SettingController::class, 'perangkat'])->name('setting.perangkat');

    /**
     * Menyimpan pengaturan printer label. Owner saja.
     *
     * `GET` di halaman Perangkat tidak memakai middleware owner karena Staff
     * boleh membuka halaman itu untuk melihat printer yang sedang dipakai.
     * Menyimpan setelan berbeda: ukuran label menentukan isi setiap label yang
     * keluar dari printer, jadi harus dipegang Owner.
     */
    Route::put('/settings/perangkat/label', [SettingController::class, 'savePrinterSettings'])
        ->middleware('owner')
        ->name('setting.perangkat.label.update');

    /**
     * Hitung grid dari angka yang sedang diketik, tanpa menyimpan apa pun.
     *
     * Owner saja, sama seperti menyimpan: angka yang dicek di sini menentukan
     * isi setiap label yang keluar dari printer.
     *
     * Dipisah dari route simpan, dan hanya membaca, karena form ini menampilkan
     * ringkasan grid sebelum Owner menekan Simpan. Ringkasan itu dihitung server
     * supaya tidak ada dua versi rumus -- satu di `SheetGridCalculator`, satu lagi
     * di JavaScript -- yang bisa mulai berbeda lalu diam-diam menghasilkan
     * cetakan yang tidak sesuai dengan yang tertulis di form.
     *
     * `POST` bukan `GET` karena angkanya dikirim di body dan endpoint ini tidak
     * mengubah apa pun; `GET` dengan lima angka di query string akan masuk ke
     * log akses dan jadi terlalu panjang untuk dibaca.
     */
    Route::post('/settings/perangkat/label/preview', [SettingController::class, 'previewPrinterLabel'])
        ->middleware('owner')
        ->name('setting.perangkat.label.preview');

    /**
     * Menyimpan kertas bukti terima titipan. Owner saja.
     *
     * Sama alasannya dengan pengaturan label: kertas menentukan isi cetakan yang
     * dibaca penitip, jadi bukan milik Staff. Hanya satu nilai yang disimpan, tapi
     * jalur simpanannya dipisah supaya izinnya tidak ikut mewarisi route label.
     */
    Route::put('/settings/perangkat/struk', [SettingController::class, 'saveReceiptPrinterSettings'])
        ->middleware('owner')
        ->name('setting.perangkat.struk.update');

    Route::get('/settings/wa-template', [SettingController::class, 'waTemplate'])->name('setting.wa-template');
    Route::put('/settings/wa-template', [SettingController::class, 'saveWaTemplate'])->name('setting.wa-template.update');
    Route::get('/settings/parameter', [SettingController::class, 'parameter'])->name('setting.parameter');

    /**
     * Menyimpan pengaturan POS. Owner saja.
     *
     * Alasannya sama dengan pengaturan printer: ketiganya mengubah angka yang
     * dipakai semua orang -- batas diskon yang dilihat kasir saat memotong harga,
     * dan ambang selisih kas yang menentukan apakah tutup shift perlu PIN Owner.
     * Nilai yang boleh diubah Staff sendiri akan jadi nilai yang tidak dipercaya.
     *
     * Halaman `GET` tetap terbuka untuk semua orang karena isinya hanya hak akses
     * dan batas nominal, bukan data bisnis. Yang dipegang Owner adalah jalur
     * simpanannya.
     */
    Route::put('/settings/parameter', [SettingController::class, 'savePosSettings'])
        ->middleware('owner')
        ->name('setting.parameter.update');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // ===== 7. Otorisasi Owner =====
    // Ditukar PIN Owner dengan token berumur pendek. Dipakai dialog PIN global,
    // lalu tokennya ikut di form yang butuh otorisasi -- bukan PIN-nya.
    Route::post('/pin/verify', [PinController::class, 'verify'])
        ->middleware('throttle:pin-verify')
        ->name('pin.verify');
});

require __DIR__.'/auth.php';
