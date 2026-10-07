<?php

declare(strict_types=1);

namespace App\Services\Label;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR Code sebagai SVG, untuk disisipkan ke dalam HTML label.
 *
 * Dipisah dari `HtmlLabelRenderer` dengan alasan yang sama seperti mesenchymalkan
 * `LabelContent` dari `StockLot`: renderer tidak boleh tahu-menahu soal pustaka
 * barcode. Saat label pindah ke TSPL untuk Print Agent, generator ini tetap
 * dipakai untuk memverifikasi isi yang dikirim, sementara renderernya berubah.
 *
 * SVG dipilih di atas PNG karena vektor: tetap tajam pada printer 300 dpi
 * maupun di layar Retina, dan tidak menambah berat unduhan.
 */
final class QrCode
{
    /**
     * Ukuran render dalam piksel. Tidak menentukan ukuran cetak -- yang itu
     * dikendalikan CSS pada wrapper SVG-nya -- jadi angka ini hanya perlu
     * cukup besar agar modul tidak pecah saat di-raster printer.
     */
    private const RENDER_SIZE = 256;

    /**
     * Quiet zone dalam modul. Empat modul adalah nilai yang diwajibkan
     * spesifikasi; label yang menempel pas di tepi stiker sering gagal
     * dibaca scanner kalau quiet zone ini dipangkas.
     */
    private const QUIET_ZONE_MODULES = 4;

    public function svg(string $data): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(self::RENDER_SIZE, self::QUIET_ZONE_MODULES),
            new SvgImageBackEnd,
        ));

        $svg = $writer->writeString($data, Encoder::DEFAULT_BYTE_MODE_ENCODING, $this->eccLevel());

        return $this->withoutPixelDimensions($svg);
    }

    /**
     * QR bawaan library mengunci `width`/`height` dalam piksel. Untuk label,
     * ukuran harus datang dari CSS -- kalau tidak, tiap printer bisa
     * menghasilkan ukuran cetakan yang berbeda dari yang diminta. Yang
     * dipertahankan hanya `viewBox`, karena itu yang menjaga rasio saat
     * CSS mengambil alih ukuran.
     *
     * Prolog `<?xml ...?>` juga dibuang. QR ini tidak pernah berdiri sendiri
     * sebagai berkas: hasilnya inline di dalam HTML label. Di sana `<?`
     * memulai bogus comment menurut aturan parser HTML, jadi prolognya
     * berubah jadi komentar yang tidak terlihat -- dan membingungkan setiap
     * orang yang Inspect Element demi mencari tahu kenapa ada teks XML di
     * dalam halaman.
     */
    private function withoutPixelDimensions(string $svg): string
    {
        $withoutSize = preg_replace('/\s(?:width|height)="[^"]*"/', '', $svg);
        $withoutSize = preg_replace('/^\s*<\?xml[^>]*\?>\s*/', '', $withoutSize ?? '');

        // Library tidak selalu menyertakan viewBox. Tanpa itu, rasio aspek
        // tidak bisa dijaga dan QR bisa gepeng saat di-raster.
        if (! str_contains($withoutSize ?? '', 'viewBox=')) {
            $withoutSize = preg_replace('/<svg\b/', '<svg viewBox="0 0 '.self::RENDER_SIZE.' '.self::RENDER_SIZE.'"', $withoutSize);
        }

        return $withoutSize ?? $svg;
    }

    /**
     * ECC level M, sesuai §1.4.2. Level M bertahan terhadap sekitar 15%
     * kerusakan -- cukup untuk stiker yang tergesek, dan pada isi yang sama
     * menghasilkan QR yang lebih sederhana (modul lebih besar) dibanding H.
     * Itu penting untuk label sekecil 3x2 cm: makin sedikit modul, makin
     * besar tiap modulnya, makin mudah dibaca scanner.
     */
    private function eccLevel(): ErrorCorrectionLevel
    {
        return ErrorCorrectionLevel::M();
    }
}
