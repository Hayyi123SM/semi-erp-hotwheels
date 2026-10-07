<?php

declare(strict_types=1);

namespace App\Services\Print\Thermal;

use App\Services\Consignment\ReceiptSheet;
use InvalidArgumentException;
use Mike42\Escpos\PrintConnectors\MemoryPrintConnector;
use Mike42\Escpos\Printer;

/**
 * Menyusun bukti terima titipan menjadi byte ESC/POS.
 *
 * Halaman html dan byte ini harus menampilkan angka yang sama; kalau tidak,
 * struk yang dihasilkan di sini dan kertas dari halaman cetak terbaca sebagai
 * dua dokumen berbeda oleh penitip. Detail itu tidak disusun ulang di sini:
 * keduanya membaca `ReceiptSheet`, jadi penyesuaian isi cukup dilakukan sekali
 * di satu tempat.
 *
 * Layoutnya setia pada `consignment-receipt.blade.php` dalam mode thermal:
 * nama toko, judul, garis pemisah, baris dokumen, rincian per SKU, catatan,
 * dua kolom tanda tangan, lalu footer. Bedanya hanya medium: yang di atas pakai
 * CSS untuk mendorong nilai ke kanan dan menyebar tiga kolom meta, yang di sini
 * pakai padding teks dengan lebar kolom yang dibaca dari `PaperSize`.
 *
 * Byte ini TIDAK dikirim ke printer di sini. Server cukup menyerahkan byte
 * (base64) ke halaman, dan halaman yang memilih ke mana byte itu pergi: pakai
 * Web Bluetooth dulu, bisa diganti QZ Tray dengan menukar satu-satunya tempat
 * yang menyentuh perangkat keras. Server tidak punya akses ke printer
 * kasir/label meja, sehingga tidak ada gunanya server mencoba membedakan.
 *
 * Isi struk selalu ASCII. Format rupiah memakai huruf `Rp` dan titik, dan detail
 * di sini tidak memakai lambang selain itu -- supaya byte yang dihasilkan tetap
 * hidup untuk kodepage printer mana pun, termasuk yang tidak punya lambang
 * khusus.
 */
final readonly class ReceiptRenderer
{
    /**
     * @throws InvalidArgumentException kalau kertasnya tidak bisa dicetak thermal
     */
    public static function render(ReceiptSheet $sheet): string
    {
        $width = $sheet->paper->escposColumnWidth();

        if (! $sheet->paper->isThermal() || $width === null) {
            throw new InvalidArgumentException('Ukuran '.$sheet->paper->label().' tidak bisa dicetak thermal.');
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
            self::center($printer, self::fit('Bukti Terima Titipan', $width), $width);
            $printer->setEmphasis(false);

            $printer->text(str_repeat('-', $width));
            $printer->feed();

            self::row($printer, 'No. dokumen', $sheet->docNo(), $width);
            self::row($printer, 'Tanggal terima', $sheet->receivedOn(), $width);
            self::row(
                $printer,
                'Penitip',
                $sheet->consignorName().' ('.$sheet->consignorCode().')',
                $width,
            );
            self::row($printer, 'Jumlah', $sheet->totals()['items'].' SKU - '.$sheet->totals()['pcs'].' pcs', $width);

            $printer->text(str_repeat('-', $width));
            $printer->feed();

            $lines = $sheet->lines();

            if ($lines === []) {
                $printer->text(self::fit('Tidak ada barang tercatat pada dokumen ini.', $width));
                $printer->feed();
            }

            foreach ($lines as $line) {
                $printer->setEmphasis(true);
                $printer->text(self::fit((string) $line['sku'], $width));
                $printer->setEmphasis(false);

                self::meta($printer, [
                    (string) $line['pcs'].' pcs',
                    (string) $line['price'],
                    $line['scheme'] !== null ? (string) $line['scheme'] : null,
                ], $width);
            }

            $variance = $sheet->varianceNote();

            if ($variance !== null) {
                $printer->feed();
                self::wrap($printer, 'Catatan: '.$variance, $width);
            }

            $printer->feed(2);

            self::signRow($printer, 'Petugas Toko', 'Penitip', $width);
            $printer->feed(5);
            self::signRow($printer, $sheet->storeSignerName(), $sheet->consignorSignerName(), $width);

            $printer->feed(2);
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            $printer->text(self::fit('Dicetak: '.$sheet->printedAt().' oleh '.$sheet->printedByName(), $width));
            $printer->setJustification(Printer::JUSTIFY_LEFT);

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
     * Meta satu SKU: pcs, harga, skema -- menyebar seperti `space-between` blade.
     *
     * Kalau hanya satu atau dua bagian yang ada, yang tersisa tidak ditampilkan,
     * dan nilai terakhir tetap didorong ke kanan.
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
     * Dua kolom tanda tangan pada satu baris.
     *
     * Mirip `.receipt-signatures` blade: peran di atas, ruang kosong, lalu nama
     * di bawah -- keduanya dalam dua kolom. Kolom dibatasi separuh lebar kertas;
     * nama yang lebih panjang dipotong dari kanan.
     */
    private static function signRow(Printer $printer, string $left, string $right, int $width): void
    {
        $half = intdiv($width, 2);
        $l = self::fit($left, $half);
        $r = self::fit($right, $half);

        $printer->text(str_pad($l, $half).$r);
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

        if (count($parts) === 2) {
            return self::pair($parts[0], $parts[1], $width);
        }

        $first = array_shift($parts);
        $last = array_pop($parts);
        $middle = implode(' ', $parts);

        return self::pair($first.' '.$middle, $last, $width);
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
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text($text);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->feed();
    }

    /**
     * Teks panjang dibungkus per baris selebar kolom.
     */
    private static function wrap(Printer $printer, string $text, int $width): void
    {
        foreach (explode("\n", $text) as $segment) {
            while (strlen($segment) > $width) {
                $printer->text(self::fit($segment, $width));
                $printer->feed();
                $segment = substr($segment, $width);
            }
            $printer->text($segment);
            $printer->feed();
        }
    }

    private static function fit(string $text, int $width): string
    {
        return strlen($text) > $width ? substr($text, 0, $width) : $text;
    }
}
