<?php

declare(strict_types=1);

namespace App\Services\Label;

use App\Enums\LabelPaperMode;
use App\Enums\PrintMethod;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Pengaturan printer label yang bisa diubah Owner.
 *
 * Empat hal yang bisa diatur: ukuran label yang jadi bawaan saat label dibuat
 * tanpa pilihan eksplisit, sisi QR dalam sentimeter, mode kertas, dan blueprint
 * kertas stiker.
 *
 * Bacaannya tidak pernah melempar. `Setting` bisa berisi apa saja -- termasuk
 * nilai yang sudah tidak valid karena diisi manual atau diubah di luar aplikasi.
 * Kalau pembacaan gagal, yang dikembalikan adalah nilai bawaan yang memang
 * sudah dipakai sistem sebelum fitur ini ada, sehingga label tetap tercetak.
 */
final class LabelPrinterSettings
{
    public const DEFAULT_TEMPLATE_KEY = 'label.default_template';

    public const QR_SIDE_KEY = 'label.qr_side_cm';

    /**
     * Mode kertas: gulungan atau stiker.
     *
     * Tidak ada nilai bawaan yang berarti "belum ditentukan" -- key yang belum
     * ada dibaca sebagai gulungan, sama persis dengan apa yang terjadi sebelum
     * key ini ada.
     */
    public const PAPER_MODE_KEY = 'label.paper_mode';

    /**
     * Blueprint kertas stiker yang dipakai saat mode stiker aktif.
     *
     * Menyimpan preset, bukan angka: Owner tidak boleh mengetik "6 kolom" atau
     * "100 x 150" sendiri, karena angka yang kelili menghasilkan grid yang salah
     * cetak dan tidak ada yang bisa mengukurnya setelah keluar dari printer.
     */
    public const STICKER_SHEET_KEY = 'label.sticker_sheet';

    /**
     * Lebar kertas stiker yang dimuat, dalam milimeter.
     *
     * Ini ukuran media, bukan ukuran label. Label tetap memakai preset 1,5 x
     * 1,5 / 3 x 2 / 4 x 3 cm karena isi label terikat ke ukuran fisiknya;
     * yang boleh bebas hanya kertasnya.
     */
    public const SHEET_MEDIA_WIDTH_MM_KEY = 'label.sheet.media_width_mm';

    public const SHEET_MEDIA_HEIGHT_MM_KEY = 'label.sheet.media_height_mm';

    /**
     * Apakah jarak antar label dipakai.
     *
     * Key tersendiri, bukan cuma celah `0`: Owner mematikan celah pada kertas
     * yang memang tidak punya jarak potong. Angka celahnya tetap tersimpan
     * supaya centangnya bisa dinyalakan lagi tanpa diketik ulang.
     */
    public const SHEET_HAS_GAP_KEY = 'label.sheet.has_gap';

    public const SHEET_GAP_MM_KEY = 'label.sheet.gap_mm';

    /**
     * Lebar cetak maksimal printer label, dalam milimeter.
     *
     * Beda dari lebar kertas: kertas 118 mm masih bisa dimuat ke BP-TD110BT,
     * tapi hanya 108 mm yang bisa dicetak. Tanpa key ini, kertas yang melebar
     * akan lolos ke printer dan kolom paling kanan keluar terpotong.
     */
    public const MAX_PRINT_WIDTH_MM_KEY = 'label.printer.max_print_width_mm';

    /**
     * Cara label dikirim ke printer: dialog browser, atau langsung (TSPL).
     *
     * Default-nya `Browser`, satu-satunya cara yang dipakai sistem sebelum
     * jalur ini ada -- instalasi lama tidak boleh berubah hanya karena Owner
     * membuka halaman Perangkat. Key yang belum ada dibaca sebagai `Browser`.
     */
    public const PRINT_METHOD_KEY = 'label.print.method';

    /**
     * Sisi QR terkecil yang masih bisa discan.
     *
     * Angka ini bukan asal. Pada payload 15 byte, QR versi 1 = 21 modul + 8
     * quiet zone = 29 modul. 0,60 cm = 6 mm, jadi 6 / 29 = 0,207 mm per
     * modul, atau 1,7 dot pada printer 203 dpi. Pindai dan printer thermal
     * injeksi berhenti andal di kisaran 2 dot per modul; di bawah itu QR yang
     * terlihat utuh tetap tidak terbaca.
     *
     * Karena itu 0,60 cm adalah batas bawah yang masih layak ditawarkan:
     * label tetap tercetak, dan kalau ternyata tidak kebaca, angka yang perlu
     * dinaikkan adalah ini, bukan yang diturunkan.
     */
    public const MIN_QR_SIDE_CM = 0.60;

    /**
     * Sisi QR terbesar yang masih muat di label terbesar (4x3).
     *
     * Di atas ini QR pasti melewati tepi stiker pada setiap template, jadi
     * batasnya di sini lebih tinggi dari kebutuhan sesaat -- yang penting
     * penolakannya terjadi di layar pengaturan, bukan di printer.
     */
    public const MAX_QR_SIDE_CM = 2.00;

    /**
     * @var array<string, mixed>
     */
    private array $cache;

    /**
     * Peringatan kalau sisi QR Owner diabaikan karena tidak muat label aktif.
     *
     * Jalur ini bisa terjadi dari `Setting` yang sudah tersimpan: Owner pernah
     * menyimpan sisi QR 1,84 cm untuk label 4x3, lalu label default-nya diganti
     * ke 1,5 cm lewat jalur yang tidak memvalidasi ulang. `LabelGeometry` lalu
     * memakai angka bawaannya 1,38 cm -- dan tanpa pelaporan, angka 1,84 tetap
     * terlihat "aktif" di halaman Pengaturan padahal tidak berlaku. Tidak ada
     * yang tahu sampai QR di rak sulit dibaca dan tidak ada jejaknya.
     *
     * Dipanggil dari `qrSideCm()`, jadi hanya saat nilai override benar-benar
     * terbaca dan diabaikan -- bukan setiap render.
     */
    private function warnIfQrIsIgnored(LabelTemplate $template, float $qrSideCm): void
    {
        $geometry = LabelGeometry::forSku($template);
        $largestSide = min($geometry->widthCm, $geometry->heightCm) - (2 * $geometry->paddingCm);

        if ($geometry->qrSideCm <= 0.0 || $qrSideCm <= $largestSide) {
            return;
        }

        Log::warning('Sisi QR Owner diabaikan karena tidak muat di label aktif.', [
            'requested_cm' => $qrSideCm,
            'largest_side_cm' => $largestSide,
            'effective_qr_cm' => $geometry->qrSideCm,
            'label_cm' => sprintf('%.1fx%.1f', $geometry->widthCm, $geometry->heightCm),
            'template' => $template->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $preloaded  nilai yang dianggap sudah dibaca
     *
     * Parameter ini ada untuk test unit dan untuk pemanggil yang sudah tahu
     * nilainya. Nilainya langsung dianggap sudah dibaca, jadi kelas yang
     * memakainya tidak perlu menyiapkan database hanya untuk dua angka ini.
     */
    public function __construct(array $preloaded = [])
    {
        $this->cache = $preloaded;
    }

    /**
     * Template bawaan untuk label yang dibuat tanpa template eksplisit.
     */
    public function defaultTemplate(): LabelTemplate
    {
        $stored = $this->get(self::DEFAULT_TEMPLATE_KEY);

        if (! is_string($stored)) {
            return LabelTemplate::default();
        }

        return LabelTemplate::tryFrom($stored) ?? LabelTemplate::default();
    }

    /**
     * Mode kertas yang sedang berlaku.
     *
     * Bawaannya `Roll`, bukan `Sheet`. Semua printer yang sudah dipakai sebelum
     * fitur ini ada berjalan di mode gulungan, jadi bawaan yang aman adalah mode
     * yang sudah berjalan -- bukan mode yang lebih FOREIGN tapi belum terbukti.
     */
    public function paperMode(): LabelPaperMode
    {
        $stored = $this->get(self::PAPER_MODE_KEY);

        if (! is_string($stored)) {
            return LabelPaperMode::Roll;
        }

        return LabelPaperMode::tryFrom($stored) ?? LabelPaperMode::Roll;
    }

    /**
     * Cara label dikirim ke printer yang sedang berlaku.
     *
     * Bawaannya `Browser`: sebelum key ini ada, satu-satunya jalur label adalah
     * dialog cetak browser, dan instalasi lama tidak boleh berubah hanya karena
     * halaman Perangkat dibuka pertama kali. Nilai yang tersimpan tidak dikenal
     * (salah ketik, nilai lama) diperlakukan sama, dengan log supaya penyebabnya
     * bisa ditemukan -- bukan diam-diam.
     */
    public function printMethod(): PrintMethod
    {
        $stored = $this->get(self::PRINT_METHOD_KEY);

        if (! is_string($stored)) {
            return PrintMethod::Browser;
        }

        $method = PrintMethod::tryFrom($stored);

        if ($method === null) {
            Log::warning('Cara cetak label tidak dikenal, memakai browser.', [
                'key' => self::PRINT_METHOD_KEY,
                'stored' => $stored,
            ]);

            return PrintMethod::Browser;
        }

        return $method;
    }

    /**
     * Blueprint kertas stiker yang tersimpan, atau `null` kalau mode gulungan.
     *
     * `null` berarti "tidak ada grid yang perlu dihitung", jadi pemanggil tidak
     * perlu memeriksa mode-nya sendiri sebelum bekerja dengan geometri.
     */
    public function stickerSheet(): ?StickerSheet
    {
        if ($this->paperMode() !== LabelPaperMode::Sheet) {
            return null;
        }

        $stored = $this->get(self::STICKER_SHEET_KEY);

        if (! is_string($stored)) {
            return StickerSheet::default();
        }

        $sheet = StickerSheet::tryFrom($stored);

        if ($sheet === null) {
            Log::warning('Blueprint stiker label tidak dikenal, memakai bawaan.', [
                'key' => self::STICKER_SHEET_KEY,
                'stored' => $stored,
                'fallback' => StickerSheet::default()->value,
            ]);

            return StickerSheet::default();
        }

        return $sheet;
    }

    /**
     * Ukuran kertas, celah, dan batas cetak printer yang berlaku.
     *
     * Inilah sumber angka untuk `SheetGridCalculator`: form pengaturan,
     * pratinjau, dan halaman cetak semuanya lewat sini, jadi tidak ada tempat
     * lain yang bebas menebak ukuran kertas.
     *
     * Pembacaannya tidak pernah melempar dan tidak pernah menulis. Nilai yang
     * tidak masuk akal dijatuhkan ke bawaan dengan peringatan di log, karena
     * `Setting` bisa berisi apa saja -- termasuk angka yang diisi manual atau
     * sisa versi lama.
     */
    public function sheet(): LabelSheetSettings
    {
        $stored = $this->storedMany([
            self::SHEET_MEDIA_WIDTH_MM_KEY => null,
            self::SHEET_MEDIA_HEIGHT_MM_KEY => null,
            self::SHEET_HAS_GAP_KEY => null,
            self::SHEET_GAP_MM_KEY => null,
            self::MAX_PRINT_WIDTH_MM_KEY => null,
        ]);

        if ($this->hasStoredSheetKey($stored)) {
            return $this->sheetFromStoredKeys($stored);
        }

        return $this->sheetFromStoredBlueprint();
    }

    /**
     * Susun pengaturan dari key baru, satu per satu dengan bawaannya sendiri.
     *
     * Diper-key, bukan "kalau semua key ada baru pakai": form menyimpan
     * kelimanya sekaligus, tapi tetap ada kemungkinan hanya sebagian yang
     * tersimpan -- dan_install yang sudah berjalan saat sebagian key ditambahkan
     * tidak boleh kehilangan seluruh pengaturan yang sudah benar.
     *
     * @param  array<string, mixed>  $stored
     */
    private function sheetFromStoredKeys(array $stored): LabelSheetSettings
    {
        $defaults = LabelSheetSettings::default();

        return new LabelSheetSettings(
            mediaWidthMm: $this->mmOr($stored[self::SHEET_MEDIA_WIDTH_MM_KEY], $defaults->mediaWidthMm, self::SHEET_MEDIA_WIDTH_MM_KEY),
            mediaHeightMm: $this->mmOr($stored[self::SHEET_MEDIA_HEIGHT_MM_KEY], $defaults->mediaHeightMm, self::SHEET_MEDIA_HEIGHT_MM_KEY),
            hasGap: $this->boolOr($stored[self::SHEET_HAS_GAP_KEY], $defaults->hasGap, self::SHEET_HAS_GAP_KEY),
            gapMm: $this->mmOr($stored[self::SHEET_GAP_MM_KEY], $defaults->gapMm, self::SHEET_GAP_MM_KEY, allowZero: true),
            maxPrintWidthMm: $this->mmOr($stored[self::MAX_PRINT_WIDTH_MM_KEY], $defaults->maxPrintWidthMm, self::MAX_PRINT_WIDTH_MM_KEY),
        );
    }

    /**
     * Pengaturan dari blueprint lama yang masih tersimpan.
     *
     * Installed yang sudah memilih kertas stiker sebelum key baru ada punya
     * `label.sticker_sheet` dan tidak punya satu pun key baru. Angkanya
     * diturunkan dari blueprint itu apa adanya -- hasilnya persis sama dengan
     * grid yang sudah tercetak sebelumnya, jadi migrasi tidak diam-diam
     * mengubah jumlah label per halaman.
     *
     * Dicatat di log karena ini transisi sekali jalan: begitu Owner menyimpan
     * lewat form, key baru menggantikan blueprint lama dan baris ini tidak
     * pernah tersentuh lagi.
     */
    private function sheetFromStoredBlueprint(): LabelSheetSettings
    {
        $stored = $this->get(self::STICKER_SHEET_KEY);
        $blueprint = is_string($stored) ? StickerSheet::tryFrom($stored) : null;

        if ($blueprint === null) {
            return LabelSheetSettings::default();
        }

        Log::info('Blueprint stiker label diterjemahkan ke ukuran kertas.', [
            'deprecated_key' => self::STICKER_SHEET_KEY,
            'blueprint' => $blueprint->value,
            'media_width_mm' => $blueprint->mediaWidthMm(),
            'media_height_mm' => $blueprint->mediaHeightMm(),
            'gap_mm' => $blueprint->gapMm(),
        ]);

        return LabelSheetSettings::fromBlueprint($blueprint);
    }

    /**
     * Apakah ada key baru yang benar-benar tersimpan.
     *
     * Yang diperiksa bukan `! empty()`, tapi apakah ada isinya: baris `Setting`
     * bisa ada dengan nilai `null`, dan itu tetap berarti Owner belum pernah
     * menyimpan pengaturan kertas.
     *
     * @param  array<string, mixed>  $stored
     */
    private function hasStoredSheetKey(array $stored): bool
    {
        foreach ($stored as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Baca beberapa key sekaligus dengan satu query.
     *
     * `get()` baik untuk satu key, tapi sheet ini lima key -- lima query untuk
     * lima angka yang selalu dibutuhkan bersamaan. Nilai yang sudah dipreload
     * tetap menang, supaya test unit dan pemanggil yang sudah tahu nilainya
     * tidak perlu menyiapkan database.
     *
     * @param  array<string, mixed>  $keys  key => null
     * @return array<string, mixed>
     */
    private function storedMany(array $keys): array
    {
        return array_replace(
            Setting::many($keys),
            array_intersect_key($this->cache, $keys),
        );
    }

    /**
     * Angka milimeter yang bisa dipakai, atau bawaannya kalau tidak.
     *
     * Nol dan negatif ditolak karena keduanya tidak pernah jadi pilihan Owner:
     * nol berarti "tidak diisi", dan negatif berarti tanda ketik. `allowZero`
     * hanya untuk celah, karena 0 memang jawaban yang sah di situ -- Owner bisa
     * menulis "0" alih-alih mematikan centang.
     *
     * DIBULATKAN ke tiga angka desimal. Angka mm yang sampai ke sini sudah
     * melewati input teks dan aritmetika float, dan sisa-sisa pecahan kecil itu
     * akan memunculkan peringatan "bukan kelipatan 1 dot" untuk selisih yang
     * tidak akan pernah terlihat di printer.
     */
    private function mmOr(mixed $stored, float $default, string $key, bool $allowZero = false): float
    {
        if ($stored === null) {
            return $default;
        }

        if (! is_numeric($stored)) {
            $this->warnUnreadableSetting($key, $stored, $default);

            return $default;
        }

        $value = (float) $stored;

        if (! is_finite($value) || $value < 0.0 || ($value === 0.0 && ! $allowZero)) {
            $this->warnUnreadableSetting($key, $stored, $default);

            return $default;
        }

        return round($value, 3);
    }

    /**
     * Centang boolean dari storage, atau bawaannya kalau tidak terbaca.
     *
     * Menerima bentuk yang bisa muncul di `Setting`: boolean asli, `1`/`0`,
     * dan string `"1"`/`"0"`/`"true"`/`"false"`. Bentuk lain ditolak, karena
     * tebakan di sini menentukan apakah label dicetak rapat atau berjarak --
     * dua hasil yang sangat berbeda dari satu centang.
     */
    private function boolOr(mixed $stored, bool $default, string $key): bool
    {
        if ($stored === null) {
            return $default;
        }

        if (is_bool($stored)) {
            return $stored;
        }

        $normalized = is_scalar($stored) ? strtolower(trim((string) $stored)) : null;

        if ($normalized === '1' || $normalized === 'true') {
            return true;
        }

        if ($normalized === '0' || $normalized === 'false') {
            return false;
        }

        $this->warnUnreadableSetting($key, $stored, $default);

        return $default;
    }

    /**
     * Catat setelan yang tidak bisa dibaca, lalu kembalikan bawaannya.
     *
     * Peringatan, bukan exception: halaman Pengaturan dan halaman cetak harus
     * tetap terbuka. Tanpa catatan ini, angka yang ditolak hilang begitu saja
     * dan Owner hanya melihat grid bawaan yang tidak pernah ia pilih.
     */
    private function warnUnreadableSetting(string $key, mixed $stored, float|bool $default): void
    {
        Log::warning('Setelan printer label tidak bisa dibaca, memakai bawaan.', [
            'key' => $key,
            'stored' => $stored,
            'fallback' => $default,
        ]);
    }

    /**
     * Grid label di atas kertas untuk template aktif, atau `null` kalau labelnya
     * tidak boleh digabung ke kertas.
     *
     * Ini satu-satunya pertanyaan yang perlu dijawab controller: kalau hasilnya
     * `null`, label ditata seperti biasa satu per halaman; kalau bukan `null`,
     * angka di dalamnya yang menentukan `@page`, jumlah kolom, dan pembulatan
     * halaman. Controller tidak pernah membaca angka kertas atau mode kertas
     * langsung, jadi semua keputusan itu berpusat di sini.
     *
     * Angkanya berasal dari `sheet()`, jadi preset kertas lama ikut terbaca lewat
     * `sheetFromStoredBlueprint()`. Cetakan tidak pernah membaca
     * `label.sticker_sheet` secara langsung -- kalau blueprint lama dan angka
     * baru punya jawaban berbeda, yang menang tetap angka baru.
     *
     * Grid yang ditolak menghasilkan `null` dengan satu peringatan di log yang
     * menyebut angkanya, bukan cuma_template_. Degradasinya aman: label tetap
     * dicetak pada ukuran yang benar, cuma tidak digabung ke grid. Yang penting
     * bukan membuat halaman crawl atau label menimpa stiker tetangga karena
     * dipaksa ke dalam kolom yang lebih sempit.
     */
    public function sheetGrid(LabelTemplate $template): ?SheetGrid
    {
        if ($this->paperMode() !== LabelPaperMode::Sheet) {
            return null;
        }

        $sheet = $this->sheet();
        $grid = $sheet->usableGridFor($template);

        if ($grid !== null) {
            return $grid;
        }

        Log::warning('Mode stiker diabaikan karena ukuran kertas tidak bisa dipakai untuk label aktif.', [
            'template' => $template->value,
            'media_width_mm' => $sheet->mediaWidthMm,
            'media_height_mm' => $sheet->mediaHeightMm,
            'gap_mm' => $sheet->effectiveGapMm(),
            'max_print_width_mm' => $sheet->maxPrintWidthMm,
            'rejections' => $sheet->rejectionsFor($template),
        ]);

        return null;
    }

    /**
     * Keputusan tata letak untuk satu cetakan.
     *
     * Dipakai controller, bukan `sheetGrid()` langsung, supaya setiap halaman
     * cetak menanyakan satu hal ("cetak ini mau bagaimana?") dan tidak perlu
     * tahu apa mode kertas atau bagaimana `@page` ditentukan.
     */
    public function layoutFor(LabelTemplate $template): LabelPaperLayout
    {
        return new LabelPaperLayout($template, $this->sheetGrid($template));
    }

    /**
     * Sisi QR yang diminta Owner, atau `null` untuk memakai angka bawaan
     * geometri.
     *
     * `null` berarti "biarkan yang pakai bawaan", dan itu beda dari angka
     * 0,60: nilai 0,60 tetap berarti QR eksplisit sekecil itu. Karena itu
     * bentuknya tidak ambigu.
     */
    public function qrSideCm(): ?float
    {
        $stored = $this->get(self::QR_SIDE_KEY);

        if (! is_numeric($stored)) {
            return null;
        }

        $value = (float) $stored;

        if ($value < self::MIN_QR_SIDE_CM || $value > self::MAX_QR_SIDE_CM) {
            return null;
        }

        $this->warnIfQrIsIgnored($this->defaultTemplate(), round($value, 2));

        return round($value, 2);
    }

    /**
     * Ditolak kalau QR yang diminta tidak muat di template yang dipilih.
     *
     * Ini yang menahan form bebas: tanpa pemeriksaan ini, Owner bisa menyimpan
     * QR 2 cm untuk label 1,5 cm, dan label yang keluar dari printer punya QR
     * terpotong tepat di sisi yang paling sering gagal discan.
     *
     * @return string|null pesan error, atau `null` kalau angkanya valid
     */
    public function rejectIfQrDoesNotFit(float $qrSideCm, LabelTemplate $template): ?string
    {
        $geometry = LabelGeometry::forSku($template);
        $needed = $qrSideCm + (2 * $geometry->paddingCm);

        if ($needed > min($geometry->widthCm, $geometry->heightCm)) {
            return sprintf(
                'QR %.2f cm tidak muat di label %.1f x %.1f cm. Label itu hanya menyediakan %.2f cm '
                .'untuk QR setelah padding %.2f cm di kedua sisi.',
                $qrSideCm,
                $geometry->widthCm,
                $geometry->heightCm,
                min($geometry->widthCm, $geometry->heightCm) - (2 * $geometry->paddingCm),
                $geometry->paddingCm,
            );
        }

        return $this->rejectIfTextBecomesUnreadable($qrSideCm, $template);
    }

    /**
     * Tolak QR yang muat di label tapi merusak teksnya.
     *
     * Pemeriksaan "QR muat" saja tidak cukup. Pada label 3x2 cm, QR 1,50 cm
     * masih muat dengan lega -- tapi kolom teksnya tinggal 1,24 cm dan baris
     * harga langsung turun ke 9 karakter. `Rp100.000.000` lalu tercetak
     * `Rp100.0...`: angka yang salah, bukan sekadar kurang terbaca.
     * Pemeriksaan yang dulu ada tidak melihat ini sama sekali, jadi Owner bisa
     * menyimpan angka yang merusak label tanpa satu pun peringatan.
     *
     * Yang diperiksa bukan cuma SKU. Awalnya hanya SKU yang diawasi, padahal
     * pada 3x2 harga yang lebih dulu rusak: SKU masih muat 15 karakter di QR
     * 1,30 cm, sementara harga sudah rusak sejak 1,10 cm. Kalau cuma SKU yang
     * dijaga, label tetap bisa tercetak dengan harga yang salah.
     *
     * Karena itu yang diperiksa setiap baris, di template yang memuat QR --
     * label barang dan label rak punya baris yang berbeda, jadi keduanya
     * diperiksa dengan sisi QR yang sama.
     *
     * @return string|null pesan error, atau `null` kalau angkanya valid
     */
    private function rejectIfTextBecomesUnreadable(float $qrSideCm, LabelTemplate $template): ?string
    {
        $geometries = [
            LabelGeometry::forSku($template, $qrSideCm),
            LabelGeometry::forRack($template, $qrSideCm),
        ];

        foreach ($geometries as $geometry) {
            foreach ($geometry->rows as $index => $row) {
                $needed = self::worstCaseLengthFor($geometry, $index);
                $capacity = $geometry->capacityFor($row);

                if ($capacity < $needed) {
                    return sprintf(
                        'QR %.2f cm membuat baris %s di label %.1f x %.1f cm hanya memuat %d karakter, '
                        .'padahal isinya bisa %d karakter. Teks akan tercetak terpotong -- untuk harga itu '
                        .'berarti angka yang salah. Turunkan sisi QR, atau pakai label yang lebih besar.',
                        $qrSideCm,
                        $row->caption !== '' ? $row->caption : sprintf('ke-%d', $index + 1),
                        $geometry->widthCm,
                        $geometry->heightCm,
                        $capacity,
                        $needed,
                    );
                }
            }
        }

        return null;
    }

    /**
     * Panjang isi terburuk untuk baris tertentu pada geometry tertentu.
     *
     * Baris berbeda antara template: 3x2 menggabung kondisi dan pemilik jadi
     * satu baris, 4x3 memisahkannya. Label rak punya isinya sendiri, yang
     * batasnya `LONGEST_RACK_CODE`.
     *
     * Panjang 5 karakter untuk kondisi dan pemilik terpisah itu
     * "NM/CL" dan "CN999" -- pasangan terpanjang di masing-masing kolom.
     */
    private static function worstCaseLengthFor(LabelGeometry $geometry, int $index): int
    {
        $values = LabelGeometry::WORST_CASE_VALUES;

        $isRack = ($geometry->rows[0]->caption ?? '') === 'RAK';

        if ($isRack) {
            return LabelGeometry::LONGEST_RACK_CODE;
        }

        // 3x2 punya 4 baris (kondisi dan pemilik digabung), 4x3 punya 5 baris
        // (kondisi dan pemilik dipisah). Jumlah baris itulah penandanya, bukan
        // nilai caption, karena baris tanpa keterangan tidak bisa dibedakan dari
        // caption.
        $isCompact = count($geometry->rows) === 4;

        return match ($index) {
            0 => $values['sku'],
            1 => $values['product'],
            2 => $isCompact ? $values['condition'] : 5,
            3 => $isCompact ? $values['price'] : 5,
            default => $values['price'],
        };
    }

    private function get(string $key): mixed
    {
        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = Setting::get($key);
        }

        return $this->cache[$key];
    }
}
