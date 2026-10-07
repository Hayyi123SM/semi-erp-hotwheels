<?php

declare(strict_types=1);

namespace App\Services\Label;

/**
 * Gambaran satu label TSPL: ukuran kertasnya dan semua operasi gambarnya.
 *
 * Ukuran ikut dikirim karena istensi dibaca oleh builder lembar untuk
 * menghitung posisi sel di atas kertas stiker (offset kolom/baris). Renderer
 * dan builder memakai angka dari sumber yang sama, jadi tidak ada dua tempat
 * yang menebak ukuran label.
 */
final readonly class TspLabelBlock
{
    /**
     * @param  list<TspLabelOp>  $ops
     */
    public function __construct(
        public int $widthDots,
        public int $heightDots,
        public array $ops,
    ) {}
}
