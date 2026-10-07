<?php

declare(strict_types=1);

namespace App\Services\Label;

/**
 * Satu label yang diminta untuk cetak langsung TSPL.
 *
 * `showPrice` disimpan eksplisit di sini -- bukan dibaca dari dalam
 * `LabelContent` -- karena keputusan itu milik job (`label_print_jobs`),
 * bukan milik isi label.
 */
final readonly class TspLabelSpec
{
    public function __construct(
        public LabelContent $content,
        public bool $showPrice,
    ) {}
}
