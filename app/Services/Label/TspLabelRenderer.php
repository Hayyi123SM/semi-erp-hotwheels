<?php

declare(strict_types=1);

namespace App\Services\Label;

/**
 * Isi satu label untuk jalur cetak langsung ke printer TSPL.
 *
 * Menghasilkan daftar operasi gambar (`TspLabelOp`) yang koordinatnya relatif
 * terhadap pojok kiri-atas label. Pemisahan operasi dari baris perintah TSPL
 * sengaja -- lihat `TspLabelOp` -- supaya pergeseran ke sel grid tidak
 * membutuhkan penguraian ulang teks.
 *
 * Layout di sini bukan replika pixel HTML, dan itu keputusan, bukan
 * kekurangan. Halaman `label-print` tetap menjadi jalur yang paling dekat
 * dengan desain; jalur TSPL memakai font bawaan printer (8x16 dot) karena
 * itu satu-satunya yang dijamin ada tanpa raster. Yang dijaga sama adalah
 * ISI: baris yang sama, urutan yang sama, pemangkasan yang eksplisit, dan
 * harga yang mengikuti toggle -- persis seperti `HtmlLabelRenderer`.
 *
 * Setelah jalur ini terbukti di depan printer dan kualitas font bawaan
 * ditolak, `PUTBMP` (raster) bisa menggantikan `TEXT` tanpa mengubah bentuk
 * DTO di sini.
 */
final class TspLabelRenderer
{
    /** 203 dpi = 8 dot/mm, angka yang sama dengan `SheetGrid`. */
    public const int DOTS_PER_MM = SheetGrid::DOTS_PER_MM;

    /**
     * Font TSPL bawaan '1' berukuran 8x16 dot (1 x 2 mm pada 203 dpi).
     *
     * Dipakai untuk semua teks: font lain butuh file font ("TSS24.BF2" dst.)
     * yang belum tentu ada, sedangkan font '1' adalah bagian dari set dasar
     * yang dipastikan ada di hampir semua firmware TSPL.
     */
    public const string FONT = '1';

    public const int FONT_WIDTH_DOT = 8;

    public const int FONT_HEIGHT_DOT = 16;

    /**
     * Modul yang digambar `QRCODE`, termasuk quiet zone.
     *
     * QR versi 1 (payload ≤ 17 byte, sesuai batas SKU 15 karakter) punya
     * 21 modul data + 8 modul quiet zone = 29. Dari sini renderer memilih
     * lebar sel sehingga hasilnya paling besar yang masih muat di sisi QR.
     */
    public const int QR_MODULES = 29;

    public function render(
        LabelContent $content,
        LabelTemplate $template,
        bool $showPrice = true,
        ?float $qrSideOverride = null,
    ): TspLabelBlock {
        $geometry = LabelGeometry::forSku($template, $qrSideOverride);

        if ($template->isQrOnly()) {
            return $this->centeredQr($geometry, $content->sku, [
                $content->sku,
                $showPrice ? $content->priceLabel() : '',
            ]);
        }

        $values = $template === LabelTemplate::ThreeByTwo
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

        return $this->rowsWithSideQr($geometry, $content->sku, $values);
    }

    public function renderRack(string $rackCode, LabelTemplate $template, ?float $qrSideOverride = null): TspLabelBlock
    {
        $geometry = LabelGeometry::forRack($template, $qrSideOverride);

        if ($template->isQrOnly()) {
            return $this->centeredQr($geometry, $rackCode, []);
        }

        return $this->rowsWithSideQr(
            $geometry,
            $rackCode,
            [RackCode::printable($rackCode)],
        );
    }

    /**
     * QR di tengah, dengan baris teks (opsional) di bawahnya.
     *
     * Dipakai label QR-only: QR dikelilingi padding, SKU dan harga diletakkan
     * di bawah QR, dan masing-masing dipusatkan horizontal. Teks memakai sisa
     * tinggi setelah QR; renderer tidak pernah memakan ruang QR demi teks.
     */
    private function centeredQr(LabelGeometry $geometry, string $qrData, array $lines): TspLabelBlock
    {
        $width = $this->dots($geometry->widthCm);
        $height = $this->dots($geometry->heightCm);
        $padding = $this->dots($geometry->paddingCm);
        $qr = $this->qrCell($geometry, $qrData);

        $ops = [];

        if ($qr['drawnDots'] > 0) {
            $qrX = max($padding, intdiv($width - $qr['drawnDots'], 2));
            $ops[] = TspLabelOp::qr($qrX, $padding, $qr['cell'], $qr['data']);

            $cursorY = $padding + $qr['drawnDots'] + self::FONT_HEIGHT_DOT;
        } else {
            $cursorY = $padding;
        }

        $capacity = max(1, intdiv($width - (2 * $padding), self::FONT_WIDTH_DOT));

        foreach ($lines as $line) {
            $wrapped = $this->wrapLines((string) $line, $capacity, 1);

            if ($wrapped === [] || $cursorY + self::FONT_HEIGHT_DOT > $height - $padding) {
                break;
            }

            $text = $wrapped[0];
            $x = max($padding, intdiv($width - (strlen($text) * self::FONT_WIDTH_DOT), 2));

            $ops[] = TspLabelOp::text($x, $cursorY, $text);
            $cursorY += self::FONT_HEIGHT_DOT;
        }

        return new TspLabelBlock($width, $height, $ops);
    }

    /**
     * Baris teks di kiri, QR di kanan -- susunan yang sama dengan label HTML
     * non-QR-only (`label__rows` + `label__qr`).
     */
    private function rowsWithSideQr(LabelGeometry $geometry, string $qrData, array $values): TspLabelBlock
    {
        $width = $this->dots($geometry->widthCm);
        $height = $this->dots($geometry->heightCm);
        $padding = $this->dots($geometry->paddingCm);
        $gutter = $this->dots($geometry->gutterCm);
        $qr = $this->qrCell($geometry, $qrData);

        $textColumn = $width - (2 * $padding) - $qr['drawnDots'] - $gutter;
        $capacity = max(1, intdiv(max(0, $textColumn), self::FONT_WIDTH_DOT));

        $ops = [];

        if ($qr['drawnDots'] > 0) {
            $ops[] = TspLabelOp::qr(
                $width - $padding - $qr['drawnDots'],
                $padding,
                $qr['cell'],
                $qr['data'],
            );
        }

        $cursorY = $padding;
        $maxY = $height - $padding;

        foreach ($geometry->rows as $index => $row) {
            $value = $values[$index] ?? '';

            if ($value === '') {
                continue;
            }

            $wrapped = $this->wrapLines((string) $value, $capacity, $row->maxLines);

            foreach ($wrapped as $line) {
                if ($cursorY + self::FONT_HEIGHT_DOT > $maxY) {
                    break 2;
                }

                $ops[] = TspLabelOp::text($padding, $cursorY, $line);
                $cursorY += self::FONT_HEIGHT_DOT;
            }
        }

        return new TspLabelBlock($width, $height, $ops);
    }

    /**
     * Pilih lebar sel QR terbesar yang masih muat di sisi QR label.
     *
     * Programmemakai `intdiv` (bukan `round`) supaya QR tidak pernah lebih
     * besar dari ruang yang dianggarkan `LabelGeometry` -- melampaui berarti
     * menimpa quiet zone label.
     *
     * @return array{cell: int, drawnDots: int, data: string}
     */
    private function qrCell(LabelGeometry $geometry, string $data): array
    {
        $safe = $this->safeText($data);
        $sideDots = $this->dots($geometry->qrSideCm);

        if ($sideDots < self::QR_MODULES || $safe === '') {
            return ['cell' => 0, 'drawnDots' => 0, 'data' => $safe];
        }

        $cell = intdiv($sideDots, self::QR_MODULES);

        return [
            'cell' => $cell,
            'drawnDots' => $cell * self::QR_MODULES,
            'data' => $safe,
        ];
    }

    /**
     * Pecah nilai menjadi baris-baris yang masing-masing muat, sesuai
     * `maxLines` si baris.
     *
     * Baris yang hanya boleh satu baris (harga) dipangkas dengan penanda
     * `..` supaya angka yang terpotong tetap terlihat sudah terpotong. Baris
     * yang dibolehkan membungkus dipecah per kapasitas, tanpa menenggelamkan
     * kata: label thermal tidak punya ruang untuk pemenggalan kata yang
     * estetis, dan `LabelContent` sudah memendekkan nama produk.
     *
     * @return list<string>
     */
    private function wrapLines(string $value, int $capacity, int $maxLines): array
    {
        $value = $this->safeText($value);
        $safeCapacity = max(1, $capacity);

        if ($value === '') {
            return [];
        }

        if ($maxLines <= 1) {
            if (strlen($value) <= $safeCapacity) {
                return [$value];
            }

            return [substr($value, 0, max(1, $safeCapacity - 2)).'..'];
        }

        $lines = [];
        $rest = $value;

        while ($rest !== '' && count($lines) < $maxLines) {
            $lines[] = substr($rest, 0, $safeCapacity);
            $rest = substr($rest, $safeCapacity);
        }

        if ($rest !== '') {
            $last = count($lines) - 1;
            $lines[$last] = substr($lines[$last], 0, max(1, $safeCapacity - 2)).'..';
        }

        return $lines;
    }

    /**
     * Teks aman untuk perintah TSPL: hanya ASCII yang bisa dicetak.
     *
     * Font '1' memakai code page yang biasanya ASCII; byte non-ASCII bisa
     * muncul sebagai karakter lain atau mematahkan data ter-quote. Nilai yang
     * tidak bisa digambar diganti `?` supaya tidak ada yang berubah bentuk
     * diam-diam. Guillemet `"` diganti kutip tunggal agar tidak memutus quote
     * perintah TSPL.
     */
    private function safeText(string $value): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '?', $value);

        return str_replace('"', "'", $ascii ?? '');
    }

    private function dots(float $cm): int
    {
        return (int) round($cm * 10 * self::DOTS_PER_MM);
    }
}
