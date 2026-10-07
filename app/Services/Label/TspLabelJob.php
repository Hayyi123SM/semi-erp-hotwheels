<?php

declare(strict_types=1);

namespace App\Services\Label;

/**
 * Satu pekerjaan cetak TSPL siap kirim.
 *
 * Teks lengkapnya (`text`) sudah berisi semua perintah dari `SIZE` sampai
 * `PRINT`, termasuk pengulangan `CLS` per lembar stiker. `sheets` dan `total`
 * dipakai UI untuk memberi tahu operator berapa lembar yang akan dipakai.
 */
final readonly class TspLabelJob
{
    /**
     * @param  list<string>  $sheetSummaries  ringkasan per lembar, untuk badge
     */
    public function __construct(
        public string $text,
        public int $sheets,
        public int $total,
        public array $sheetSummaries = [],
    ) {}
}
