<?php

declare(strict_types=1);

namespace App\Services\Label;

use App\Models\LabelPrintJob;
use App\Models\Rack;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Menyusun satu halaman cetak berisi semua label yang dipilih.
 *
 * Berbeda dengan `HtmlLabelRenderer`, kelas ini boleh menyentuh model.
 * Pembatasannya jelas: model dipakai hanya untuk membaca data, menyusun HTML
 * tetap diserahkan ke `LabelRenderer`.
 */
final class LabelPage
{
    /**
     * Batas jumlah label per halaman cetak.
     *
     * Halaman ini dirender penuh di memori sebelum dikirim ke browser. Tanpa
     * batas, 200 job dengan 50 salinan akan jadi 10.000 label dan 10.000 QR
     * code. Yang lebih buruk lagi: kalau pemotongan dilakukan diam-diam,
     * operator menekan cetak dan mengira semua label tercetak, padahal tidak.
     * Jadi halaman menolak, bukan memotong.
     */
    public const MAX_LABELS_PER_PAGE = 600;

    public function __construct(
        private readonly LabelRenderer $renderer,
    ) {}

    /**
     * Ukuran datang dari pemanggil, bukan dari kolom `template` milik job.
     *
     * Kolom itu tetap ditulis saat job dibuat, dan itu bukan kelalaian: isinya
     * ukuran yang berlaku waktu itu, sehingga ada catatan kalau ukuran label
     * diubah di tengah jalan. Tapi ukuran itu tidak boleh menentukan cetakan.
     * Antrean label bisa berisi job dari sebelum dan sesudah Owner mengganti
     * ukuran, dan kalau job lama tetap memakai ukuran lamanya, satu halaman
     * cetak berisi dua ukuran kertas berbeda -- yang keluar dari printer akan
     * bergantian, dan operator tidak bisa mengukur gauge-nya.
     *
     * Job yang SUDAH pernah dicetak lalu dicetak ulang memang boleh berubah
     * ukuran. Yang dibekukan di `payload` adalah isi label, bukan ukurannya:
     * isi supaya cetakan kedua persis sama, ukuran supaya operator sedang
     * mencetak pada kertas yang ukurannya memang sudah diganti.
     *
     * @param  Collection<int, LabelPrintJob>  $jobs
     * @param  SheetGrid|null  $grid  `null` untuk mode gulungan
     */
    public function forJobs(Collection $jobs, LabelTemplate $template, ?SheetGrid $grid = null): string
    {
        // Job yang di-print ulang memakai `copies`; job yang gagal lalu dicoba
        // lagi juga `copies`.
        $total = (int) $jobs->sum('copies');

        $this->guardAgainstTooManyLabels($total);

        $labels = [];

        foreach ($jobs as $job) {
            $content = $this->contentFor($job);

            for ($copy = 0; $copy < $job->copies; $copy++) {
                $labels[] = $this->renderer->render($content, $template, $job->show_price);
            }
        }

        return $this->wrap($labels, $grid);
    }

    public function forRack(Rack $rack, LabelTemplate $template, int $copies = 1, ?SheetGrid $grid = null): string
    {
        return $this->forRacks(collect([$rack]), $template, $copies, $grid);
    }

    /**
     * Beberapa rak sekaligus, tetap dalam satu halaman cetak.
     *
     * Label rak untuk satu zona biasanya dicetak sekaligus, jadi
     * masing-masing rak tidak boleh jadi sheet sendiri: operator akan
     * mengganti kertas untuk setiap label.
     *
     * @param  Collection<int, Rack>  $racks
     */
    public function forRacks(Collection $racks, LabelTemplate $template, int $copies = 1, ?SheetGrid $grid = null): string
    {
        if ($copies < 1) {
            throw new LogicException('Jumlah label rak minimal 1.');
        }

        $this->guardAgainstTooManyLabels($racks->count() * $copies);

        $labels = [];

        foreach ($racks as $rack) {
            for ($copy = 0; $copy < $copies; $copy++) {
                $labels[] = $this->renderer->renderRack($rack->code, $template);
            }
        }

        return $this->wrap($labels, $grid);
    }

    /**
     * Uji cetak (FR-IB-25): contoh label untuk mengukur gauge printer.
     *
     * Tidak menyentuh lot, job, atau stok -- kalau tidak, setiap kali operator
     * mengatur jarak label, sistem akan ikut mencatat cetakan palsu. Isinya
     * berasal dari `LabelContent::sample()` yang sengaja memakai kasus terburuk
     * supaya masalah layout ketahuan di printer, bukan nanti di rak.
     */
    public function forTestPrint(LabelTemplate $template, int $copies = 1, bool $showPrice = true, ?SheetGrid $grid = null): string
    {
        if ($copies < 1) {
            throw new LogicException('Jumlah label uji minimal 1.');
        }

        $this->guardAgainstTooManyLabels($copies);

        $content = LabelContent::sample();
        $labels = array_fill(0, $copies, $this->renderer->render($content, $template, $showPrice));

        return $this->wrap($labels, $grid);
    }

    /**
     * Job lama yang sudah pernah dicetak memakai snapshot `payload`, bukan
     * data lot sekarang. Kalau tidak, re-print karena `PRICE_CHANGE` bisa
     * diam-diam menghasilkan label yang sama dengan cetakan pertama, dan
     * alasan re-print-nya jadi tidak berarti.
     */
    private function contentFor(LabelPrintJob $job): LabelContent
    {
        $payload = $job->payload;

        if (is_array($payload) && $payload !== [] && isset($payload['sku'])) {
            return LabelContent::fromPayload($payload);
        }

        return LabelContent::fromLot($job->lot);
    }

    /**
     * @param  list<string>  $labels
     */
    private function wrap(array $labels, ?SheetGrid $grid = null): string
    {
        if ($grid === null) {
            return '<div class="label-sheet">'.implode('', $labels).'</div>';
        }

        return $this->gridSheets($labels, $grid);
    }

    /**
     * Menolak cetakan yang terlalu banyak label.
     *
     * Dicek dari jumlah salinan yang diminta, sebelum satu label pun dirender.
     * 40.000 label tetap harus ditolak -- tapi ditolak setelah membangun 40.000
     * QR code di memori hanya memperpanjang waktu tunggu tanpa mengubah
     * jawabannya.
     */
    private function guardAgainstTooManyLabels(int $total): void
    {
        if ($total <= self::MAX_LABELS_PER_PAGE) {
            return;
        }

        throw new LogicException(sprintf(
            'Jumlah label (%d) melebihi batas %d per halaman. Cetak per kelompok yang lebih kecil.',
            $total,
            self::MAX_LABELS_PER_PAGE,
        ));
    }

    /**
     * Menypi label ke beberapa lembar stiker.
     *
     * Pembulatan di sini, bukan disembunyikan: 120 label pada lembar 48 label
     * menghasilkan tiga lembar (48 + 48 + 24), dan lembar terakhir memang
     * setengah kosong. Itu pilihan yang lebih aman daripada memotong jadi 48 + 48
     * lalu 24 label sisanya "nanti saja" -- operator yang menekan cetak sekali
     * akan mendapatkan semuanya, dan jumlah lembarnya tertera di badge halaman.
     *
     * `@page` dipegang `label-print.blade.php` dari geometri yang sama, jadi
     * ukuran kertas di dialog cetak dan isi lembar tidak mungkin berbeda.
     *
     * @param  list<string>  $labels
     */
    private function gridSheets(array $labels, SheetGrid $grid): string
    {
        $perSheet = $grid->labelsPerSheet();

        // `LabelSheetSettings::usableGridFor()` sudah menolak grid yang tidak
        // bisa dicetak, jadi ini jaring pengaman, bukan jalur normal. Pesannya
        // tetap menyebut angkanya: kalau sampai sini, yang perlu diperbaiki
        // Owner adalah ukuran kertasnya, bukan programnya.
        if ($perSheet < 1) {
            throw new LogicException(sprintf(
                'Grid %s tidak punya kolom atau baris yang bisa dipakai untuk label %s.',
                $grid->summary(),
                SheetGrid::mm($grid->labelWidthMm).' x '.SheetGrid::mm($grid->labelHeightMm).' mm',
            ));
        }

        $chunks = array_chunk($labels, $perSheet);
        $total = count($chunks);
        $style = $grid->gridStyle();
        $sheets = [];

        foreach ($chunks as $index => $chunk) {
            $sheets[] = sprintf(
                '<div class="label-sheet label-sheet--grid" style="%s" data-sheet="%d" data-sheet-total="%d">%s</div>',
                $style,
                $index + 1,
                $total,
                implode('', $chunk),
            );
        }

        return implode('', $sheets);
    }
}
