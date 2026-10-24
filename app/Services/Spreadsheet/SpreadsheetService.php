<?php

namespace App\Services\Spreadsheet;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class SpreadsheetService
{
    /**
     * Batas baris per unggahan, dihitung termasuk baris judul.
     *
     * Bukan sekadar angka efisiensi: pembaca menyimpan seluruh lembar di
     * memori, jadi berkas yang tidak dibatasi bisa menghabiskan proses request
     * sebelum sempat diproses. Batasnya harus terlihat oleh pengguna, bukan
     * dipotong diam-diam di belakang layar.
     */
    public const MAX_ROWS = 2000;

    /**
     * Baca berkas menjadi daftar baris yang benar-benar berisi data.
     *
     * Baris setelah `maxRows` dulu hilang tanpa jejak: berkas tiga ribu baris
     * masuk sebagian dan tidak ada yang diberi tahu. Hasilnya sekarang
     * membawa `totalRows` dan `truncated`, supaya pemanggil bisa menolak
     * berkas yang kepanjangan sebelum sebagian datanya lenyap.
     *
     * @return array{rows: array<int, array<int, string|null>>, totalRows: int, truncated: bool}
     */
    public function rows(string $path, int $maxRows = self::MAX_ROWS): array
    {
        $totalRows = $this->dataRowCount($path);

        // Berkas yang sudah ketahuan kepanjangan tidak perlu diparsing lagi:
        // pemanggil pasti menolaknya, dan membaca seluruh isinya hanya
        // membuang memori sebelum berkas itu dibuang juga.
        if ($totalRows > $maxRows) {
            return ['rows' => [], 'totalRows' => $totalRows, 'truncated' => true];
        }

        $truncated = false;
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(false);

        // Berkas CSV kita ditulis dengan pemisah koma (lihat `ExportService`).
        // Membiarkan pembaca menebak sendiri berbahaya: satu nama orang yang
        // memuat spasi (mis. "Budi Santoso") cukup untuk membuatnya memilih
        // spasi sebagai pemisah, dan seluruh barisnya bergeser tanpa error.
        if ($reader instanceof CsvReader) {
            $reader->setDelimiter(',');
            $reader->setEnclosure('"');
        }

        // Filter baris sengaja dinaikkan satu baris di atas batas: baris itu
        // tidak pernah ikut disimpan, tapi keberadaannya menjadi penanda cadangan
        // kalau penghitungan di `dataRowCount()` tidak bisa membaca berkasnya.
        $filter = new class($maxRows + 1) implements IReadFilter
        {
            public function __construct(private int $maxRows) {}

            public function readCell($column, $row, $worksheetName = ''): bool
            {
                return $row <= $this->maxRows;
            }
        };
        $reader->setReadFilter($filter);

        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [];

        foreach ($sheet->getRowIterator() as $rowIndex => $row) {
            if ($rowIndex > $maxRows) {
                $truncated = true;
                break;
            }

            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                if (Date::isDateTime($cell)) {
                    $cells[] = $cell->getFormattedValue();

                    continue;
                }

                $value = $cell->getValue();
                if (is_scalar($value)) {
                    $cells[] = (string) $value;

                    continue;
                }

                if ($value === null) {
                    $cells[] = null;

                    continue;
                }

                $cells[] = (string) $value;
            }

            if ($this->isFilled($cells)) {
                $rows[] = $cells;
            }
        }

        // Selama `truncated` terbaca benar, `totalRows` harus tetap masuk akal
        // untuk ditampilkan. Kalau penghitungannya sendiri gagal, batas bawah
        // yang diketahui adalah baris pertama yang terbaca melewati batas.
        if ($truncated) {
            $totalRows = max($totalRows, $maxRows + 1);
        }

        return ['rows' => array_values($rows), 'totalRows' => $totalRows, 'truncated' => $truncated];
    }

    /**
     * Nomor baris terakhir yang benar-benar berisi data, tanpa memuat isinya.
     *
     * Pembacaan kedua ini murah -- untuk .xlsx hanya membaca XML lembar dan
     * untuk .csv hanya menghitung baris -- dan tidak terpengaruh filter baris
     * di `rows()`, sehingga data yang berada jauh melewati batas tetap
     * terdeteksi, bukan hanya yang berada di baris berikutnya.
     *
     * Penghitungan yang gagal bukan alasan menolak berkas: berkas yang tidak
     * bisa dihitung tetap diperiksa seperti biasa, dan `rows()` masih punya
     * penanda cadangan di baris batasnya sendiri.
     */
    private function dataRowCount(string $path): int
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadEmptyCells(false);
            $info = $reader->listWorksheetInfo($path);
        } catch (\Throwable) {
            return 0;
        }

        return (int) ($info[0]['totalRows'] ?? 0);
    }

    public function detectHeaderRow(array $rows): ?int
    {
        foreach ($rows as $index => $row) {
            $nonEmpty = array_filter($row, fn ($cell) => $cell !== null && trim((string) $cell) !== '');
            $numeric = array_filter($nonEmpty, fn ($cell) => is_numeric(trim((string) $cell)));
            $hasText = count($nonEmpty) - count($numeric);

            if (count($nonEmpty) >= 2 && $hasText >= count($nonEmpty) / 2) {
                return $index;
            }
        }

        return null;
    }

    private function isFilled(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return true;
            }
        }

        return false;
    }
}
