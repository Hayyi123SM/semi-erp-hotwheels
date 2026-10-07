<?php

namespace App\Services\Import;

use App\Models\Consignor;
use App\Models\ProductSeries;
use App\Support\Enums;
use App\Support\ImportSchemas;
use App\Support\Numbers;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ImportManager
{
    private const STORAGE_DISK = 'local';

    private const DIRECTORY = 'imports';

    private ?array $seriesLookup = null;

    private ?array $consignorLookup = null;

    /**
     * Simpan file & metadata ke session, kembalikan token.
     */
    public function begin(UploadedFile $file, string $module, array $rows): string
    {
        $token = Str::random(40);

        Storage::disk(self::STORAGE_DISK)->putFileAs(
            self::DIRECTORY,
            $file,
            $token.'.'.$file->getClientOriginalExtension(),
        );

        session()->put("import.{$token}", [
            'module' => $module,
            'filename' => $file->getClientOriginalName(),
            'rows' => $rows,
            'header_line' => null,
            'preview' => null,
            'created_at' => now()->timestamp,
        ]);

        return $token;
    }

    /**
     * Simpan mapping yang baru saja ditampilkan ke layar validasi.
     *
     * Disimpan, bukan dikirim lagi lewat formulir, karena dua alasan. Pertama,
     * `commit` lalu menulis persis apa yang sudah dilihat dan disetujui orang;
     * kalau mapping ikut lagi di formulir, isi POST bisa berbeda dari yang
     * ditampilkan tanpa jejak. Kedua, `header_row` bisa saja berbeda dari
     * yang terakhir dilihat -- pindah ke halaman pemetaan lalu kembali tidak
     * mengubah apa pun, dan mapping lama harus ikut terbuang.
     */
    public function rememberMapping(string $token, array $mapping): void
    {
        $payload = $this->find($token);

        if ($payload === null) {
            return;
        }

        $payload['preview'] = [
            'mapping' => [
                'header_row' => (int) ($mapping['header_row'] ?? 0),
                'map' => $mapping['map'] ?? [],
                'defaults' => $mapping['defaults'] ?? [],
            ],
            'at' => now()->timestamp,
        ];

        session()->put("import.{$token}", $payload);
    }

    /**
     * Catat baris header yang dipilih, sekalian buang persetujuan yang lama.
     *
     * Dipanggil setiap kali halaman pemetaan dibuka -- bukan hanya ketika
     * baris header berubah -- karena halaman itu adalah tempat semua yang bisa
     * membuat persetujuan basi diubah: baris header, pemetaan kolom, nilai
     * default. Membuang preview hanya saat `header_row` berubah tidak cukup;
     * memetakan ulang kolom tanpa menyentuh baris header sama saja membuat
     * layar validasi yang disetujui orang tidak lagi cocok dengan isinya.
     *
     * Baris headernya ikut disimpan supaya pilihan itu bertahan. Tanpa ini,
     * mundur dari Validasi lewat stepper -- yang menuju URL tanpa query --
     * akan kembali ke baris hasil deteksi, seolah-olah pilihannya tidak pernah
     * ada.
     */
    public function rememberHeaderRow(string $token, int $headerLine): void
    {
        $payload = $this->find($token);

        if ($payload === null) {
            return;
        }

        $payload['header_line'] = $headerLine;
        $payload['preview'] = null;

        session()->put("import.{$token}", $payload);
    }

    /**
     * Mapping yang terakhir kali ditampilkan dan disetujui, kalau ada.
     */
    public function mapping(string $token): ?array
    {
        $mapping = $this->find($token)['preview']['mapping'] ?? null;

        return is_array($mapping) ? $mapping : null;
    }

    public function find(string $token): ?array
    {
        $payload = session()->get("import.{$token}");

        return is_array($payload) ? $payload : null;
    }

    public function destroy(string $token): void
    {
        foreach (['xlsx', 'xls', 'csv'] as $extension) {
            Storage::disk(self::STORAGE_DISK)->delete(self::DIRECTORY.'/'.$token.'.'.$extension);
        }
        session()->forget("import.{$token}");
    }

    /**
     * Bangun hasil normalisasi & validasi seluruh baris dari payload & mapping.
     */
    public function buildResult(string $module, array $payload, array $mapping): ImportResult
    {
        $schema = ImportSchemas::for($module);
        $rows = $payload['rows'];
        $headerIndex = (int) ($mapping['header_row'] ?? 0);

        if ($headerIndex < 0 || $headerIndex >= count($rows)) {
            throw ValidationException::withMessages([
                'header_row' => 'Baris header yang dipilih tidak valid. File mungkin tidak memiliki cukup baris.',
            ]);
        }

        $columnMap = $this->columnIndexes($mapping['map'] ?? [], $rows[$headerIndex]);
        $defaults = array_merge($this->schemaDefaults($schema), $mapping['defaults'] ?? []);
        $result = new ImportResult;

        foreach (array_slice($rows, $headerIndex + 1) as $offset => $row) {
            $excelRow = $headerIndex + $offset + 2; // baris 1-based pada dokumen asli
            $storeRow = [];
            $combined = [];

            foreach ($schema['items'] as $item) {
                $store = $item['store'];
                $typed = $this->typedValue($item, $this->cellValue($row, $item['key'], $columnMap, $defaults));

                if (($item['combineOrder'] ?? null) !== null) {
                    if ($typed !== null) {
                        $combined[$store][] = $typed;
                    }

                    continue;
                }

                $storeRow[$store] = $typed;
            }

            foreach ($combined as $store => $parts) {
                $storeRow[$store] = trim(implode(' ', $parts));
            }

            [$preErrors, $storeRow] = $this->resolveReferences($schema, $storeRow);

            if ($preErrors !== []) {
                $result->addFailure($excelRow, $preErrors);

                continue;
            }

            $validator = Validator::make($storeRow, $this->rulesFor($schema));

            if ($validator->fails()) {
                $result->addFailure($excelRow, array_values($validator->errors()->all()));

                continue;
            }

            $result->addSuccess($storeRow, $excelRow);
        }

        return $result;
    }

    /**
     * Nilai bawaan per kolom (mis. scheme_type = PERCENTAGE) dari skema.
     */
    private function schemaDefaults(array $schema): array
    {
        $defaults = [];

        foreach ($schema['items'] as $item) {
            if (array_key_exists('default', $item)) {
                $defaults[$item['key']] = is_bool($item['default'])
                    ? ($item['default'] ? 'YA' : 'TIDAK')
                    : (string) $item['default'];
            }
        }

        return $defaults;
    }

    private function columnIndexes(array $map, array $headerRow): array
    {
        $indexes = [];
        foreach ($map as $key => $column) {
            if ($column === null || $column === '') {
                continue;
            }

            $index = Coordinate::columnIndexFromString((string) $column) - 1;

            if (array_key_exists($index, $headerRow)) {
                $indexes[$key] = $index;
            }
        }

        return $indexes;
    }

    private function cellValue(array $row, string $key, array $columnMap, array $defaults): ?string
    {
        $value = null;

        if (isset($columnMap[$key])) {
            $raw = $row[$columnMap[$key]] ?? null;

            if (is_scalar($raw) && trim((string) $raw) !== '') {
                $value = (string) $raw;
            }
        }

        $default = $defaults[$key] ?? null;

        if ((($value ?? '') === '') && $default !== null && $default !== '') {
            return (string) $default;
        }

        return $value;
    }

    private function typedValue(array $item, ?string $value): mixed
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return match ($item['type']) {
            'money' => Numbers::integer($value),
            // Read the same way the form is, so a spreadsheet cell holding
            // `1.000` is a thousand here as well. Casting it instead --
            // `(int) '1.000'` is `1` -- is the under-count this replaced,
            // and for a capacity or a year it would be a silent one.
            'integer' => Numbers::integer($value),
            'percentage' => Numbers::rate($value),
            'boolean' => $this->normalizeBoolean($value),
            'tags' => $this->normalizeTags($value),
            'date' => $this->normalizeDate($value),
            'enum' => Enums::fromAny($item['enum'], $value),
            default => trim($value),
        };
    }

    private function resolveReferences(array $schema, array $storeRow): array
    {
        $errors = [];

        foreach ($schema['items'] as $item) {
            if ($item['lookup'] ?? null === null) {
                continue;
            }

            $value = $storeRow[$item['store']] ?? null;

            if ($value === null || $value === '' || is_numeric($value)) {
                continue;
            }

            $resolved = match ($item['lookup']) {
                'series' => $this->seriesLookup()[$value] ?? null,
                'consignor' => $this->consignorLookup()[$value] ?? null,
                default => null,
            };

            if ($resolved === null) {
                $errors[] = "Kolom {$item['label']}: \"{$value}\" tidak ditemukan.";

                continue;
            }

            $storeRow[$item['store']] = $resolved;
        }

        return [$errors, $storeRow];
    }

    private function seriesLookup(): array
    {
        if ($this->seriesLookup === null) {
            $this->seriesLookup = [];
            foreach (ProductSeries::select('id', 'name', 'code')->get() as $series) {
                $this->seriesLookup[$series->code] = $series->id;
                $this->seriesLookup[mb_strtolower($series->name)] = $series->id;
            }
        }

        return $this->seriesLookup;
    }

    private function consignorLookup(): array
    {
        if ($this->consignorLookup === null) {
            $this->consignorLookup = [];
            foreach (Consignor::select('id', 'name', 'consignor_code')->get() as $consignor) {
                $this->consignorLookup[$consignor->consignor_code] = $consignor->id;
                $this->consignorLookup[mb_strtolower($consignor->name)] = $consignor->id;
            }
        }

        return $this->consignorLookup;
    }

    private function rulesFor(array $schema): array
    {
        $rules = [];

        foreach ($schema['items'] as $item) {
            foreach ($item['rules'] ?? [] as $store => $fieldRules) {
                $rules[$store] = $fieldRules;
            }
        }

        return $rules;
    }

    private function normalizeBoolean(string $value): ?bool
    {
        $lower = mb_strtolower(trim($value));

        if (in_array($lower, ['1', 'ya', 'y', 'yes', 'true', 'aktif', 'active', 'on'], true)) {
            return true;
        }

        if (in_array($lower, ['0', 'tidak', 't', 'no', 'false', 'nonaktif', 'inactive', 'off', '-'], true)) {
            return false;
        }

        return true;
    }

    private function normalizeTags(string $value): array
    {
        return collect(preg_split('/[;,]/', $value))
            ->map(fn ($tag) => trim((string) $tag))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeDate(string $value): ?string
    {
        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        $parsed = date_parse($value);

        if (($parsed['error_count'] ?? 1) > 0 || ! ($parsed['year'] ?? null)) {
            return $value; // biarkan rule 'date' yang menolak
        }

        return sprintf('%04d-%02d-%02d', $parsed['year'], $parsed['month'] ?: 1, $parsed['day'] ?: 1);
    }
}
