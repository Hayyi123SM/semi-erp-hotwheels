<?php

declare(strict_types=1);

namespace App\Services\Label;

/**
 * Keputusan tata letak untuk satu cetakan: kertas apa, halaman seberapa besar,
 * dan label ditata bagaimana.
 *
 * Ada karena ukuran halaman tidak boleh diputuskan di tiga tempat berbeda.
 * Ukuran itu sendiri harus berasal dari template label (mode gulungan) atau dari
 * grid di atas kertas (mode stiker), dan `@page` harus sama persis dengan grid
 * yang benar-benar dirender. Kalau tiap controller menghitung sendiri, satu yang
 * lupa memperbarui akan menghasilkan dialog cetak menawarkan kertas 3x2 cm untuk 48
 * stiker 100x150 mm.
 *
 * `$grid` bernilai `null` berarti mode gulungan, jadi pemanggil cukup
 * memeriksa `$layout->isSheetGrid()` tanpa tahu apa pun tentang mode.
 */
final readonly class LabelPaperLayout
{
    public function __construct(
        public LabelTemplate $template,
        public ?SheetGrid $grid,
    ) {}

    /**
     * Ukuran halaman untuk `@page`, dalam CSS.
     */
    public function pageSizeCss(): string
    {
        return $this->grid?->pageSizeCss() ?? $this->template->pageSizeCss();
    }

    /**
     * Apakah cetakan ini digabung ke grid di atas kertas.
     *
     * Satu-satunya yang perlu diketahui pemanggil soal mode: `false` berarti satu
     * label satu halaman, `true` berarti label ditata ke kertas dan satu lembar
     * satu halaman.
     */
    public function isSheetGrid(): bool
    {
        return $this->grid !== null;
    }

    /**
     * Berapa label yang keluar per halaman cetak.
     *
     * Selalu 1 pada mode gulungan, meskipun satu cetak bisa menghasilkan beberapa
     * halaman sekaligus: yang dihitung di sini per halaman, bukan per dokumen.
     */
    public function labelsPerPage(): int
    {
        return $this->grid?->labelsPerSheet() ?? 1;
    }

    /**
     * Satu baris ringkas untuk badge di halaman cetak.
     *
     * Yang ditulis operator ke kertas bukan "mode: sheet", tapi "berapa label
     * yang keluar per halaman" dan "kertasnya seberapa besar" -- dua hal yang
     * harus dicocokkan dengan isi laci di depan printer.
     */
    public function summary(): string
    {
        if ($this->grid === null) {
            return sprintf('Gulungan · %s · 1 label per halaman', $this->template->label());
        }

        return 'Stiker · '.$this->grid->summary();
    }
}
