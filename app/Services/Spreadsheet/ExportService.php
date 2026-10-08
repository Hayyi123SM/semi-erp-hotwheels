<?php

namespace App\Services\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Unduhan laporan dalam dua format yang sama-sama bisa dibuka pembaca.
 *
 * Dua fungsi kembar (CSV dan XLSX) ada karena keduanya punya pembaca yang nyata
 * di mejanya sendiri. CSV dibuka Excel sekaligus brankas di tempat lain, dan
 * nilainya ditulis polos -- "175000", bukan "Rp175.000" -- supaya bisa dijumlahkan
 * mesin. XLSX ditulis lewat PhpSpreadsheet dengan baris kepala berwarna, supaya
 * laporan yang sama bisa dibagikan apa adanya.
 *
 * Kolom "laba/HPP" yang tidak boleh dibaca Staff dikecualikan oleh pemanggil
 * sebelum baris sampai di sini, bukan disembunyikan di sini: layanan ini tidak
 * tahu siapa pembacanya, jadi menyaring lewat dia hanya akan membuat dua sumber
 * kebenaran soal siapa boleh melihat uang toko.
 */
class ExportService
{
    /**
     * @param  string  $filename  nama berkas tanpa ekstensi
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function csv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            // BOM di depan: tanpanya Excel membaca berkas ini sebagai cp1252 dan
            // huruf non-ASCII di nama produk keluar pecah.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ',', '"', '\\');

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (mixed $cell): mixed => $this->cell($cell), $row), ',', '"', '\\');
            }

            fclose($out);
        }, $filename.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  string  $filename  nama berkas tanpa ekstensi
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function xlsx(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            (new Xlsx($this->build($headers, $rows)))->save('php://output');
        }, $filename.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Lembar kerja satu baris kepala dan `$rows` baris data.
     *
     * Nilai angka ditulis sebagai angka benar-benar (bukan teks) supaya Excel
     * mempertahankannya untuk dijumlahkan; `null` ditulis kosong, bukan string
     * "null".
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function build(array $headers, array $rows): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($headers as $index => $header) {
            $sheet->setCellValue((string) Coordinate::stringFromColumnIndex($index + 1).'1', $header);
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $cell) {
                $value = $this->cell($cell);

                if ($value === null) {
                    continue;
                }

                $sheet->setCellValue(
                    (string) Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 2),
                    $value,
                );
            }
        }

        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($headers)));
        $head = $sheet->getStyle('A1:'.$lastColumn.'1');
        $head->getFont()->setBold(true);
        $head->getFill()->setFillType(Fill::FILL_SOLID);
        $head->getFill()->getStartColor()->setARGB('FFEFF6FF');

        foreach (range(1, max(1, count($headers))) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
        }

        return $spreadsheet;
    }

    private function cell(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
