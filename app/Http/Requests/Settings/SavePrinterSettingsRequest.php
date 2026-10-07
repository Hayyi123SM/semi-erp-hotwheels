<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\LabelPaperMode;
use App\Enums\PrintMethod;
use App\Services\Label\LabelPrinterSettings;
use App\Services\Label\LabelSheetSettings;
use App\Services\Label\LabelTemplate;
use App\Services\Label\SheetGrid;
use App\Services\Label\SheetGridCalculator;
use App\Services\Label\StickerSheet;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Simpan pengaturan printer label dari halaman Perangkat.
 *
 * Yang divalidasi bukan hanya rentang angka. Ukuran QR dan ukuran label punya
 * hubungan yang hanya bisa diperiksa lewat geometry nyata: QR 1,5 cm masuk di
 * label 4x3, tapi memotong tepi label 1,5 cm.
 *
 * Hubungan ketiga punya sifat yang sama, dan lebih sering jadi masalah: Owner
 * mengetik lebar kertas yang lebih besar dari area cetak printer, atau celah
 * yang lebih besar dari labelnya sendiri.
 *
 * Semua hubungan itu dicek lewat `SheetGridCalculator` yang sama dengan halaman
 * cetak, jadi pengaturan yang lolos di sini dijamin bisa dicetak.
 *
 * Pemeriksaan itu ada karena angka yang lolos validasi rentang masih bisa
 * menghasilkan label tercetak terpotong, dan itu baru ditemukan operator di
 * depan printer setelah lotnya sudah tanpa label. Jadi penolakan terjadi di
 * layar pengaturan, dengan angka yang bisa langsung ditindaklanjuti.
 */
class SavePrinterSettingsRequest extends FormRequest
{
    /**
     * Batas bawah ukuran kertas, dalam milimeter.
     *
     * Ada hanya untuk menangkap ketik yang salah. Angka 10 mm bukan kertas
     * stiker yang masuk akal -- tujuannya hanya supaya "0" atau digit yang
     * tertinggal tidak lolos ke kalkulator.
     */
    public const int MIN_MEDIA_MM = 10;

    /**
     * Batas atas ukuran kertas, dalam milimeter.
     *
     * 500 mm sudah jauh melebihi kertas stiker terbesar yang dipakai printer
     * label, dan tidak melewati batas angka yang enak dibaca. Batas sesungguhnya
     * ada di `LabelSheetSettings`: lebar kertas tidak boleh melebihi area cetak
     * printer.
     */
    public const int MAX_MEDIA_MM = 500;

    /**
     * Kolom form untuk setiap setelan yang bisa ditolak kalkulator.
     *
     * Peta ini milik form, bukan milik kalkulator: kalkulator tidak tahu apa
     * itu kolom input, dan tidak perlu tahu. Sebagai gantinya penolakannya
     * membawa nama setelannya, jadi pemetaan ke kolom cukup satu tabel.
     *
     * `default_template` untuk label: ukuran label dipilih dari preset, jadi
     * kalau label yang terlalu besar untuk kertas yang dipilih, satu-satunya
     * yang bisa diperbaiki Owner dari sisi itu adalah memilih label lain.
     *
     * Publik karena dipakai juga oleh endpoint preview grid: preview dan simpan
     * harus menunjuk kolom yang sama, jadi petanya harus satu.
     */
    public const array SHEET_FIELD_BY_SETTING = [
        SheetGridCalculator::REJECTION_MEDIA_WIDTH_MM => 'sheet_media_width_mm',
        SheetGridCalculator::REJECTION_MEDIA_HEIGHT_MM => 'sheet_media_height_mm',
        SheetGridCalculator::REJECTION_GAP_MM => 'sheet_gap_mm',
        SheetGridCalculator::REJECTION_LABEL_MM => 'default_template',
    ];

    public function rules(): array
    {
        /**
         * Ukuran kertas dan celah hanya bermakna di mode stiker.
         *
         * Di mode gulungan tidak ada grid, jadi kolomnya diabaikan seluruhnya
         * -- bukan hanya tidak diwajibkan. Memeriksa angkanya di mode gulungan
         * akan menolak penyimpanan yang sebenarnya sah, hanya karena Owner
         * membiarkan browser mengisi kolom yang memang tidak dia pakai.
         */
        $sheetMode = $this->isSheetMode();
        $gapUsed = $sheetMode && $this->hasGap();

        return [
            'default_template' => ['required', Rule::in($this->templateValues())],
            'qr_side_cm' => [
                'nullable',
                'numeric',
                'min:'.LabelPrinterSettings::MIN_QR_SIDE_CM,
                'max:'.LabelPrinterSettings::MAX_QR_SIDE_CM,
            ],
            'paper_mode' => ['required', Rule::in($this->paperModeValues())],
            /**
             * Cara mengirim label ke printer, opsional.
             *
             * `null` berarti jangan sentuh key yang tersimpan: form versi lama
             * tidak mengirim kolom ini, dan memaksakan `browser` di sini akan
             * menimpa pilihan thermal milik instalasi yang sudah menyimpannya.
             * Nilai yang tidak dikenal tetap ditolak, bukan diabaikan.
             */
            'print_method' => ['nullable', Rule::in($this->printMethodValues())],
            /**
             * Blueprint lama sudah tidak wajib dan hanya dibaca kalau masih
             * dikirim.
             *
             * Dulu kolom ini wajib di mode stiker. Sekarang ukuran kertas dan
             * celah yang wajib, dan blueprint digantikan oleh angka-angka itu.
             * Menetapkannya wajib akan membuat Owner menyimpan ukuran kertas
             * yang sudah benar lalu ditolak karena kolom yang tidak lagi dipakai
             * masih kosong.
             *
             * Aturannya dibiarkan lengkap supaya form lama atau request yang
             * masih mengirim preset tidak ditolak diam-diam: nilai yang tidak
             * dikenal tetap ditolak, bukan diabaikan.
             */
            'sticker_sheet' => [
                'nullable',
                Rule::in($this->stickerSheetValues()),
            ],
            // Kelima kolom ini menggantikan `sticker_sheet`: Owner mengetik
            // ukuran kertas dan celah, dan jumlah labelnya dihitung.
            'sheet_media_width_mm' => $sheetMode ? [
                'required',
                'numeric',
                'min:'.self::MIN_MEDIA_MM,
                'max:'.self::MAX_MEDIA_MM,
            ] : ['nullable'],
            'sheet_media_height_mm' => $sheetMode ? [
                'required',
                'numeric',
                'min:'.self::MIN_MEDIA_MM,
                'max:'.self::MAX_MEDIA_MM,
            ] : ['nullable'],
            'sheet_has_gap' => ['nullable', 'boolean'],
            // Celah hanya wajib saat centangnya menyala. Angka pada kolom yang
            // tidak dipakai tidak boleh menghalangi Owner menyimpan, karena
            // isinya sudah tidak berpengaruh ke grid.
            'sheet_gap_mm' => $gapUsed ? [
                'required',
                'numeric',
                'min:'.SheetGridCalculator::MIN_GAP_MM,
                'max:'.SheetGridCalculator::MAX_GAP_MM,
            ] : ['nullable'],
            // Batas cetak printer berlaku di kedua mode, jadi kolomnya tidak
            // ikut bergantung pada mode kertas.
            'max_print_width_mm' => [
                'nullable',
                'numeric',
                'min:'.self::MIN_MEDIA_MM,
                'max:'.self::MAX_MEDIA_MM,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'default_template.required' => 'Pilih ukuran label bawaan.',
            'default_template.in' => 'Ukuran label tidak dikenal.',
            'qr_side_cm.numeric' => 'Sisi QR harus berupa angka dalam sentimeter.',
            'qr_side_cm.min' => sprintf(
                'Sisi QR minimal %s cm. Di bawah itu QR tidak bisa discan pada printer 203 dpi.',
                number_format(LabelPrinterSettings::MIN_QR_SIDE_CM, 2, ',', '.'),
            ),
            'qr_side_cm.max' => sprintf(
                'Sisi QR maksimal %s cm.',
                number_format(LabelPrinterSettings::MAX_QR_SIDE_CM, 2, ',', '.'),
            ),
            'paper_mode.required' => 'Pilih mode kertas.',
            'paper_mode.in' => 'Mode kertas tidak dikenal.',
            'print_method.in' => 'Cara mencetak label tidak dikenal.',
            'sticker_sheet.in' => 'Tipe kertas stiker tidak dikenal.',
            'sheet_media_width_mm.required' => 'Isi lebar kertas stiker.',
            'sheet_media_width_mm.numeric' => 'Lebar kertas harus berupa angka dalam milimeter.',
            'sheet_media_width_mm.min' => sprintf(
                'Lebar kertas minimal %s mm.',
                SheetGrid::mm(self::MIN_MEDIA_MM),
            ),
            'sheet_media_width_mm.max' => sprintf(
                'Lebar kertas maksimal %s mm. Kertas yang lebih lebar tidak bisa dicetak printer label.',
                SheetGrid::mm(self::MAX_MEDIA_MM),
            ),
            'sheet_media_height_mm.required' => 'Isi tinggi kertas stiker.',
            'sheet_media_height_mm.numeric' => 'Tinggi kertas harus berupa angka dalam milimeter.',
            'sheet_media_height_mm.min' => sprintf(
                'Tinggi kertas minimal %s mm.',
                SheetGrid::mm(self::MIN_MEDIA_MM),
            ),
            'sheet_media_height_mm.max' => sprintf(
                'Tinggi kertas maksimal %s mm.',
                SheetGrid::mm(self::MAX_MEDIA_MM),
            ),
            'sheet_gap_mm.required' => 'Isi celahnya, atau matikan centang celah.',
            'sheet_gap_mm.numeric' => 'Celah harus berupa angka dalam milimeter.',
            'sheet_gap_mm.min' => sprintf(
                'Celah minimal %s mm. Di bawah itu printer 203 dpi membulatkan, jadi jaraknya bukan lagi '
                .'jarak yang kamu tulis.',
                SheetGrid::mm(SheetGridCalculator::MIN_GAP_MM),
            ),
            'sheet_gap_mm.max' => sprintf(
                'Celah maksimal %s mm. Celah sebesar itu hampir selalu berarti ada angka lain yang keliru.',
                SheetGrid::mm(SheetGridCalculator::MAX_GAP_MM),
            ),
            'max_print_width_mm.numeric' => 'Lebar cetak printer harus berupa angka dalam milimeter.',
            'max_print_width_mm.min' => sprintf(
                'Lebar cetak printer minimal %s mm.',
                SheetGrid::mm(self::MIN_MEDIA_MM),
            ),
            'max_print_width_mm.max' => sprintf(
                'Lebar cetak printer maksimal %s mm.',
                SheetGrid::mm(self::MAX_MEDIA_MM),
            ),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->checkQrFitsTemplate($validator);
            $this->checkSheetGrid($validator);
        });
    }

    public function defaultTemplate(): LabelTemplate
    {
        return LabelTemplate::parse((string) $this->validated('default_template'));
    }

    public function paperMode(): LabelPaperMode
    {
        return LabelPaperMode::parse((string) $this->validated('paper_mode'));
    }

    /**
     * Blueprint lama yang masih dikirim, atau `null` kalau tidak ada.
     *
     * `null` berarti jangan sentuh key lama: form baru tidak mengirim kolom ini
     * sama sekali, dan memaksa nilai bawaan di sini akan menimpa blueprint lama
     * milik instalasi yang masih memakainya.
     *
     * Nilai yang tidak dikenal tidak ikut di sini -- `Rule::in` sudah menolaknya
     * sebelum accessor ini dipanggil.
     */
    public function stickerSheet(): ?StickerSheet
    {
        if ($this->paperMode() !== LabelPaperMode::Sheet) {
            return null;
        }

        $value = $this->validated('sticker_sheet');

        if (! is_string($value) || $value === '') {
            return null;
        }

        return StickerSheet::tryFrom($value);
    }

    /**
     * Ukuran kertas dan celah yang diminta, atau `null` untuk mode gulungan.
     *
     * `null` berarti jangan sentuh pengaturan kertas. Mode gulungan tidak punya
     * grid, jadi menyimpan angka stiker saat mode itu hanya menambah data yang
     * tidak dipakai dan tidak bisa dijelaskan dari audit.
     */
    public function sheet(): ?LabelSheetSettings
    {
        if ($this->paperMode() !== LabelPaperMode::Sheet) {
            return null;
        }

        $current = app(LabelPrinterSettings::class)->sheet();

        return new LabelSheetSettings(
            mediaWidthMm: (float) $this->validated('sheet_media_width_mm'),
            mediaHeightMm: (float) $this->validated('sheet_media_height_mm'),
            hasGap: $this->hasGap(),
            gapMm: (float) ($this->validated('sheet_gap_mm') ?? $current->gapMm),
            maxPrintWidthMm: $this->maxPrintWidthMm() ?? $current->maxPrintWidthMm,
        );
    }

    /**
     * Apakah Owner menyalakan celah.
     *
     * Checkbox HTML tidak mengirim apa pun kalau tidak dicentang, jadi "tidak
     * ada di request" sama dengan "tidak dipakai celah". `boolean()` yang
     * dipakai di sini sudah memahami `1`, `on`, dan `true`.
     */
    public function hasGap(): bool
    {
        return $this->boolean('sheet_has_gap');
    }

    /**
     * Apakah request ini bermaksud memakai kertas stiker.
     *
     * Dibaca dari input apa adanya, bukan lewat `paperMode()`: `paperMode()`
     * melempar pada nilai yang tidak dikenal, sedangkan `rules()` harus tetap
     * bisa disusun supaya aturan `Rule::in` yang bekerja.
     */
    private function isSheetMode(): bool
    {
        return LabelPaperMode::tryFrom((string) $this->input('paper_mode')) === LabelPaperMode::Sheet;
    }

    /**
     * Lebar cetak printer yang diminta, atau `null` kalau tidak dikirim.
     *
     * Beda dari kolom-kolom lain di form ini: batas cetak printer berlaku di
     * kedua mode, jadi kolomnya tidak ikut wajib saat mode stiker. Kalau tidak
     * dikirim, nilai yang tersimpan dipakai -- dan bukan bawaan, supaya Owner
     * yang punya printer 216 mm tidak dikembalikan ke 108 mm hanya karena form
     * versi lama tidak mengirim kolom ini.
     */
    public function maxPrintWidthMm(): ?float
    {
        $value = $this->validated('max_print_width_mm');

        /**
         * Nilai yang bukan angka dikembalikan sebagai `null`, bukan `0`.
         *
         * Aturan `numeric` sudah menolaknya, tapi accessor ini dipanggil juga
         * saat pemeriksaan geometry. Kalau teks salah ketik dibaca `0`,Owner
         * akan melihat "kertas lebih lebar dari area cetak 0 mm" -- complaints
         * yang menunjuk ke kolom yang salah.
         */
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 3);
    }

    /**
     * `null` berarti Owner memilih memakai angka bawaan geometri.
     */
    public function qrSideCm(): ?float
    {
        $value = $this->validated('qr_side_cm');

        // Sama seperti batas cetak printer: teks yang bukan angka berarti
        // "tidak punya nilai", bukan `0 cm`.
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * @return list<string>
     */
    private function templateValues(): array
    {
        return array_map(
            static fn (LabelTemplate $template): string => $template->value,
            LabelTemplate::cases(),
        );
    }

    /**
     * @return list<string>
     */
    private function paperModeValues(): array
    {
        return array_map(
            static fn (LabelPaperMode $mode): string => $mode->value,
            LabelPaperMode::cases(),
        );
    }

    /**
     * @return list<string>
     */
    private function stickerSheetValues(): array
    {
        return array_map(
            static fn (StickerSheet $sheet): string => $sheet->value,
            StickerSheet::cases(),
        );
    }

    /**
     * @return list<string>
     */
    private function printMethodValues(): array
    {
        return array_map(
            static fn (PrintMethod $method): string => $method->value,
            PrintMethod::cases(),
        );
    }

    /**
     * Cara mencetak label yang diminta, atau `null` kalau tidak dikirim.
     *
     * `null` berarti jangan sentuh key yang tersimpan (form lama tidak punya
     * kolom ini). Nilai yang tidak dikenal sudah ditolak `Rule::in`, jadi
     * lewat sini selalu enum yang valid.
     */
    public function printMethod(): ?PrintMethod
    {
        $value = $this->validated('print_method');

        if (! is_string($value) || $value === '') {
            return null;
        }

        return PrintMethod::tryFrom($value);
    }

    /**
     * QR harus muat di label yang dipilih, setelah padding kedua sisi.
     *
     * Yang dibandingkan adalah sisi terpendek label, bukan lebarnya: QR bujur
     * sangkar, dan label 1,5 x 1,5 tidak boleh lolos hanya karena satu sisinya
     * kebetulan cukup.
     */
    private function checkQrFitsTemplate(Validator $validator): void
    {
        $qrSideCm = $this->qrSideCm();

        if ($qrSideCm === null) {
            return;
        }

        $template = LabelTemplate::tryFrom((string) $this->input('default_template'));

        if (! $template instanceof LabelTemplate) {
            return;
        }

        $rejection = app(LabelPrinterSettings::class)->rejectIfQrDoesNotFit($qrSideCm, $template);

        if ($rejection !== null) {
            $validator->errors()->add('qr_side_cm', $rejection);
        }
    }

    /**
     * Grid harus benar-benar bisa dicetak, bukan cuma angkanya dalam rentang.
     *
     * Aturan rentang hanya menahan angka yang mustahil. Hubungan antar angka --
     * label lebih lebar dari kertas, kertas lebih lebar dari area cetak,
     * celah lebih besar dari label -- baru terlihat setelah semua angka
     * terkumpul, dan itu diperiksa di sini lewat kalkulator yang sama dengan
     * yang dipakai halaman cetak.
     *
     * Setiap pesan ditaruh di kolom setelannya, bukan dikumpulkan jadi satu
     * daftar. Lima input dengan satu daftar error berarti Owner harus mencari
     * sendiri mana yang salah.
     *
     * Hanya saat mode stiker: di mode gulungan tidak ada grid, jadi tidak ada
     * yang perlu dicek.
     */
    private function checkSheetGrid(Validator $validator): void
    {
        $mode = LabelPaperMode::tryFrom((string) $this->input('paper_mode'));

        if ($mode !== LabelPaperMode::Sheet) {
            return;
        }

        /**
         * Berhenti kalau angka dasarnya sudah ditolak.
         *
         * Pemeriksaan geometry butuh angka yang sudah lolos aturan rentang.
         * Kalau tidak, angka nol ikut masuk kalkulator dan Owner berakhir
         * dengan dua pesan di kolom yang sama: satu dari aturan rentang, satu
         * dari geometry -- yang kedua justru tidak mungkin diperbaiki.
         */
        if ($validator->errors()->hasAny(array_values(self::SHEET_FIELD_BY_SETTING))) {
            return;
        }

        $template = LabelTemplate::tryFrom((string) $this->input('default_template'));

        if (! $template instanceof LabelTemplate) {
            return;
        }

        $sheet = $this->sheet();

        if ($sheet === null) {
            return;
        }

        foreach ($sheet->rejectionsBySetting($template) as $setting => $messages) {
            $field = self::SHEET_FIELD_BY_SETTING[$setting] ?? null;

            if ($field === null) {
                continue;
            }

            foreach ($messages as $message) {
                $validator->errors()->add($field, $message);
            }
        }
    }
}
