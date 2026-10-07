<?php

namespace App\Services\Spreadsheet;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class SpreadsheetService
{
    public function rows(string $path, int $maxRows = 2000): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        $filter = new class($maxRows) implements IReadFilter
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
        $rows = $sheet->toArray(null, true, false, false);

        return array_values(array_filter($rows, fn (array $row) => $this->isFilled($row)));
    }

    /**
     * Tebak baris pertama yang layak menjadi baris header.
     * Kembalikan indeks baris (mulai 0) atau null bila tak ditemukan.
     */
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
