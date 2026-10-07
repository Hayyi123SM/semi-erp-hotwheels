<?php

declare(strict_types=1);

namespace App\Services\Label;

use LogicException;

/**
 * Menyusun perintah TSPL lengkap untuk daftar label.
 *
 * Pemilik baris perintah (sintaks) ada di sini, bukan di `TspLabelRenderer`:
 * renderer hanya menghasilkan operasi gambar per label, dan syntax `SIZE`,
 * `GAP`, `CLS`, `PRINT` serta pergeseran koordinat ke sel grid adalah urusan
 * lembar -- pemindahan operasi ke sel adalah transformasi geometri yang tidak
 * boleh ditelusuri perintah demi perintah.
 *
 * Mode kertas mengubah bentuk output:
 *  - **Stiker**: satu lembar `SIZE`=media, `GAP`, semua sel digambar, lalu
 *    `PRINT 1`. Label dipecah ke beberapa lembar bila lebih dari kapasitas.
 *  - **Gulungan**: satu `SIZE`=ukuran label per label; setiap label =
 *    `CLS` + isi + `PRINT 1`.
 *
 * Semua angka keluar dalam dot kelipatan 0,125 mm (203 dpi) -- satuan yang
 * benar-benar digambar printer, bukan angka bulat yang menyenangkan.
 */
final class TspLabelJobBuilder
{
    public function __construct(
        private readonly TspLabelRenderer $renderer,
    ) {}

    /**
     * @param  list<TspLabelSpec>  $specs  dalam urutan yang diminta operator
     * @param  float|null  $qrSideCm  sisi QR dari pengaturan, `null` = bawaan
     */
    public function build(array $specs, LabelTemplate $template, ?float $qrSideCm = null, ?SheetGrid $grid = null): TspLabelJob
    {
        $blocks = array_map(
            fn (TspLabelSpec $spec): TspLabelBlock => $this->renderer->render(
                $spec->content,
                $template,
                $spec->showPrice,
                $qrSideCm,
            ),
            $specs,
        );

        if ($grid === null) {
            return $this->rollJob($blocks);
        }

        return $this->sheetJob($blocks, $grid);
    }

    /**
     * @param  list<TspLabelBlock>  $blocks
     */
    private function sheetJob(array $blocks, SheetGrid $grid): TspLabelJob
    {
        $perSheet = $grid->labelsPerSheet();

        if ($perSheet < 1 || ! $grid->isPrintable()) {
            throw new LogicException(sprintf(
                'Grid %s tidak punya kolom atau baris yang bisa dicetak.',
                $grid->summary(),
            ));
        }

        $lines = [
            sprintf('SIZE %s mm,%s mm', self::mm($grid->mediaWidthMm), self::mm($grid->mediaHeightMm)),
        ];

        if ($grid->gapMm > 0.0) {
            $lines[] = sprintf('GAP %s mm,0 mm', self::mm($grid->gapMm));
        }

        $columns = $grid->columns;
        $pitchX = $this->pitchDots($grid->labelWidthMm, $grid->gapMm);
        $pitchY = $this->pitchDots($grid->labelHeightMm, $grid->gapMm);

        $chunks = array_chunk($blocks, $perSheet);
        $summaries = [];

        foreach ($chunks as $chunk) {
            $lines[] = 'CLS';

            foreach ($chunk as $index => $block) {
                $col = $index % $columns;
                $row = intdiv($index, $columns);

                $this->appendBlock($lines, $block, $col * $pitchX, $row * $pitchY);
            }

            $lines[] = 'PRINT 1';
            $summaries[] = sprintf('%d label', count($chunk));
        }

        return new TspLabelJob(
            text: implode("\n", $lines)."\n",
            sheets: count($chunks),
            total: count($blocks),
            sheetSummaries: $summaries,
        );
    }

    /**
     * @param  list<TspLabelBlock>  $blocks
     */
    private function rollJob(array $blocks): TspLabelJob
    {
        $lines = [];
        $summaries = [];

        foreach ($blocks as $block) {
            $lines[] = sprintf(
                'SIZE %s mm,%s mm',
                self::mm($block->widthDots / TspLabelRenderer::DOTS_PER_MM),
                self::mm($block->heightDots / TspLabelRenderer::DOTS_PER_MM),
            );
            $lines[] = 'CLS';
            $this->appendBlock($lines, $block, 0, 0);
            $lines[] = 'PRINT 1';
            $summaries[] = '1 label';
        }

        return new TspLabelJob(
            text: implode("\n", $lines)."\n",
            sheets: count($blocks),
            total: count($blocks),
            sheetSummaries: $summaries,
        );
    }

    /**
     * Tulis operasi sebuah blok ke daftar baris, setelah menggeser ke posisi
     * sel (dx, dy dalam dot).
     *
     * @param  list<string>  $lines
     */
    private function appendBlock(array &$lines, TspLabelBlock $block, int $dx, int $dy): void
    {
        foreach ($block->ops as $op) {
            $offset = $op->withOffset($dx, $dy);

            if ($offset->kind === TspLabelOp::QR) {
                $lines[] = sprintf(
                    'QRCODE %d,%d,M,%d,A,0,"%s"',
                    $offset->x,
                    $offset->y,
                    $offset->cell,
                    $offset->text,
                );

                continue;
            }

            $lines[] = sprintf(
                'TEXT %d,%d,"%s",0,%d,%d,"%s"',
                $offset->x,
                $offset->y,
                $offset->font,
                $offset->multiplier,
                $offset->multiplier,
                $offset->text,
            );
        }
    }

    private function pitchDots(float $labelMm, float $gapMm): int
    {
        return (int) round(($labelMm + $gapMm) * TspLabelRenderer::DOTS_PER_MM);
    }

    /**
     * Angka milimeter tanpa nol di belakang koma, untuk `SIZE`/`GAP`.
     */
    private static function mm(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
