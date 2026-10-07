<?php

declare(strict_types=1);

namespace App\Services\Label;

/**
 * Mengubah isi label menjadi HTML siap cetak.
 *
 * Sengaja tanpa argumen HTTP, model, atau query: implementasi harus bisa diuji
 * tanpa database dan tanpa prepares, karena label adalah bagian yang paling
 * sulit dicek setelah keluar dari printer.
 */
interface LabelRenderer
{
    /**
     * Satu potong HTML untuk satu label.
     *
     * @param  bool  $showPrice  Apakah harga ikut dicetak. Toggle ini
     *                           dijadikan parameter eksplisit, bukan
     *                           disembunyikan di dalam renderer, supaya aturan
     *                           "harga di sistem adalah sumber kebenaran"
     *                           (FR-IB-23) punya satu tempat yang jelas.
     */
    public function render(LabelContent $content, LabelTemplate $template, bool $showPrice = true): string;

    /**
     * Label rak, berawalan `RK:` supaya tidak tertukar dengan SKU saat scan.
     */
    public function renderRack(string $rackCode, LabelTemplate $template): string;
}
