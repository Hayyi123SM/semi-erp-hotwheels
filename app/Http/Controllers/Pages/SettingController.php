<?php

namespace App\Http\Controllers\Pages;

use App\Enums\LabelPaperMode;
use App\Enums\PaperSize;
use App\Enums\PaymentMethod;
use App\Enums\PrintMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SaveNotificationTemplateRequest;
use App\Http\Requests\Settings\SavePosSettingsRequest;
use App\Http\Requests\Settings\SavePrinterSettingsRequest;
use App\Http\Requests\Settings\SaveReceiptPrinterSettingsRequest;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Consignment\ReceiptPrinterSettings;
use App\Services\Label\LabelPrinterSettings;
use App\Services\Label\LabelSheetSettings;
use App\Services\Label\LabelTemplate;
use App\Services\Label\SheetGrid;
use App\Services\Notification\NotificationTemplate;
use App\Services\Pos\PosSettings;
use App\Services\Pos\SaleStrukSheet;
use App\Services\Print\PrintSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Halaman pengaturan.
 *
 * Dua dari halaman di sini menyimpan perubahan ke `Setting`, yang tidak punya
 * primary key -- identitasnya adalah kunci teksnya sendiri. Itu sebabnya
 * keduanya mencatat jejak audit lewat `entityKey`, bukan `entityId`: kolom
 * kedua adalah `unsignedBigInteger`, dan mengirim kunci teks ke sana membuat
 * MySQL menolak seluruh permintaan dengan "Incorrect integer value".
 *
 * Keduanya juga membungkus simpanan dalam transaksi. Tanpa itu, `Setting`
 * sudah ter-commit sebelum audit ditulis, dan Owner yang melihat 500 akan
 * mengira pengaturannya gagal lalu menekan simpan lagi.
 */
class SettingController extends Controller
{
    /**
     * Deskripsi tiap setelan POS, ikut disimpan bersama nilainya.
     *
     * Disimpan di baris `settings.description` supaya tabel itu bisa dibaca orang
     * lain tanpa harus membuka kode: saat semua key begini punya satu kalimat
     * yang menjelaskan bunyinya, "angka ini setelan apa?" punya jawaban yang
     * tidak bergantung pada orang yang menulis key-nya.
     *
     * Diisi sebagai peta statis, bukan diambil dari enum atau dari nama field
     * form, supaya menambah setelan baru berarti sengaja menambah kalimatnya --
     * bukan diam-diam menyimpan baris tanpa penjelasan.
     */
    private const POS_DESCRIPTIONS = [
        PosSettings::STAFF_DISCOUNT_LIMIT_KEY => 'Potongan harga maksimal (%) yang boleh diberikan kasir tanpa PIN Owner',
        PosSettings::CASH_DIFFERENCE_THRESHOLD_KEY => 'Selisih kas (Rp) yang perlu persetujuan Owner saat shift ditutup',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function pengguna()
    {
        return $this->page('pages.settings.pengguna', [], 'Pengguna & Role');
    }

    public function perangkat(LabelPrinterSettings $printer, PrintSettings $printSettings)
    {
        return $this->page('pages.settings.perangkat', [
            /**
             * Template lengkap, bukan cuma ukuran bawaan yang sedang aktif.
             *
             * Halaman ini memakai label sebagai pengukuran gauge printer, jadi
             * Owner perlu bisa mencetak semua ukuran yang tersedia -- termasuk
             * yang belum jadi bawaan. Daftar yang dipangkas ke template aktif
             * akan membuat halaman itu tidak bisa dipakai untuk kalibrasi.
             */
            'labelTemplates' => collect(LabelTemplate::cases())
                ->mapWithKeys(static fn (LabelTemplate $template): array => [
                    $template->value => [
                        'label' => $template->label(),
                        'description' => $template->description(),
                        'qrSideCm' => $template->defaultQrSideCm(),
                    ],
                ])
                ->all(),
            'defaultTemplate' => $printer->defaultTemplate()->value,
            'qrSideCm' => $printer->qrSideCm(),

            /**
             * Mode kertas, dari enum.
             *
             * `paperMode()` yang dikirim, bukan `paperMode()->value()`, supaya
             * select menampilkan nilai yang benar-benar berlaku. Kalau yang
             * dikirim enum sementara form menyimpan string, select akan terlihat
             * salah saat dibuka -- persis masalah yang sudah diperbaiki untuk
             * kartu struk.
             */
            'paperMode' => $printer->paperMode()->value,
            'labelPaperModes' => LabelPaperMode::options(),

            /**
             * Cara kirim label ke printer, bentuknya sama seperti struk.
             *
             * `printMethod()` (bukan setting mentah) supaya instalasi yang
             * belum pernah menyimpan kolom ini tetap menampilkan `browser` --
             * satu-satunya cara yang berjalan sebelum jalur langsung ada.
             */
            'labelPrintMethod' => $printer->printMethod()->value,
            'labelPrintMethods' => PrintMethod::options(),

            /**
             * Label mode dan ukuran kertas untuk tampilan baca-saja milik Staff.
             *
             * Diturunkan dari enum, bukan dicari dari daftar `options()` di
             * Blade: `options()` mengembalikan list dengan kunci `value`, jadi
             * `$labelPaperModes[$paperMode]` tidak akan menemukan apa pun --
             * form Owner salah baca mode sebagai "Gulungan" tanpa error.
             *
             * `sheetLabel` sengaja null di mode gulungan. Nilai input yang dikirim
             * ke form di atas memakai angka bawaan supaya tidak kosong saat Owner
             * sedang mengaktifkan mode stiker; kalau labelnya ikut memakai angka
             * itu, Staff akan membaca "100 x 150 mm" di bawah "Gulungan", yaitu
             * dua mode sekaligus.
             */
            'paperModeLabel' => $printer->paperMode()->label(),
            'sheetLabel' => $this->sheetLabel($printer),

            /**
             * Ukuran kertas, celah, dan batas cetak yang berlaku sekarang.
             *
             * Yang dikirim angka yang sudah diformat untuk `<input type="number">`,
             * yaitu dengan koma. `<input type="number">` menolak koma sebagai
             * pemisah desimal, jadi angka mentah `100.0` harus diubah ke bentuk
             * `100` -- bukan `100,0`, yang akan membuat browser menandai kolom
             * itu sebagai tidak valid tepat saat Owner membuka form.
             *
             * Sumbernya `sheet()`, bukan `Setting::get()` per key: `sheet()`
             * sudah menerapkan fallback blueprint lama dan bawaan, jadi Owner
             * yang instalasinya masih pakai preset lama melihat angka yang sama
             * dengan yang akan dipakai halaman cetak.
             *
             * `sheetGrid` ikut dikirim supaya ringkasan di form selalu berasal
             * dari kalkulator yang sama dengan halaman cetak, bukan dari
             * perhitungan ulang di JavaScript.
             *
             * @var array{width: string, height: string, hasGap: bool, gap: string, maxPrintWidth: string, grid: array<string, mixed>}
             */
            'sheet' => $this->sheetViewData($printer),

            /**
             * Kartu struk memakai daftar dari enum, bukan daftar yang disalin di
             * view. Kalau `PaperSize` dapat kasus baru, daftar ini ikut
             * muncul tanpa ada bagian view yang perlu disunting.
             */
            'papers' => PaperSize::options(),

            /**
             * Yang terkirim adalah yang tersimpan, bukan hasil `paper()`.
             * `paper()` selalu mengembalikan nilai, jadi tidak bisa membedakan
             * "Owner memilih A4" dari "Owner belum pernah menyimpan apa pun" --
             * dan select yang menampilkan pilihan bawaan tanpa disimpan akan
             * terlihat sudah beres saat form dikirim tanpa sengaja.
             */
            'paper' => $printSettings->storedPaper(),

            /**
             * Cara cetak global, dengan bentuk yang sama seperti kertas: nilai
             * yang tersimpan, atau `null` kalau belum pernah disimpan.
             */
            'method' => $printSettings->storedMethod(),
            'methods' => PrintMethod::options(),

            /**
             * Pratinjau struk untuk ketiga ukuran kertas, dirender sekali di
             * server dan diganti lewat Alpine di klien.
             */
            'strukPreviews' => $this->strukPreviewSheets(auth()->user()),
        ], 'Perangkat');
    }

    /**
     * Tiga `SaleStrukSheet` (58/80/A4) dari satu nota contoh, tanpa menyentuh
     * database.
     *
     * Owner mengubah kertas sambil melihat langsung bagaimana struknya berubah,
     * jadi pratinjau harus ada sebelum ada transaksi nyata dan tidak boleh
     * berubah isi setiap kali halaman dibuka -- nota contoh yang dirancang
     * sekali lebih berguna daripada "nota terakhir", yang bisa saja berisi satu
     * baris tanpa diskon dan membuat kartu terlihat belum selesai.
     *
     * Model dibuat dengan `new`, bukan `factory()->make()`: semua yang dibaca
     * struk (angka, relasi, pembayaran) sudah di-set di sini, dan pratinjau
     * tidak bergantung pada isi database yang bisa berubah karena seeding.
     *
     * @return array<string, SaleStrukSheet>
     */
    private function strukPreviewSheets(User $printedBy): array
    {
        $sale = new Sale([
            'receipt_no' => 'HW-20261008-0042',
            'shift_id' => 7,
            'sold_at' => now(),
            'subtotal' => 180_000,
            'discount_total' => 15_000,
            'total' => 165_000,
        ]);

        $sale->setRelation('items', collect($this->strukPreviewItems()));
        // 200.000 tunai untuk nota 165.000: baris "Kembalian" adalah salah satu
        // elemen yang paling mudah hilang kalau nota contoh dibuat pas-pasan.
        $sale->setRelation('payments', collect([
            new SalePayment(['method' => PaymentMethod::Cash, 'amount' => 200_000]),
        ]));

        return collect(PaperSize::cases())
            ->mapWithKeys(static fn (PaperSize $paper): array => [
                $paper->value => new SaleStrukSheet(
                    sale: $sale,
                    paper: $paper,
                    printedBy: $printedBy,
                    changeDue: 35_000,
                ),
            ])
            ->all();
    }

    /**
     * Dua baris barang untuk nota contoh: satu berdiskon, satu tidak.
     *
     * Nama produk menempel lewat relasi `lot -> product`, sama seperti penjualan
     * asli: `SaleStrukSheet::lines()` membaca nama dari sana, dan nota contoh
     * yang hanya menampilkan SKU membuat pratinjau berbeda dari struk nyata.
     *
     * @return list<SaleItem>
     */
    private function strukPreviewItems(): array
    {
        $fastAndFurious = new StockLot(['sku' => 'HW-FX001']);
        $fastAndFurious->setRelation('product', new Product(['name' => 'Fast & Furious 5-Pack']));

        $dragBus = new StockLot(['sku' => 'HW-TH01']);
        $dragBus->setRelation('product', new Product(['name' => 'Treasure Hunt VW Drag Bus']));

        $first = new SaleItem([
            'sku' => 'HW-FX001',
            'qty' => 3,
            'list_price' => 60_000,
            'discount' => 5_000,
            'sell_price' => 55_000,
        ]);
        $first->setRelation('lot', $fastAndFurious);

        $second = new SaleItem([
            'sku' => 'HW-TH01',
            'qty' => 1,
            'list_price' => 25_000,
            'discount' => 10_000,
            'sell_price' => 15_000,
        ]);
        $second->setRelation('lot', $dragBus);

        return [$first, $second];
    }

    /**
     * Hitung grid untuk angka yang sedang diketik, tanpa menyimpan apa pun.
     *
     * Endpoint ini ada supaya ringkasan grid di form bisa bergerak mengikuti
     * angka Owner, tanpa menyalin rumus `SheetGridCalculator` ke JavaScript.
     * Dua versi rumus yang sama di dua bahasa adalah cara paling murah untuk
     * membuat Owner melihat "6 x 8 = 48 label", menekan Simpan, lalu mendapat
     * cetakan dengan 4 label per halaman tanpa ada yang gagal.
     *
     * Sengaja tidak memakai `SavePrinterSettingsRequest`: form ini harus
     * menampilkan penjelasan untuk angka yang justru DITOLAK, jadi angka yang
     * belum lengkap tidak boleh membuat endpoint ini membalas 422. Yang dikembalikan
     * hanya bentuk JSON yang sudah jadi baca-Alpine, termasuk alasan penolakan
     * yang dikelompokkan per kolom form.
     */
    public function previewPrinterLabel(Request $request, LabelPrinterSettings $printer): JsonResponse
    {
        $template = LabelTemplate::tryFrom((string) $request->input('default_template'))
            ?? $printer->defaultTemplate();

        $width = $this->previewMm($request, 'sheet_media_width_mm');
        $height = $this->previewMm($request, 'sheet_media_height_mm');
        $gap = $this->previewMm($request, 'sheet_gap_mm');
        $hasGap = $request->boolean('sheet_has_gap');
        $maxPrintWidth = $this->previewMm($request, 'max_print_width_mm');

        /**
         * Angka yang belum lengkap membuat ringkasan tidak bisa dihitung.
         *
         * Jawabannya bukan grid kosong dan bukan 422: Owner sedang mengetik,
         * jadi yang perlu dikembalikan adalah kolom mana yang belum terisi
         * -- bukan kalimat validasi, yang sudah ada di bawah tiap kolom begitu
         * Owner menekan Simpan.
         *
         * Celah ikut dihitung hanya kalau centangnya menyala, karena angka
         * pada kolom yang dinonaktifkan memang tidak dikirim browser.
         */
        $missing = [];

        foreach (['sheet_media_width_mm' => $width, 'sheet_media_height_mm' => $height] as $field => $value) {
            if ($value === null) {
                $missing[] = $field;
            }
        }

        // Celah yang dimatikan tidak masuk daftar ini sama sekali: kolomnya
        // dinonaktifkan browser dan tidak dikirim, jadi tidak ada yang perlu
        // ditunggu. Menandainya sebagai belum terisi akan membuat ringkasan
        // tidak pernah selesai dihitung selama Owner menyetel label rapat.
        if ($hasGap && $gap === null) {
            $missing[] = 'sheet_gap_mm';
        }

        if ($missing !== []) {
            return response()->json([
                'readable' => false,
                'fits' => false,
                'missing' => $missing,
                'errors' => [],
            ]);
        }

        $sheet = new LabelSheetSettings(
            mediaWidthMm: $width,
            mediaHeightMm: $height,
            hasGap: $hasGap,
            gapMm: $gap ?? 0.0,
            maxPrintWidthMm: $maxPrintWidth ?? $printer->sheet()->maxPrintWidthMm,
        );

        $grid = $sheet->gridFor($template);

        /**
         * Penolakan dikelompokkan per kolom form, sama seperti saat menyimpan.
         *
         * Satu peta untuk kedua jalur, supaya preview tidak mungkin
         * menampilkan alasan di kolom yang berbeda dari yang akan ditunjuk
         * setelah Owner menekan Simpan.
         */
        $errors = [];

        foreach ($sheet->rejectionsBySetting($template) as $setting => $messages) {
            $field = SavePrinterSettingsRequest::SHEET_FIELD_BY_SETTING[$setting] ?? null;

            if ($field === null) {
                continue;
            }

            foreach ($messages as $message) {
                $errors[$field][] = $message;
            }
        }

        return response()->json([
            'readable' => true,
            'fits' => $errors === [] && $grid->isPrintable(),
            'missing' => [],
            'errors' => $errors,
            'summary' => $grid->summary(),
            'labelsPerSheet' => $grid->labelsPerSheet(),
            'pageSize' => $grid->pageSizeCss(),
            'trailingSlack' => $grid->trailingSlackLabel(),
        ]);
    }

    /**
     * Angka dari input preview, atau `null` kalau belum diisi atau bukan angka.
     *
     * `null` berarti "belum diketahui", bukan `0`. Kalau teks yang diketik
     * Owner dibaca sebagai `0`, ringkasan akan melaporkan kertas 0 x 0 mm --
     * jawaban yang tidak mungkin dan membuat Owner mengira ada yang salah di
     * rumusnya, bukan di kolomnya.
     */
    private function previewMm(Request $request, string $key): ?float
    {
        $value = $request->input($key);

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Simpan ukuran label, sisi QR, mode kertas, dan blueprint stiker.
     *
     * Nilai lama dibaca sebelum transaksi dibuka, bukan di dalamnya: pembacaan
     * `Setting` tidak butuh terkunci, dan snapshot-nya harus mencerminkan
     * keadaan sebelum ada yang berubah.
     */
    public function savePrinterSettings(SavePrinterSettingsRequest $request, LabelPrinterSettings $printer)
    {
        $previousTemplate = $printer->defaultTemplate()->value;
        $previousQrSide = $printer->qrSideCm();
        $previousPaperMode = $printer->paperMode()->value;
        $previousPrintMethod = $printer->printMethod()->value;
        $previousStickerSheet = $printer->stickerSheet()?->value;

        /**
         * Angka kertas dan celah yang akan berlaku setelah simpan.
         *
         * Dibaca dari `sheet()` request, bukan dari input mentah: pemformatan,
         * pembulatan, dan Rules sudah berlalu di situ. Membaca input lagi di
         * sini berarti mengulang aturan yang sama dengan bentuk berbeda, dan
         * request yang lolos validasi bisa tetap menghasilkan angka yang
         * berbeda dari yang divalidasi.
         */
        $previousSheet = $printer->sheet();
        $sheet = $request->sheet();
        $maxPrintWidthMm = $request->maxPrintWidthMm();

        $template = $request->defaultTemplate();
        $qrSideCm = $request->qrSideCm();
        $paperMode = $request->paperMode();
        $stickerSheet = $request->stickerSheet();
        $printMethod = $request->printMethod();

        /**
         * Setelan dan jejaknya ditulis bersama, atau tidak sama sekali.
         *
         * Tanpa transaksi, `Setting` sudah ter-commit sebelum baris audit masuk
         * database. Kalau auditnya gagal, Owner melihat 500 padahal
         * pengaturannya sudah tersimpan -- lalu menekan simpan sekali lagi dan
         * mendapat satu baris audit tambahan untuk perubahan yang sama.
         */
        DB::transaction(function () use ($template, $qrSideCm, $paperMode, $printMethod, $stickerSheet, $sheet, $maxPrintWidthMm, $previousTemplate, $previousQrSide, $previousPaperMode, $previousPrintMethod, $previousStickerSheet, $previousSheet): void {
            Setting::set(LabelPrinterSettings::DEFAULT_TEMPLATE_KEY, $template->value, 'Ukuran label bawaan untuk label baru');

            /**
             * Sisi QR kosong berarti "pakai bawaan ukuran label", jadi barisnya
             * dihapus, bukan ditulis `null`. `Setting::set()` menyaring `null` agar
             * description lama tidak tertimpa, dan itu berlaku juga untuk `value` --
             * jadi `set($key, null)` terlihat berhasil sementara angka lamanya masih
             * terbaca. Form yang menampilkan kolom kosong karena itulah yang terjadi.
             */
            if ($qrSideCm === null) {
                Setting::forget(LabelPrinterSettings::QR_SIDE_KEY);
            } else {
                Setting::set(LabelPrinterSettings::QR_SIDE_KEY, $qrSideCm, 'Sisi QR label dalam sentimeter');
            }

            Setting::set(LabelPrinterSettings::PAPER_MODE_KEY, $paperMode->value, 'Mode kertas printer label: gulungan atau stiker');

            /**
             * Cara kirim label hanya disimpan kalau form benar-benar
             * mengirimnya.
             *
             * Form versi lama tidak punya kolom ini, dan memaksakan `browser`
             * akan menimpa pilihan thermal instalasi yang sudah menyimpannya
             * hanya karena Owner menyimpan ukuran kertas. `null` = biarkan
             * key lama apa adanya, sama seperti `max_print_width_mm`.
             */
            if ($printMethod !== null) {
                Setting::set(
                    LabelPrinterSettings::PRINT_METHOD_KEY,
                    $printMethod->value,
                    'Cara kirim label ke printer: dialog browser atau langsung (TSPL)',
                );
            }

            /**
             * Ukuran kertas dan celah ditulis terpisah dari batas cetak printer.
             *
             * Batas cetak tidak ikut dihapus di mode gulungan, tidak seperti
             * angka kertas: printer yang bisa mencetak 216 mm tidak suddenly
             * kembali ke 108 mm hanya karena Owner sedang menyimpan mode
             * gulungan. Kolomnya ada di kedua mode dan tidak bergantung pada
             * mode kertas.
             *
             * Karena itu `null` dari request berarti "tidak mengirim", bukan
             * "hapus": form versi lama tidak punya kolom ini, dan menghapusnya
             * akan mengembalikan printer 216 mm ke 108 mm tanpa Owner pernah
             * menyentuhnya.
             */
            if ($maxPrintWidthMm !== null) {
                Setting::set(
                    LabelPrinterSettings::MAX_PRINT_WIDTH_MM_KEY,
                    $maxPrintWidthMm,
                    'Lebar area cetak printer label dalam milimeter',
                );
            }

            /**
             * Ukuran kertas dihapus di mode gulungan, blueprint lama ikut
             * dihapus, dan keduanya tidak disimpan bersembunyi.
             *
             * Kalau tetap ditulis, `Setting` akan berisi ukuran kertas yang tidak
             * berlaku dan tidak pernah dibaca -- dan pembacaan audit "¿kertas
             * seperti apa yang dipakai waktu label ini dicetak?" akan menemukan
             * angka yang ternyata tidak dipakai. Baris yang tidak berlaku lebih
             * membingungkan daripada tidak ada.
             *
             * Blueprint lama ikut dihapus karena persis alasan yang sama, dan
             * karena ia sudah tidak berlaku sejak mode pertama bukan gulungan.
             */
            if ($sheet === null) {
                foreach ([
                    LabelPrinterSettings::SHEET_MEDIA_WIDTH_MM_KEY,
                    LabelPrinterSettings::SHEET_MEDIA_HEIGHT_MM_KEY,
                    LabelPrinterSettings::SHEET_HAS_GAP_KEY,
                    LabelPrinterSettings::SHEET_GAP_MM_KEY,
                    LabelPrinterSettings::STICKER_SHEET_KEY,
                ] as $key) {
                    Setting::forget($key);
                }
            } else {
                Setting::set(
                    LabelPrinterSettings::SHEET_MEDIA_WIDTH_MM_KEY,
                    $sheet->mediaWidthMm,
                    'Lebar kertas stiker dalam milimeter',
                );
                Setting::set(
                    LabelPrinterSettings::SHEET_MEDIA_HEIGHT_MM_KEY,
                    $sheet->mediaHeightMm,
                    'Tinggi kertas stiker dalam milimeter',
                );
                Setting::set(
                    LabelPrinterSettings::SHEET_HAS_GAP_KEY,
                    $sheet->hasGap,
                    'Apakah ada celah antar label di kertas stiker',
                );
                Setting::set(
                    LabelPrinterSettings::SHEET_GAP_MM_KEY,
                    $sheet->gapMm,
                    'Celah antar label pada kertas stiker dalam milimeter',
                );

                /**
                 * Blueprint lama dihapus hanya kalau angka barunya sudah
                 * benar-benar tersimpan.
                 *
                 * Dua-duanya sumber yang sama untuk ukuran kertas, jadi
                 * membiarkannya berdua membuat pembacaan jadi ambigu: angka mana
                 * yang berlaku waktu label dicetak? Key yang baru selalu menang,
                 * jadi yang lama bisa dihapus.
                 *
                 * Kalau form yang menyimpan masih form lama yang hanya mengirim
                 * blueprint, baris lamanya dibiarkan apa adanya: menghapus
                 * satu-satunya catatan ukuran kertas yang ada akan membuat
                 * instalasi itu berikutnya tercetak dengan ukuran bawaan.
                 */
                if ($stickerSheet === null) {
                    Setting::forget(LabelPrinterSettings::STICKER_SHEET_KEY);
                } else {
                    Setting::set(
                        LabelPrinterSettings::STICKER_SHEET_KEY,
                        $stickerSheet->value,
                        'Blueprint kertas stiker untuk mode stiker',
                    );
                }
            }

            /**
             * Versi lama ikut dilampirkan di log.
             *
             * Ukuran label menentukan isi label, jadi begitu job dibuat, ukurannya
             * tersimpan di job itu dan tidak lagi ikut berubah. Kalau ada label
             * yang nanti terbukti salah cetak, satu-satunya sumber untuk tahu
             * "printer sedang disetel seperti apa waktu itu" adalah catatan ini --
             * bukan setelan sekarang, yang sudah berubah.
             */
            $this->audit->log('UPDATE_PRINTER_SETTINGS', 'Setting', before: [
                'default_template' => $previousTemplate,
                'qr_side_cm' => $previousQrSide,
                'paper_mode' => $previousPaperMode,
                'print_method' => $previousPrintMethod,
                'sticker_sheet' => $previousStickerSheet,
                /**
                 * Angka kertas ikut dicatat, bukan hanya blueprint.
                 *
                 * Kalau complaint "stiker paling kanan selalu terpotong" muncul
                 * bulan depan, setelan yang berlaku saat itu sudah tidak ada lagi
                 * -- jadi satu-satunya sumber jawabannya adalah baris audit ini.
                 */
                'sheet_media_width_mm' => $previousSheet->mediaWidthMm,
                'sheet_media_height_mm' => $previousSheet->mediaHeightMm,
                'sheet_has_gap' => $previousSheet->hasGap,
                'sheet_gap_mm' => $previousSheet->effectiveGapMm(),
                'max_print_width_mm' => $previousSheet->maxPrintWidthMm,
            ], after: [
                'default_template' => $template->value,
                'qr_side_cm' => $qrSideCm,
                'paper_mode' => $paperMode->value,
                'print_method' => $printMethod?->value ?? $previousPrintMethod,
                'sticker_sheet' => $stickerSheet?->value,
                'sheet_media_width_mm' => $sheet?->mediaWidthMm,
                'sheet_media_height_mm' => $sheet?->mediaHeightMm,
                'sheet_has_gap' => $sheet?->hasGap,
                /**
                 * Celah yang dicatat adalah celah yang aktif, bukan angkanya.
                 *
                 * Kalau Owner mematikan celah, angka 2 mm masih tersimpan sebagai
                 * pengingat, tapi yang dicetak jaraknya 0. Audit yang mencatat
                 * angka yang tidak dipakai akan membuat "&celah 2 mm" terlihat
                 * benar di catatan padahal cetakannya berjarak 0.
                 */
                'sheet_gap_mm' => $sheet?->effectiveGapMm(),
                'max_print_width_mm' => $maxPrintWidthMm ?? $previousSheet->maxPrintWidthMm,
            ], entityKey: 'label.printer');
        });

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Pengaturan printer label disimpan. Berlaku untuk label yang dibuat berikutnya; job yang sudah ada tetap memakai ukuran lamanya.',
        ]);

    }

    /**
     * Angka kertas, celah, batas cetak, dan ringkasan grid untuk form.
     *
     * Dihitung dari `sheet()` supaya angka yang tampil di form, angka yang
     * dibaca halaman cetak, dan angka yang dipakai validasi semuanya berasal
     * dari sumber yang sama. Kalau form punya hitungannya sendiri, Owner bisa
     * melihat "6 x 8 = 48 label" di sini lalu mendapat cetakan dengan 4 label
     * per halaman tanpa ada yang gagal.
     *
     * Ringkasannya dikirim apa adanya, termasuk saat grid-nya tidak bisa
     * dicetak. Penjelasan "kenapa ditolak" lebih berguna daripada angka yang
     * disembunyikan: yang tidak bisa dicetak ditandai lewat `fits`, bukan lewat
     * kembalian kosong.
     *
     * @return array{width: string, height: string, hasGap: bool, gap: string, maxPrintWidth: string, grid: array<string, mixed>}
     */
    private function sheetViewData(LabelPrinterSettings $printer): array
    {
        $sheet = $printer->sheet();
        $grid = $sheet->gridFor($printer->defaultTemplate());

        return [
            /**
             * `inputValue()`, bukan `mm()`: kolomnya `<input type="number">`
             * yang hanya menerima titik sebagai pemisah desimal.
             */
            'width' => SheetGrid::inputValue($sheet->mediaWidthMm),
            'height' => SheetGrid::inputValue($sheet->mediaHeightMm),
            'hasGap' => $sheet->hasGap,
            'gap' => SheetGrid::inputValue($sheet->gapMm),
            'maxPrintWidth' => SheetGrid::inputValue($sheet->maxPrintWidthMm),
            'grid' => [
                'fits' => $grid->isPrintable(),
                'summary' => $grid->summary(),
                'labelsPerSheet' => $grid->labelsPerSheet(),
                'pageSize' => $grid->pageSizeCss(),
                'customProperties' => $grid->gridCustomProperties(),
                'style' => $grid->gridStyle(),
                'trailingSlack' => $grid->trailingSlackLabel(),
            ],
        ];
    }

    /**
     * Ringkasan ukuran kertas untuk tampilan baca-saja.
     *
     * `null` di mode gulungan. Angka kertasnya sendiri memang selalu ada --
     * semuanya begitu, termasuk bawaannya -- tapi yang berlaku berbeda: di
     * mode gulungan tidak ada grid, jadi menampilkan "100 x 150 mm" di bawah
     * "Gulungan" hanya memberi Staff angka yang tidak berlaku.
     */
    private function sheetLabel(LabelPrinterSettings $printer): ?string
    {
        if ($printer->paperMode() !== LabelPaperMode::Sheet) {
            return null;
        }

        $sheet = $printer->sheet();

        return sprintf(
            '%s x %s mm%s',
            SheetGrid::mm($sheet->mediaWidthMm),
            SheetGrid::mm($sheet->mediaHeightMm),
            $sheet->hasGap
                ? sprintf(', celah %s mm', SheetGrid::mm($sheet->effectiveGapMm()))
                : ', tanpa celah',
        );
    }

    /**
     * Simpan kertas bukti terima titipan.
     *
     * Nilai lama dibaca sebelum transaksi dibuka, sama seperti pengaturan label:
     * pembacaan `Setting` tidak butuh terkunci, dan snapshot-nya harus
     * mencerminkan keadaan sebelum ada yang berubah.
     */
    public function saveReceiptPrinterSettings(
        SaveReceiptPrinterSettingsRequest $request,
        PrintSettings $printSettings,
    ) {
        $previousPaper = $printSettings->storedPaper();
        $previousMethod = $printSettings->storedMethod();
        $paper = $request->paper();
        $method = $request->printMethod();
        $postCommitMode = $request->input('post_commit_mode') ?? 'auto_print';

        /**
         * Setelan dan jejaknya ditulis bersama, atau tidak sama sekali.
         *
         * Sama seperti pengaturan label: tanpa transaksi, `Setting` sudah
         * ter-commit sebelum baris audit masuk. Kalau auditnya gagal, Owner
         * melihat 500 padahal pengaturannya sudah tersimpan -- lalu menekan simpan
         * lagi dan mendapat satu baris audit tambahan untuk perubahan yang sama.
         */
        DB::transaction(function () use ($paper, $method, $postCommitMode, $previousPaper, $previousMethod): void {
            Setting::set(PrintSettings::PAPER_KEY, $paper->value, 'Ukuran kertas untuk semua cetakan dokumen');
            Setting::set(PrintSettings::METHOD_KEY, $method->value, 'Cara mengirim dokumen ke printer');
            Setting::set(ReceiptPrinterSettings::POST_COMMIT_MODE_KEY, $postCommitMode, 'Perilaku setelah commit consignment');

            /**
             * Ukuran kertas ikut dicatat bersama format lain.
             *
             * Ukuran menentukan isi cetakan: `@page`, lebar area, dan pilihan tata
             * letak struk atau A4 semuanya ikut berubah. Cetakan yang jadi bukti
             * harus bisa ditelusuri ke ukuran yang dipakai waktu itu, karena
             * complaint "angka pada struk berbeda" hampir selalu sebenarnya
             * "struk yang itu bukan yang saya terima".
             */
            $this->audit->log(
                'UPDATE_RECEIPT_PRINTER_SETTINGS',
                'Setting',
                before: ['paper' => $previousPaper?->value, 'method' => $previousMethod?->value],
                after: ['paper' => $paper->value, 'method' => $method->value, 'post_commit_mode' => $postCommitMode],
                entityKey: 'print',
            );
        });

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Pengaturan struk disimpan. Berlaku untuk alur berikutnya; struk yang sudah dicetak tetap memakai ukuran lamanya.',
        ]);
    }

    public function waTemplate()
    {
        return $this->page('pages.settings.wa-template', [
            'templates' => collect(NotificationTemplate::cases())
                ->mapWithKeys(fn (NotificationTemplate $template): array => [
                    $template->value => [
                        'label' => $template->title(),
                        'body' => $template->body(),
                        /**
                         * Nomor parameter sudah ikut, bukan dihitung di view.
                         *
                         * Menyusun `{{1}}` di dalam Blade mustahil tanpa
                         * menyesatkan parser-nya: `{{ sprintf('{{%d}}', 1) }}`
                         * dipecah oleh kurung kurawal ganda di dalam string,
                         * dan view-nya gagal diparse dengan galat yang sama
                         * sekalipun sintaks PHP-nya benar.
                         */
                        'variables' => array_map(
                            static fn (string $name, int $index): array => [
                                'position' => $index + 1,
                                'name' => $name,
                                'label' => sprintf('{{%d}} %s', $index + 1, $name),
                            ],
                            $template->srsParameters(),
                            array_keys($template->srsParameters()),
                        ),
                        'custom' => Setting::get($template->settingKey()) !== null,
                    ],
                ])
                ->all(),
            'recentNotifications' => Notification::query()
                ->with('consignment')
                ->latest('id')
                ->limit(8)
                ->get(),
        ], 'Template WhatsApp');
    }

    /**
     * Simpan isi satu template WhatsApp.
     *
     * Sama seperti halaman Perangkat: setelan dan jejaknya satu transaksi.
     * Tanpa itu, `Setting` ter-commit sebelum baris audit masuk, dan pemanggil
     * yang melihat 500 akan mengira templatenya tidak tersimpan.
     */
    public function saveWaTemplate(SaveNotificationTemplateRequest $request)
    {
        $template = $request->notificationTemplate();

        abort_if($template === null, 422, 'Jenis template tidak dikenal.');

        $previous = (string) Setting::get($template->settingKey(), $template->defaultBody());

        DB::transaction(function () use ($template, $previous, $request): void {
            Setting::set($template->settingKey(), $request->body());

            /**
             * Versi lama ikut dilampirkan di log.
             *
             * Kalau isi template nanti terbukti salah, yang dicari pertama selalu
             * "isi template apa yang aktif pada saat itu", bukan "isi template
             * bawaannya apa". Bedanya nyata: begitu ada Staff yang menyesuaikan
             * isi template, isi bawaan tidak lagi menjawab pertanyaan itu, dan
             * tanpa snapshot di sini tidak ada sumber yang bisa dicari.
             */
            $this->audit->log('UPDATE_WA_TEMPLATE', 'Setting', before: [
                'template' => $template->value,
                'body' => $previous,
            ], after: [
                'template' => $template->value,
                'body' => $request->body(),
            ], entityKey: $template->settingKey());
        });

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Template "'.$template->title().'" disimpan. Berlaku untuk pesan berikutnya.',
        ]);
    }

    public function parameter(PosSettings $posSettings, PrintSettings $printSettings)
    {
        return $this->page('pages.settings.parameter', [
            /**
             * Nilai yang berlaku, bukan yang tersimpan mentah.
             *
             * Form harus menampilkan angka yang sedang dipakai server. Kalau yang
             * dikirim yang mentah, form akan menampilkan kolom kosong untuk
             * pengaturan yang belum pernah disimpan, lalu kasir akan menyimpannya
             * kembali dengan angka kosong -- dan pembacaan yang memakainya tidak
             * bisa dibedakan dari "Owner sengaja mengosongkan".
             */
            'posSettings' => $posSettings->current(),

            /**
             * Kertas global, ditampilkan baca-saja di sini supaya kasir tidak
             * mencari pengaturannya di tempat yang bukan rumahnya.
             */
            'paper' => $printSettings->paper(),
        ], 'Parameter Aplikasi');
    }

    /**
     * Simpan pengaturan POS: batas diskon kasir dan ambang selisih kas.
     *
     * Nilai lama dibaca sebelum transaksi dibuka, bukan di dalamnya: pembacaan
     * `Setting` tidak butuh terkunci, dan snapshot-nya harus mencerminkan keadaan
     * sebelum ada yang berubah.
     */
    public function savePosSettings(SavePosSettingsRequest $request, PosSettings $posSettings)
    {
        $before = $posSettings->snapshot();
        $values = $request->settings();

        DB::transaction(function () use ($values, $before): void {
            foreach ($values as $key => $value) {
                Setting::set($key, $value, self::POS_DESCRIPTIONS[$key] ?? null);
            }

            /**
             * Jejak audit memakai bentuk yang sama rata dengan `before`, sesuai
             * aturan `AuditLogger`: laporan menampilkan keduanya berdampingan sebagai
             * diff, dan bentuk yang berbeda per halaman memaksa pembaca laporan
             * tahu bentuk mana yang sedang dihadapannya.
             */
            $this->audit->log('UPDATE_POS_SETTINGS', 'Setting', before: $before, after: [
                'staff_discount_limit_percent' => $values[PosSettings::STAFF_DISCOUNT_LIMIT_KEY] ?? null,
                'cash_difference_threshold' => $values[PosSettings::CASH_DIFFERENCE_THRESHOLD_KEY] ?? null,
            ], entityKey: 'pos');
        });

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Pengaturan POS disimpan. Berlaku untuk shift yang dibuka setelah ini.',
        ]);
    }
}
