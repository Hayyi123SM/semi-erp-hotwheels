<?php

namespace App\Services\Import;

use App\Support\Format;

class ImportResult
{
    /**
     * Peringatan penggabungan: baris berisikan hal berbeda sehingga tidak
     * disatukan dengan kelompoknya. Datanya tetap tersimpan terpisah.
     */
    public const KIND_MERGE = 'merge';

    /**
     * Peringatan referensi: nama produk menunjuk ke beberapa baris master
     * yang identik, jadi satu di antaranya dipakai sebagai wakil.
     */
    public const KIND_DUPLICATE = 'duplicate';

    /**
     * @var array<int, array{row: int, anchorRow: int, sourceRows: array<int, int>, label: ?string, data: array<string, mixed>}> Baris valid siap dipersist.
     */
    public array $rows = [];

    /**
     * @var array<int, array{row: int, errors: array<int, string>}>
     */
    public array $failures = [];

    /**
     * @var array<int, array{rows: array<int, int>, messages: array<int, string>, kind: string}>
     */
    public array $warnings = [];

    /**
     * Jumlah baris data di berkas asli, dihitung sebelum baris lanjutan
     * digabung. Tanpa ini "Total Baris" di halaman Validasi ikut menyusut
     * bersama jumlah baris gabungan, dan orang kehilangan ukuran asli
     * berkasnya sendiri.
     */
    public int $sourceRowCount = 0;

    /**
     * @param  array<int, int>  $sourceRows  Baris asal di berkas yang digabung menjadi baris ini.
     */
    public function addSuccess(array $row, int $excelRow, array $sourceRows = [], int $anchorRow = 0, ?string $label = null): void
    {
        $sourceRows = $sourceRows === [] ? [$excelRow] : array_values(array_unique(array_map('intval', $sourceRows)));

        $this->rows[] = [
            'row' => $excelRow,
            'anchorRow' => $anchorRow === 0 ? $excelRow : $anchorRow,
            'sourceRows' => $sourceRows,
            'label' => $label,
            'data' => $row,
        ];
    }

    public function addFailure(int $excelRow, array $errors): void
    {
        $this->failures[] = ['row' => $excelRow, 'errors' => $errors];
    }

    /**
     * @param  array<int, int>  $rows
     * @param  array<int, string>  $messages
     * @param  string  $kind  Salah satu konstanta `KIND_*`.
     */
    public function addWarning(array $rows, array $messages, string $kind = self::KIND_MERGE): void
    {
        $this->warnings[] = [
            'rows' => array_values(array_unique(array_map('intval', $rows))),
            'messages' => $messages,
            'kind' => $kind,
        ];
    }

    public function successCount(): int
    {
        return count($this->rows);
    }

    public function failureCount(): int
    {
        return count($this->failures);
    }

    public function warningCount(?string $kind = null): int
    {
        if ($kind === null) {
            return count($this->warnings);
        }

        return count(array_filter($this->warnings, static fn (array $warning): bool => $warning['kind'] === $kind));
    }

    public function total(): int
    {
        return max($this->sourceRowCount, $this->successCount() + $this->failureCount());
    }

    /**
     * Baris yang perlu disorot di halaman Validasi: hasil penggabungan,
     * kelompok yang sengaja tidak digabung, dan baris yang nama produknya
     * menunjuk ke duplikat master.
     *
     * Berkas biasa tanpa penggabungan dan tanpa duplikat mengembalikan daftar
     * kosong, jadi tampilan penyimpanan normal tidak ikut terganggu.
     *
     * @return list<array{source: string, label: ?string, qty: int, messages: array<int, string>, kinds: array<int, string>}>
     */
    public function notableRows(): array
    {
        $byRange = [];

        foreach ($this->warnings as $warning) {
            $range = Format::rowRange($warning['rows']);
            $byRange[$range]['messages'] = array_merge($byRange[$range]['messages'] ?? [], $warning['messages']);
            $byRange[$range]['kinds'][$warning['kind']] = true;
        }

        $rows = [];

        foreach ($this->rows as $row) {
            $range = Format::rowRange($row['sourceRows']);
            $messages = $byRange[$range]['messages'] ?? [];

            if (count($row['sourceRows']) <= 1 && $messages === []) {
                continue;
            }

            $rows[] = [
                'source' => $range,
                'label' => $row['label'],
                'qty' => (int) ($row['data']['qty'] ?? 0),
                'messages' => $messages,
                'kinds' => array_keys($byRange[$range]['kinds'] ?? []),
            ];
        }

        return $rows;
    }

    /**
     * Jumlah baris asal di balik peringatan. Satu peringatan bisa mewakili
     * lebih dari satu baris, jadi angkanya bukan `warningCount()`.
     */
    public function warnedRowCount(?string $kind = null): int
    {
        $warnings = $kind === null
            ? $this->warnings
            : array_filter($this->warnings, static fn (array $warning): bool => $warning['kind'] === $kind);

        return array_sum(array_map(
            static fn (array $warning): int => count($warning['rows']),
            $warnings,
        ));
    }
}
