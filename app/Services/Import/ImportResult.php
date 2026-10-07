<?php

namespace App\Services\Import;

class ImportResult
{
    /**
     * @var array<int, array<string, mixed>> Baris valid siap dipersist.
     */
    public array $rows = [];

    /**
     * @var array<int, array{row: int, errors: array<int, string>}>
     */
    public array $failures = [];

    public function addSuccess(array $row, int $excelRow): void
    {
        $this->rows[] = ['row' => $excelRow, 'data' => $row];
    }

    public function addFailure(int $excelRow, array $errors): void
    {
        $this->failures[] = ['row' => $excelRow, 'errors' => $errors];
    }

    public function successCount(): int
    {
        return count($this->rows);
    }

    public function failureCount(): int
    {
        return count($this->failures);
    }

    public function total(): int
    {
        return $this->successCount() + $this->failureCount();
    }
}
