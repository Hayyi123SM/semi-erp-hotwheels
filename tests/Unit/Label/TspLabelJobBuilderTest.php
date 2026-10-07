<?php

declare(strict_types=1);

namespace Tests\Unit\Label;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\OwnerType;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\StockLot;
use App\Services\Label\LabelContent;
use App\Services\Label\LabelTemplate;
use App\Services\Label\SheetGrid;
use App\Services\Label\TspLabelJob;
use App\Services\Label\TspLabelJobBuilder;
use App\Services\Label\TspLabelRenderer;
use App\Services\Label\TspLabelSpec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test yang menjaga builder lembar menghasilkan urutan perintah TSPL yang
 * benar dari kotak kosong, bukan dari harapan yang menipu diri sendiri.
 *
 * Focusnya adalah bentuk: `SIZE`/`GAP` sesuai media, tiap lembar dibuka
 * `CLS` dan \u201cPRINT 1\u201d sekali, dan setiap label dipindah ke sel
 * grid-nya. Kalau urutan ini kacau, printer bisa mencetak seluruh lembar
 * di atas lembar sebelumnya -- dan tidak ada dialog print browser yang
 * memberi kesempatan kedua untuk menangkapnya.
 */
class TspLabelJobBuilderTest extends TestCase
{
    /**
     * Grid 6 x 8 yang ekuivalen dengan blueprint `BpTd110BtA6`.
     */
    private function grid(): SheetGrid
    {
        return new SheetGrid(
            mediaWidthMm: 100.0,
            mediaHeightMm: 150.0,
            labelWidthMm: 15.0,
            labelHeightMm: 15.0,
            gapMm: 2.0,
            columns: 6,
            rows: 8,
            usedWidthMm: 100.0,
            usedHeightMm: 134.0,
            slackWidthMm: 0.0,
            slackHeightMm: 16.0,
            rejections: [],
            warnings: [],
        );
    }

    /**
     * @return list<TspLabelSpec>
     */
    private function specs(int $count): array
    {
        $lot = new StockLot([
            'sku' => 'CN01-HW-001-U03',
            'owner_code' => 'cn01',
            'owner_type' => OwnerType::Consign,
            'card_condition' => CardCondition::NearMint,
            'blister_condition' => BlisterCondition::Clear,
            'list_price' => 50_000,
            'qty_received' => 12,
        ]);
        $lot->setRelation('product', new Product(['name' => 'Ferrari F40']));
        $lot->setRelation('consignment', new Consignment);
        $lot->setRelation('consignor', new Consignor(['name' => 'Budi Santoso']));

        $content = LabelContent::fromLot($lot);

        return array_map(
            fn () => new TspLabelSpec($content, showPrice: true),
            range(1, $count),
        );
    }

    private function builder(): TspLabelJobBuilder
    {
        return new TspLabelJobBuilder(new TspLabelRenderer);
    }

    #[Test]
    public function one_sheet_when_filled_exactly(): void
    {
        $job = $this->builder()->build($this->specs(48), LabelTemplate::QrOnly, grid: $this->grid());

        $this->assertInstanceOf(TspLabelJob::class, $job);
        $this->assertSame(1, $job->sheets);
        $this->assertSame(48, $job->total);
        $this->assertSame(['48 label'], $job->sheetSummaries);

        $lines = $this->lines($job);

        $this->assertContains('SIZE 100 mm,150 mm', $lines);
        $this->assertContains('GAP 2 mm,0 mm', $lines);
        $this->assertSame(1, count(array_keys($lines, 'CLS', true)));
        $this->assertSame(1, count(array_keys($lines, 'PRINT 1', true)));
        $this->assertSame(48, count($this->commandsWith($lines, 'QRCODE')));
        // 48 label x (1 QR + 2 teks).
        $this->assertSame(96, count($this->commandsWith($lines, 'TEXT')));
    }

    #[Test]
    public function spills_into_second_sheet_when_over_capacity(): void
    {
        $job = $this->builder()->build($this->specs(49), LabelTemplate::QrOnly, grid: $this->grid());

        $this->assertSame(2, $job->sheets);
        $this->assertSame(['48 label', '1 label'], $job->sheetSummaries);

        $lines = $this->lines($job);

        $this->assertSame(2, count(array_keys($lines, 'CLS', true)));
        $this->assertSame(2, count(array_keys($lines, 'PRINT 1', true)));
        $this->assertSame(49, count($this->commandsWith($lines, 'QRCODE')));
    }

    #[Test]
    public function sheet_offset_moves_each_label_into_its_grid_cell(): void
    {
        $job = $this->builder()->build($this->specs(48), LabelTemplate::QrOnly, grid: $this->grid());

        $qr = $this->qrCommands($this->lines($job));

        // Sel pojok kiri-atas tidak digeser.
        $this->assertSame('QRCODE 31,5,M,2,A,0,"CN01-HW-001-U03"', $qr[0]);

        // Sel terakhir (kolom 5, baris 7): pitch 17 mm = 136 dot.
        $this->assertSame('QRCODE 711,957,M,2,A,0,"CN01-HW-001-U03"', $qr[47]);
    }

    #[Test]
    public function roll_mode_prints_each_label_as_its_own_size_and_print_cycle(): void
    {
        $job = $this->builder()->build($this->specs(3), LabelTemplate::QrOnly);

        $this->assertSame(3, $job->sheets);
        $this->assertSame(3, $job->total);
        $this->assertSame(['1 label', '1 label', '1 label'], $job->sheetSummaries);

        $lines = $this->lines($job);

        $this->assertSame(3, count(array_keys($lines, 'SIZE 15 mm,15 mm', true)));
        $this->assertSame(3, count(array_keys($lines, 'PRINT 1', true)));
        // Tidak ada GAP di mode gulungan.
        $this->assertSame(0, count($this->commandsWith($lines, 'GAP')));

        // QR tidak ideser target offset (roll), tetap di koordinat label.
        $this->assertSame('QRCODE 31,5,M,2,A,0,"CN01-HW-001-U03"', $this->qrCommands($lines)[0]);
    }

    #[Test]
    public function commands_never_carry_quotes_that_could_break_tspl(): void
    {
        $job = $this->builder()->build($this->specs(1), LabelTemplate::ThreeByTwo);

        foreach ($this->lines($job) as $line) {
            if (str_starts_with($line, 'TEXT')) {
                // x,y,"font",rot,x,y,"teks" => tepat 4 tanda kutip.
                $this->assertSame(4, substr_count($line, '"'), $line);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function lines(TspLabelJob $job): array
    {
        return explode("\n", trim($job->text));
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function commandsWith(array $lines, string $prefix): array
    {
        return array_values(array_filter(
            $lines,
            fn (string $line) => str_starts_with($line, $prefix),
        ));
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function qrCommands(array $lines): array
    {
        return $this->commandsWith($lines, 'QRCODE');
    }
}
