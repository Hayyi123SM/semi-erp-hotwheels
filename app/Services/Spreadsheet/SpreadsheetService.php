<?php

namespace App\Services\Spreadsheet;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class SpreadsheetService
{
    public function rows(string $path, int $maxRows = 2000): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(false);

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
        $rows = [];

        foreach ($sheet->getRowIterator() as $rowIndex => $row) {
            if ($rowIndex > $maxRows) {
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

        return array_values($rows);
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
