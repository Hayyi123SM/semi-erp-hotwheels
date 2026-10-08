<?php

declare(strict_types=1);

namespace App\Services\Print\Thermal;

use App\Services\Pos\SaleStrukSheet;
use App\Support\Format;
use InvalidArgumentException;
use Mike42\Escpos\PrintConnectors\MemoryPrintConnector;
use Mike42\Escpos\Printer;

/**
 * Menyusun nota kasir menjadi byte ESC/POS.
 *
 * Berpasangan dengan `PosStrukRenderer` untuk dua medium, sama seperti
 * `ReceiptRenderer` untuk bukti terima: halaman html dan byte ini harus
 * menampilkan angka yang sama, dan keduanya membaca `SaleStrukSheet` sehingga
 * penyesuaian isi cukup dilakukan sekali.
 *
 * Byte TIDAK dikirim ke printer di sini; server hanya menyerahkan byte
 * (base64) ke halaman, dan halaman yang memilih ke mana byte itu pergi --
 * alasan yang sama persis dengan `ReceiptRenderer`.
 *
 * Isi struk selalu ASCII: rupiah ditulis `Rp` + titik, tanpa lambang khusus,
 * supaya byte tetap hidup untuk kodepage printer mana pun.
 */
final readonly class PosStrukRenderer
{
    /**
     * @throws InvalidArgumentException kalau kertasnya tidak bisa dicetak thermal
     */
    public static function render(SaleStrukSheet $sheet): string
    {
        $width = $sheet->paper()->escposColumnWidth();

        if (! $sheet->paper()->isThermal() || $width === null) {
            throw new InvalidArgumentException('Ukuran '.$sheet->paper()->label().' tidak bisa dicetak thermal.');
        }

        $connector = new MemoryPrintConnector;
        $printer = new Printer($connector);
        $data = null;

        try {
            $printer->initialize();

            $printer->setEmphasis(true);
            self::center($printer, self::fit($sheet->storeName(), $width), $width);
            $printer->setEmphasis(false);

            $printer->setTextSize(1, 1);
            $printer->setEmphasis(true);
            self::center($printer, self::fit('Nota Kasir', $width), $width);
            $printer->setEmphasis(false);

            $printer->text(str_repeat('-', $width));
            $printer->feed();

            self::row($printer, 'No. nota', $sheet->docNo(), $width);
            self::row($printer, 'Waktu', $sheet->soldOn(), $width);
            self::row($printer, 'Kasir', $sheet->cashierName(), $width);
            self::row($printer, 'Shift', $sheet->shiftNo(), $width);

            $printer->text(str_repeat('-', $width));
            $printer->feed();

            $lines = $sheet->lines();

            if ($lines === []) {
                $printer->text(self::fit('Tidak ada barang tercatat pada nota ini.', $width));
                $printer->feed();
            }

            foreach ($lines as $line) {
                $printer->setEmphasis(true);
                $printer->text(self::fit((string) $line['sku'], $width));
                $printer->setEmphasis(false);
                $printer->feed();

                $printer->text(self::fit((string) $line['name'], $width));
                $printer->feed();

                self::meta($printer, [
                    (string) $line['qty'].' x '.Format::rupiah($line['price']),
                    Format::rupiah($line['line_total']),
                ], $width);
            }

            $printer->text(str_repeat('-', $width));
            $printer->feed();

            self::row($printer, 'Subtotal', Format::rupiah($sheet->subtotal()), $width);

            if ($sheet->discountTotal() > 0) {
                self::row($printer, 'Diskon', '-'.Format::rupiah($sheet->discountTotal()), $width);
            }

            $printer->setEmphasis(true);
            self::row($printer, 'Total', Format::rupiah($sheet->total()), $width);
            $printer->setEmphasis(false);

            $printer->text(str_repeat('-', $width));
            $printer->feed();

            foreach ($sheet->payments() as $payment) {
                $ref = $payment['reference'] !== null ? trim((string) $payment['reference']) : '';

                if ($ref !== '') {
                    $printer->setEmphasis(true);
                    $printer->text(self::fit((string) $payment['method'], $width));
                    $printer->setEmphasis(false);
                    $printer->feed();
                    $printer->text(self::pair('Ref '.$ref, Format::rupiah($payment['amount']), $width));
                    $printer->feed();
                } else {
                    $printer->setEmphasis(true);
                    self::row($printer, (string) $payment['method'], Format::rupiah($payment['amount']), $width);
                    $printer->setEmphasis(false);
                }
            }

            // Cetakan ulang tidak tahu uang yang dipegang kasir, jadi baris ini
            // hanya ada saat layar kasir memang punya angkanya.
            if ($sheet->changeDue !== null) {
                self::row($printer, 'Kembalian', Format::rupiah($sheet->changeDue), $width);
            }

            $printer->feed(2);
            self::center($printer, self::fit('Terima kasih atas kunjungan Anda.', $width), $width);
            $printer->feed(2);

            self::center($printer, self::fit('Dicetak: '.$sheet->printedAt().' oleh '.$sheet->printedByName(), $width), $width);

            $printer->feed(2);
            $printer->cut();

            // Diambil SEBELUM `close()`. `MemoryPrintConnector::finalize()`
            // mengosongkan buffer-nya, jadi `getData()` sesudah `close()` akan
            // menyentuh buffer yang sudah dinolkan -- paling banter TypeError.
            $data = $connector->getData();
        } finally {
            // Menutup selalu di sini: dalam keadaan normal byte sudah lengkap
            // (cut dilakukan di atas), dan kalau render gagal di tengah, byte
            // yang tertinggal tetap dibuang dengan bersih.
            $printer->close();
        }

        return $data;
    }

    /**
     * Satu baris dokumen: label di kiri, nilai di kanan.
     *
     * Mirip `.receipt-row` blade: label dan nilai saling berjauhan, bukan
     * dipisahkan titik-titik.
     */
    private static function row(Printer $printer, string $label, string $value, int $width): void
    {
        $printer->text(self::pair($label, self::fit($value, max(8, $width - strlen($label) - 1)), $width));
        $printer->feed();
    }

    /**
     * Meta satu baris: dua nilai menyebar seperti `space-between` blade.
     */
    private static function meta(Printer $printer, array $parts, int $width): void
    {
        $parts = array_values(array_filter($parts, static fn ($part): bool => $part !== null && trim((string) $part) !== ''));

        if ($parts === []) {
            return;
        }

        $printer->text(self::spread($width, $parts));
        $printer->feed();
    }

    /**
     * @param  list<string>  $parts
     */
    private static function spread(int $width, array $parts): string
    {
        $parts = array_map(static fn (string $part): string => self::fit($part, $width), $parts);

        if (count($parts) === 1) {
            return $parts[0];
        }

        return self::pair($parts[0], $parts[1], $width);
    }

    private static function pair(string $left, string $right, int $width): string
    {
        $left = self::fit($left, $width);
        $right = self::fit($right, max(1, $width - strlen($left) - 1));
        $gap = max(1, $width - strlen($left) - strlen($right));

        return self::fit($left.str_repeat(' ', $gap).$right, $width);
    }

    private static function center(Printer $printer, string $text, int $width): void
    {
        $text = self::fit($text, $width);
        $pad = max(0, intdiv($width - strlen($text), 2));

        if ($pad > 0) {
            $printer->text(str_repeat(' ', $pad));
        }

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text($text);
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        if ($pad > 0) {
            $remainder = ($width - strlen($text)) % 2;
            if ($remainder > 0) {
                $printer->text(' ');
            }
        }

        $printer->feed();
    }

    private static function fit(string $text, int $width): string
    {
        return strlen($text) > $width ? substr($text, 0, $width) : $text;
    }
}
