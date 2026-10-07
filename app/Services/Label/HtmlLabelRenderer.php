<?php

declare(strict_types=1);

namespace App\Services\Label;

/**
 * Label thermal sebagai HTML, untuk dicetak lewat dialog browser (Ctrl+P).
 *
 * Ini transport pertama. Ia dipisah dari renderer lain lewat `LabelRenderer`
 * supaya penggantian ke TSPL untuk Print Agent tidak menyentuh kode lain.
 *
 * Tiga aturan yang dipegang di sini:
 *
 * 1. Ukuran label dan ukuran fontnya datang dari `LabelGeometry`, ditulis
 *    sebagai inline style. Kalau ukurannya hanya ada di stylesheet, perubahan
 *    satu aturan CSS bisa membuat teks keluar stiker tanpa ada test yang
 *    gagal -- dan label yang terpotong baru ketahuan setelah keluar printer.
 * 2. Tiap ukuran tata letaknya sendiri. Label 3x2 cm hanya 6 cm persegi;
 *    memaksakan tata letak label 4x3 ke sana membuat teks tergulir keluar.
 * 3. Isi label diambil seluruhnya dari `LabelContent`, bukan dari model. Nama
 *    penitip tidak pernah ada di sini -- hanya `owner_code`.
 */
final class HtmlLabelRenderer implements LabelRenderer
{
    public function __construct(
        private readonly QrCode $qr,
        private readonly LabelPrinterSettings $printer,
    ) {}

    public function render(LabelContent $content, LabelTemplate $template, bool $showPrice = true): string
    {
        $geometry = LabelGeometry::forSku($template, $this->printer->qrSideCm());

        // Layout 3x2 cm tidak memakai keterangan kolom. Kolom teksnya hanya
        // sekitar 1,7 cm; keterangan akan membuat teksnya mengecil sampai
        // tidak terbaca, sedangkan operator sudah tahu apa arti barcode,
        // kondisi, dan harga.
        $compact = $template === LabelTemplate::ThreeByTwo;

        // QR-only: `$geometry->rows` kosong, jadi loop di bawah tidak jalan dan
        // tidak ada teks sama sekali yang bisa bocor ke label. Yang perlu
        // ditangani hanya kelas CSS-nya, karena `.label--full` menyetel
        // `justify-content: space-around` pada kolom teks yang tidak ada.
        $layout = match (true) {
            $template->isQrOnly() => 'label--qr-only',
            $compact => 'label--compact',
            default => 'label--full',
        };

        $values = $compact
            ? [
                $content->sku,
                $content->productName,
                $content->conditionLabel().' '.$content->ownerLabel(),
                $showPrice ? $content->priceLabel() : '',
            ]
            : [
                $content->sku,
                $content->productName,
                $content->conditionLabel(),
                $content->ownerLabel(),
                $showPrice ? $content->priceLabel() : '',
            ];

        $rows = '';
        foreach ($geometry->rows as $index => $row) {
            $value = $values[$index] ?? '';

            // Baris yang dimatikan (harga saat toggle harga mati) tidak
            // menghasilkan elemen kosong, supaya tidak menyisakan ruang yang
            // tidak terpakai di stiker.
            if ($value === '') {
                continue;
            }

            $rows .= $this->row($geometry, $row, $value, compact: $compact);
        }

        $qrHtml = $this->qrBlock($content->sku, $geometry);
        $textBelow = '';

        if ($template->isQrOnly()) {
            /*
             * SKU selalu tercetak; harga menyusul kalau toggle harga hidup.
             *
             * Dua baris teks di bawah QR membutuhkan 2 x 0,125 cm -- font
             * yang sama untuk SKU dan harga (lihat `QR_ONLY_SKU_FONT_CM`).
             * Karena QR dipusatkan, teks hanya boleh memakai ruang bawah
             * `(1,38 - QR) / 2`; 0,86 cm untuk QR menyisakan 0,26 cm, dan
             * modulnya turun ke 0,297 mm (2,4 dot). Itu harga yang diterima
             * sejak harga ikut tercetak di stiker ini.
             *
             * Harga ditiadakan begitu toggle "tampilkan harga" mati, sama
             * seperti template lain: stiker yang isinya berubah perlu dicetak
             * ulang supaya harga di rak tidak menyesatkan.
             */
            $priceLine = $showPrice ? e($content->priceLabel()) : '';

            $textBelow = '<div class="label__qr-text">'
                .sprintf(
                    '<div class="label__qr-sku" style="font-size:%scm">%s</div>',
                    $this->cm(LabelGeometry::QR_ONLY_SKU_FONT_CM),
                    e($this->truncateForQrOnly($content->sku)),
                );

            if ($priceLine !== '') {
                $textBelow .= sprintf(
                    '<div class="label__qr-price" style="font-size:%scm">%s</div>',
                    $this->cm(LabelGeometry::QR_ONLY_SKU_FONT_CM),
                    $priceLine,
                );
            }

            $textBelow .= '</div>';
        }

        return $this->wrap(
            $geometry,
            'label label--sku '.$layout,
            $this->body($geometry, $rows, $qrHtml, $textBelow),
        );
    }

    public function renderRack(string $rackCode, LabelTemplate $template): string
    {
        $geometry = LabelGeometry::forRack($template, $this->printer->qrSideCm());
        $code = RackCode::printable($rackCode);

        // FR-MD-21: label rak untuk put-away dan opname harus bisa discan.
        // Layout 3x2 tidak punya ruang untuk QR tanpa membuat kode rak tidak
        // terbaca, jadi `qrBlock` dilewati diam-diam oleh `qrSideCm` 0.
        //
        // Pada QR-only `rows` kosong, jadi `$geometry->rows[0]` tidak ada dan
        // kode rak tidak boleh dirender sama sekali. Yang ditulis ke stiker
        // hanya QR-nya: menyisakan baris kosong bukan pilihan yang aman, karena
        // kode rak yang setengah tercetak lebih buruk daripada tidak ada.
        $rowsHtml = $template->isQrOnly()
            ? ''
            : $this->row($geometry, $geometry->rows[0], $code, compact: false);

        return $this->wrap(
            $geometry,
            'label label--rack '.($template->isQrOnly() ? 'label--qr-only' : ''),
            $this->body($geometry, $rowsHtml, $this->qrBlock($code, $geometry)),
        );
    }

    private function row(LabelGeometry $geometry, LabelRow $row, string $value, bool $compact): string
    {
        $style = $this->rowStyle($geometry, $row);

        $valueClass = 'label__value';

        if ($row->mono) {
            $valueClass .= ' label__value--sku';
        } elseif ($row->weight >= 700) {
            $valueClass .= ' label__value--price';
        } elseif ($compact) {
            $valueClass .= ' label__value--compact';
        }

        // Kelas membungkus datang dari `maxLines`, bukan dari tebakan layout.
        // Dulu stylesheet yang memutuskan sendiri lewat
        // `.label--compact .label__value { white-space: nowrap }`, dan itu
        // mengalahkan `.label__value--sku` -- jadi SKU yang dianggarkan dua
        // baris oleh `LabelGeometry` tetap tercetak satu baris dan terpotong.
        if ($row->wraps()) {
            $valueClass .= ' label__value--wrap';
        }

        $valueSpan = sprintf(
            '<span class="%s" style="%s">%s</span>',
            $valueClass,
            $style,
            e($this->truncate($geometry, $row, $value)),
        );

        if ($compact) {
            return '<div class="label__stack">'.$valueSpan.'</div>';
        }

        if ($row->caption === '') {
            return '<div class="label__line">'.$valueSpan.'</div>';
        }

        // Ukuran font keterangan ditulis inline, bukan dari stylesheet, karena
        // angka itu ikut menentukan `captionWidthCm()` dan jadi menentukan
        // kapasitas baris. Kalau angka ini hidup di CSS, mengubah CSS diam-diam
        // membuat hitungan geometri tidak lagi cocok dengan yang dicetak --
        // persis kelas bug yang sedang diperbaiki di sini.
        return '<div class="label__line">'
            .'<span class="label__caption" style="font-size:'.self::cm(LabelRow::CAPTION_FONT_SIZE_CM).'cm">'
            .e($row->caption)
            .'</span>'
            .$valueSpan
            .'</div>';
    }

    private function rowStyle(LabelGeometry $geometry, LabelRow $row): string
    {
        return sprintf(
            'font-size:%scm;line-height:%s;font-weight:%d;max-height:%scm',
            $this->cm($row->fontSizeCm),
            $this->cm($row->lineHeight),
            $row->weight,
            $this->cm($row->heightCm()),
        );
    }

    /**
     * Sisi QR ditulis langsung sebagai inline style, bukan dari stylesheet.
     *
     * Alasannya geometri: ruang QR menentukan sisa lebar untuk teks, dan sisa
     * lebar itulah yang membuat label muat atau tidak.
     */
    private function qrBlock(string $value, LabelGeometry $geometry): string
    {
        if ($geometry->qrSideCm <= 0.0) {
            return '';
        }

        return sprintf(
            '<div class="label__qr" style="width:%scm;height:%scm">%s</div>',
            $this->cm($geometry->qrSideCm),
            $this->cm($geometry->qrSideCm),
            $this->qr->svg($value),
        );
    }

    private function body(LabelGeometry $geometry, string $rows, string $aside = '', string $below = ''): string
    {
        /*
         * Wadah baris hanya dibuat kalau memang ada baris.
         *
         * `.label__rows` punya `flex: 1`, jadi pada label QR-only wadah kosong
         * itu merebut ruang dan QR tergeser ke pinggir -- persis yang tidak
         * boleh terjadi, karena satu piksel yang hilang berarti satu modul QR
         * hilang. Tanpa wadah itu, QR jadi satu-satunya anak `.label__body`
         * dan otomatis mengisi label.
         */
        $rowsHtml = $rows === '' ? '' : '<div class="label__rows">'.$rows.'</div>';

        if ($below !== '') {
            return sprintf(
                '<div class="label__body label__body--qr-only" style="padding:%scm;gap:%scm">'
                .'%s'
                .'%s'
                .'</div>',
                $this->cm($geometry->paddingCm),
                $this->cm($geometry->gutterCm),
                $aside,
                $below,
            );
        }

        return sprintf(
            '<div class="label__body" style="padding:%scm;gap:%scm">'
            .'%s'
            .'%s'
            .'</div>',
            $this->cm($geometry->paddingCm),
            $this->cm($geometry->gutterCm),
            $rowsHtml,
            $aside,
        );
    }

    /**
     * Potong teks untuk label QR-only agar muat di lebar 1,5 cm.
     *
     * Label ini sangat sempit, jadi SKU dipangkas konservatif tanpa mengorbankan
     * kemampuan scan. Pemotongan terkontrol (bukan hanya CSS) supaya hasilnya
     * konsisten antar cetakan.
     */
    private function wrap(LabelGeometry $geometry, string $class, string $body): string
    {
        return sprintf(
            '<div class="%s" style="width:%scm;height:%scm;--row-gap:%scm">%s</div>',
            e($class),
            $this->cm($geometry->widthCm),
            $this->cm($geometry->heightCm),
            $this->cm($geometry->heightCm <= 2.5 ? 0.03 : 0.06),
            $body,
        );
    }

    private function truncate(LabelGeometry $geometry, LabelRow $row, string $value): string
    {
        $capacity = $geometry->capacityFor($row);

        if ($value === '' || mb_strlen($value) <= $capacity) {
            return $value;
        }

        return mb_substr($value, 0, max(1, $capacity - 1)).'…';
    }

    private function truncateForQrOnly(string $value): string
    {
        $capacity = 18;

        if ($value === '' || mb_strlen($value) <= $capacity) {
            return $value;
        }

        return mb_substr($value, 0, max(1, $capacity - 1)).'…';
    }

    /**
     * Sentimeter ke string CSS untuk nilai yang sudah dihitung `LabelGeometry`.
     *
     * Dua hal dijaga di sini, keduanya soal ruang fisik yang tidak bisa diprediksi.
     *
     * **Pembulatan ke bawah, bukan terdekat.** Nilai yang ditulis ke CSS adalah
     * anggaran: `LabelGeometry` sudah menghitung sisa ruang dari angka yang sama.
     * Kalau pembulatan menaikkan angka -- `font-size` baris SKU 0,125 cm menjadi
     * 0,13 cm -- CSS memakai ruang lebih besar dari yang dianggarkan dan teks mulai
     * menimpa quiet zone QR. Melemahkan yang tercetak hanya membuat label lebih
     * kecil dari rencana, jadi tidak ada risiko baru.
     *
     * **Empat desimal, bukan dua.** Satu dot printer 203 dpi adalah 0,0125 cm, jadi
     * dua desimal -- 0,01 cm -- tidak pernah menyatakan satu dot pun secara utuh.
     * Semua angka label ini memang kelipatan satu dot, jadi membulatkan ke 0,01 cm
     * tidak menambah ketelitian, hanya memalsukan presisi yang tidak ada. Empat
     * desimal (0,001 mm) menyimpan angka apa adanya, termasuk yang punya desimal ketiga seperti
     * 0,125 cm dan 0,504 cm.
     *
     * @param  float  $value  nilai dalam sentimeter
     */
    private function cm(float $value): string
    {
        $factor = 10_000;

        return rtrim(rtrim(number_format(floor($value * $factor) / $factor, 4, '.', ''), '0'), '.');
    }
}
