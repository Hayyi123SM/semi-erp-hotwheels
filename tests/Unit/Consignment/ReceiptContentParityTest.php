<?php

declare(strict_types=1);

namespace Tests\Unit\Consignment;

use App\Enums\PaperSize;
use App\Models\Consignment;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Consignment\ReceiptContent;
use App\Services\Consignment\ReceiptSheet;
use App\Services\Notification\ConsignmentReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kertas dan WhatsApp harus mengulang angka yang sama.
 *
 * Ini test yang jadi alasan `ReceiptContent` ada. Dua medium dibaca oleh orang
 * yang sama pada saat yang sama: penitip memegang struk bertanda tangan sambil
 * pesanani di hp-nya. Kalau hanya salah satu yang keliru, orang itu menemukan
 * dua angka berbeda untuk satu barang dan tidak ada yang bisa bilang mana yang
 * benar.
 *
 * Yang diperiksa di sini setiap angka yang muncul di struk harus bisa ditemukan
 * utuh di body pesan. Arah assertionsnya penting: test ini dimulai dari
 * struk, bukan dari pesan. Kalau dimulai dari pesan, perubahan di pesan yang
 * menghapus angka akan lolos sebagai "tidak ada yang berubah di struk".
 */
class ReceiptContentParityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_number_on_the_paper_appears_in_the_whatsapp_body(): void
    {
        $consignment = $this->consignmentWithLots();

        $body = (new ConsignmentReceipt($consignment))->body();
        $sheet = new ReceiptSheet($consignment, PaperSize::Mm80, $this->printer());

        self::assertStringContainsString($sheet->docNo(), $body, 'Nomor dokumen tidak ada di pesan.');
        self::assertStringContainsString($sheet->consignorName(), $body, 'Nama penitip tidak ada di pesan.');
        self::assertStringContainsString($sheet->consignorCode(), $body, 'Kode penitip tidak ada di pesan.');
        self::assertStringContainsString(
            $consignment->consignment_date->format('d M Y'),
            $body,
            'Tanggal terima tidak ada di pesan.',
        );

        foreach ($sheet->lines() as $line) {
            self::assertStringContainsString($line['sku'], $body, sprintf('SKU %s tidak ada di pesan.', $line['sku']));
            self::assertStringContainsString(
                $line['pcs'].' pcs',
                $body,
                sprintf('Jumlah pcs untuk %s tidak ada di pesan.', $line['sku']),
            );
            self::assertStringContainsString(
                $line['price'],
                $body,
                sprintf('Harga %s untuk %s tidak ada di pesan.', $line['price'], $line['sku']),
            );
        }
    }

    #[Test]
    public function the_totals_on_the_paper_match_the_totals_in_the_message(): void
    {
        $consignment = $this->consignmentWithLots();
        $variables = (new ReceiptContent($consignment))->variables();
        $totals = (new ReceiptSheet($consignment, PaperSize::Mm80, $this->printer()))->totals();

        self::assertSame((string) $totals['items'], $variables['item_count']);
        self::assertSame((string) $totals['pcs'], $variables['qty']);
    }

    #[Test]
    public function the_paper_lists_the_lots_in_the_same_order_as_the_message(): void
    {
        $consignment = $this->consignmentWithLots();

        $fromPaper = array_column((new ReceiptSheet($consignment, PaperSize::Mm80, $this->printer()))->lines(), 'sku');
        $fromMessage = array_map(
            static fn (string $line): string => (string) strtok(ltrim($line, '· '), ' '),
            (new ReceiptContent($consignment))->lines(),
        );

        self::assertSame($fromMessage, $fromPaper);
        self::assertSame(['CN01-HW-001', 'CN01-HW-002', 'CN01-HW-003'], $fromPaper);
    }

    #[Test]
    public function a_variance_note_reaches_both_mediums(): void
    {
        // `variance_note` dikosongkan supaya yang diuji memang catatan yang
        // dihitung dari selisih klaim dan terima. Factory `counted()` mengisinya
        // sendiri, dan dengan begitu test ini diam-diam menguji jalur yang salah.
        $consignment = Consignment::factory()->completed()->counted(10, 8)->create([
            'variance_note' => null,
        ]);
        StockLot::factory()->ownedBy($consignment->consignor)->create([
            'consignment_id' => $consignment->id,
            'sequence' => 1,
            'sku' => 'CN01-HW-001',
        ]);

        self::assertStringContainsString(
            'Penitip mengklaim 10 pcs, diterima 8 pcs.',
            (new ReceiptContent($consignment))->detailText(),
        );
        self::assertStringContainsString(
            'Penitip mengklaim 10 pcs, diterima 8 pcs.',
            (new ConsignmentReceipt($consignment))->body(),
        );
    }

    #[Test]
    public function the_staff_note_wins_over_the_numbers_it_contradicts(): void
    {
        // Klaim dan terima sengaja tidak cocok, dan Staff sudah menulis alasannya
        // saat commit. Catatan itu yang benar: dialah yang memegang barangnya.
        $consignment = Consignment::factory()->completed()->counted(10, 8)->create([
            'variance_note' => '2 blister sobek di tokong, foto dilampirkan.',
        ]);
        StockLot::factory()->ownedBy($consignment->consignor)->create([
            'consignment_id' => $consignment->id,
            'sequence' => 1,
            'sku' => 'CN01-HW-001',
        ]);

        self::assertSame(
            '2 blister sobek di tokong, foto dilampirkan.',
            (new ReceiptContent($consignment))->varianceNote(),
        );
    }

    #[Test]
    public function the_paper_shows_the_store_the_message_names(): void
    {
        $consignment = $this->consignmentWithLots();

        self::assertSame(
            (new ReceiptContent($consignment))->storeName(),
            (new ReceiptSheet($consignment, PaperSize::Mm80, $this->printer()))->storeName(),
        );
    }

    private function consignmentWithLots(): Consignment
    {
        $consignment = Consignment::factory()->completed()->create();
        $consignor = $consignment->consignor;

        foreach ([1 => 5, 2 => 3, 3 => 8] as $sequence => $qty) {
            StockLot::factory()->ownedBy($consignor)->create([
                'consignment_id' => $consignment->id,
                'sequence' => $sequence,
                'sku' => sprintf('CN01-HW-%03d', $sequence),
                'qty_received' => $qty,
                'qty_on_hand' => $qty,
            ]);
        }

        return $consignment->fresh(['consignor', 'stockLots']);
    }

    private function printer(): User
    {
        return User::factory()->staff()->create();
    }
}
