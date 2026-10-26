<?php

namespace App\Services\Import;

use App\Support\Format;

/**
 * Menangani berkas Inbound yang memakai sel merge Excel.
 *
 * Penulis berkas sering menggabungkan blok identitas produk (Kategori / Seri,
 * Nama Produk, Warna, Kode Casting) yang menaungi beberapa baris data, lalu
 * mengisi kolom satuan seperti qty pada tiap barisnya. Setelah dibaca,
 * baris-baris lanjutan itu terlihat identitasnya kosong -- bukan berarti
 * rusak, melainkan lanjutan dari baris induk di atasnya.
 *
 * Dua tahap dikerjakan berurutan:
 *
 * 1. `fillDown()` -- sebelum validasi. Menandai tiap baris sebagai baris
 *    induk atau baris lanjutan, lalu mewariskan sel kosong baris lanjutan
 *    dari sel induknya. Pekerjaan ini dilakukan di level sel mentah, SEBELUM
 *    default skema diambil, supaya kolom yang nilainya di-merge seperti
 *    "Tgl Masuk" tidak malah jatuh ke default hari ini dan membuat baris
 *    tidak dikenali sebagai satu kelompok.
 *
 * 2. `consolidate()` -- sesudah validasi. Menyatukan baris yang empat
 *    penentunya sama: Kategori / Seri, produk, warna, dan harga. Total qty
 *    dijumlahkan walau baris-baris itu menulis ulang data yang sama tanpa
 *    sel merge. Kolom lain (rak, tgl masuk, kondisi) ikut dari baris
 *    pertama kelompok; hanya perbedaan empat penentu itu yang menandai
 *    baris sebagai tidak bisa digabung.
 */
final class InboundRowMerger
{
    /**
     * Satu-satunya kolom yang nilainya dijumlahkan, bukan diwariskan.
     */
    private const QTY_KEY = 'qty';

    /**
     * Empat penentu identitas satu baris inbound: Kategori / Seri, produk,
     * warna, dan harga -- Harga (HPP) untuk Stock In Pribadi, Harga Jual
     * untuk Consignment In. Kolom yang tidak ada pada modul berjalan gugur
     * dengan sendirinya saat kunci dibangun dari isi baris.
     */
    private const MERGE_KEYS = ['series_name', 'product_id', 'color', 'cost_price', 'list_price'];

    public function __construct(
        private readonly string $emptyFirstRowMessage = 'Baris pertama tidak boleh kosong kolom identitasnya (Kategori / Seri, Nama Produk, Warna, Kode Casting); baris tersebut tidak bisa ditebak lanjutan dari siapa.',
    ) {}

    /**
     * Tandai baris induk vs baris lanjutan, lalu wariskan sel kosong.
     *
     * @param  array<int, array<int, mixed>>  $rows  Baris data (tanpa baris header), sel berposisi 0-based.
     * @param  array<string, int>  $columnMap  Kunci item skema => indeks kolom 0-based.
     * @param  list<array<string, mixed>>  $items  Item skema modul.
     * @return array{rows: list<array{excelRow: int, anchorRow: int, cells: array<int, mixed>}>, failures: list<array{row: int, errors: array<int, string>}>}
     */
    public function fillDown(array $rows, int $firstExcelRow, array $columnMap, array $items): array
    {
        $identityKeys = $this->keysWhere($items, static fn (array $item): bool => (bool) ($item['identity'] ?? false));

        if ($identityKeys === []) {
            return ['rows' => [], 'failures' => []];
        }

        $inheritableKeys = $this->keysWhere($items, static fn (array $item): bool => $item['key'] !== self::QTY_KEY);

        $output = [];
        $failures = [];
        $anchorRow = null;
        $anchorCells = [];

        foreach (array_values($rows) as $offset => $cells) {
            $excelRow = $firstExcelRow + $offset;

            if (! $this->identityIsEmpty($cells, $columnMap, $identityKeys)) {
                $anchorRow = $excelRow;
                $anchorCells = $cells;
                $output[] = ['excelRow' => $excelRow, 'anchorRow' => $excelRow, 'cells' => $cells];

                continue;
            }

            if ($anchorRow === null) {
                $failures[] = ['row' => $excelRow, 'errors' => [$this->emptyFirstRowMessage]];

                continue;
            }

            $output[] = [
                'excelRow' => $excelRow,
                'anchorRow' => $anchorRow,
                'cells' => $this->inherit($cells, $anchorCells, $columnMap, $inheritableKeys),
            ];
        }

        return ['rows' => $output, 'failures' => $failures];
    }

    /**
     * Satukan baris yang empat penentunya sama (kecuali qty) menjadi satu
     * baris berisi total qty, apa pun asalnya: baris lanjutan dari sel merge
     * Excel atau baris biasa yang menulis ulang data yang sama.
     *
     * Bila ada kelompok yang berbeda di antara empat penentu, kelompok
     * pertama jadi acuan dan sisanya tetap jadi baris tersendiri -- tapi
     * hanya untuk produk yang sama, karena dua produk berbeda memang wajar
     * bila kolomnya berbeda. Bedanya ditandai sebuah peringatan: datanya
     * valid, hanya tidak disatu.
     */
    public function consolidate(ImportResult $result, array $items): void
    {
        $consolidated = [];

        foreach ($this->groupIndexesByContent($result->rows) as $indexes) {
            $consolidated[] = $this->mergePartition($result->rows, $indexes);
        }

        $result->rows = $consolidated;

        $this->warnDivergentGroups($result, $items);
    }

    /**
     * @param  array<int, array{row: int, anchorRow: int, sourceRows: array<int, int>, label: ?string, data: array<string, mixed>}>  $rows
     * @return array<int, array<int, int>>
     */
    private function groupIndexesByContent(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $index => $row) {
            $grouped[$this->contentKey($row['data'])][] = $index;
        }

        return array_values($grouped);
    }

    /**
     * Isi sama berarti produk sama, jadi perbedaan di antaranya wajar untuk
     * diberi tanda. Kelompok dengan produk berbeda dilewatkan: kolomnya memang
     * tidak seharusnya sama.
     *
     * @param  array<int, array{row: int, anchorRow: int, sourceRows: array<int, int>, label: ?string, data: array<string, mixed>}>  $rows
     * @return array<int, array<int, int>>
     */
    private function groupIndexesByProduct(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $index => $row) {
            $grouped[serialize($row['data']['product_id'] ?? null)][] = $index;
        }

        return array_values($grouped);
    }

    private function warnDivergentGroups(ImportResult $result, array $items): void
    {
        foreach ($this->groupIndexesByProduct($result->rows) as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            $acuan = $result->rows[$indexes[0]];

            foreach (array_slice($indexes, 1) as $index) {
                $messages = $this->differenceMessages($result->rows[$index], $acuan, $items);

                if ($messages !== []) {
                    $result->addWarning($result->rows[$index]['sourceRows'], $messages, ImportResult::KIND_MERGE);
                }
            }
        }
    }

    /**
     * @param  array<int, array{row: int, anchorRow: int, sourceRows: array<int, int>, label: ?string, data: array<string, mixed>}>  $rows
     * @param  array<int, int>  $indexes
     * @return array{row: int, anchorRow: int, sourceRows: array<int, int>, label: ?string, data: array<string, mixed>}
     */
    private function mergePartition(array $rows, array $indexes): array
    {
        $merged = $rows[$indexes[0]];

        foreach ($indexes as $index) {
            $merged['sourceRows'] = array_merge($merged['sourceRows'], $rows[$index]['sourceRows']);
        }

        $merged['sourceRows'] = $this->sortedUnique($merged['sourceRows']);
        $merged['data'][self::QTY_KEY] = array_sum(array_map(
            static fn (int $index): int => (int) $rows[$index]['data'][self::QTY_KEY],
            $indexes
        ));

        return $merged;
    }

    /**
     * Kunci pengelompokan: empat penentu identitas baris (lihat
     * `MERGE_KEYS`); qty sengaja tidak ikut karena selalu dijumlahkan.
     * String dinormalkan supaya beda spasi atau kapital tidak memecah
     * kelompok yang isinya sama. `ksort` supaya dua baris dengan isi sama
     * tetap dianggap sama walau urutan kolomnya beda.
     *
     * Nilai kosong (`null`, `''`, atau kunci yang tidak ada) diseragamkan
     * jadi `null` agar baris dengan nama+harga sama bisa digabung meski
     * seri/warna dikosongkan.
     */
    private function contentKey(array $data): string
    {
        $keyed = [];

        foreach (self::MERGE_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                $keyed[$key] = null;

                continue;
            }

            $value = $data[$key];

            if ($value === null || $value === '') {
                $keyed[$key] = null;

                continue;
            }

            if (is_string($value)) {
                $normalized = mb_strtolower(trim($value));

                if ($normalized === '') {
                    $keyed[$key] = null;

                    continue;
                }

                $keyed[$key] = $normalized;

                continue;
            }

            if (is_bool($value)) {
                $keyed[$key] = $value;

                continue;
            }

            if (is_int($value) || is_float($value)) {
                $keyed[$key] = $value;

                continue;
            }

            $keyed[$key] = $value;
        }

        ksort($keyed);

        return serialize($keyed);
    }

    /**
     * @param  array{row: int, sourceRows: array<int, int>, label: ?string, data: array<string, mixed>}  $divergent
     * @param  array{row: int, sourceRows: array<int, int>, label: ?string, data: array<string, mixed>}  $acuan
     * @param  list<array<string, mixed>>  $items
     * @return array<int, string>
     */
    private function differenceMessages(array $divergent, array $acuan, array $items): array
    {
        // Data hasil validasi disimpan per `store`, jadi label dan tipe
        // kolomnya pun harus dicari per `store` -- bukan per `key`.
        $labels = array_column($items, 'label', 'store');
        $types = array_column($items, 'type', 'store');
        $differences = [];

        foreach ($divergent['data'] as $key => $value) {
            if ($key === self::QTY_KEY || $acuan['data'][$key] === $value) {
                continue;
            }

            $differences[] = sprintf(
                '%s berbeda: %s vs %s',
                $labels[$key] ?? $key,
                $this->display($value, $types[$key] ?? null),
                $this->display($acuan['data'][$key] ?? null, $types[$key] ?? null),
            );
        }

        if ($differences === []) {
            return [];
        }

        return [sprintf(
            'Baris %s tidak digabung dengan baris %s karena %s.',
            Format::rowRange($divergent['sourceRows']),
            Format::rowRange($acuan['sourceRows']),
            implode(', ', $differences)
        )];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  callable(array<string, mixed>): bool  $predicate
     * @return array<int, string>
     */
    private function keysWhere(array $items, callable $predicate): array
    {
        return array_values(array_map(
            static fn (array $item): string => $item['key'],
            array_filter($items, $predicate)
        ));
    }

    /**
     * @param  array<int, mixed>  $cells
     * @param  array<string, int>  $columnMap
     * @param  array<int, string>  $keys
     */
    private function identityIsEmpty(array $cells, array $columnMap, array $keys): bool
    {
        foreach ($keys as $key) {
            $column = $columnMap[$key] ?? null;

            if ($column === null) {
                continue;
            }

            if (! $this->isBlank($cells[$column] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $cells
     * @param  array<int, mixed>  $anchorCells
     * @param  array<string, int>  $columnMap
     * @param  array<int, string>  $keys
     * @return array<int, mixed>
     */
    private function inherit(array $cells, array $anchorCells, array $columnMap, array $keys): array
    {
        foreach ($keys as $key) {
            $column = $columnMap[$key] ?? null;

            if ($column === null || ! $this->isBlank($cells[$column] ?? null)) {
                continue;
            }

            $cells[$column] = $anchorCells[$column] ?? null;
        }

        return $cells;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function display(mixed $value, ?string $type): string
    {
        if ($type === 'money') {
            return Format::rupiah(is_numeric($value) ? (int) $value : null);
        }

        if ($value === null || $value === '') {
            return '(kosong)';
        }

        return is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<int, int>  $rows
     * @return array<int, int>
     */
    private function sortedUnique(array $rows): array
    {
        $rows = array_values(array_unique(array_map('intval', $rows)));
        sort($rows);

        return $rows;
    }
}
