<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConsignmentStatus;
use App\Enums\PaperSize;
use App\Models\AuditLog;
use App\Models\Consignment;
use App\Models\Setting;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Print\PrintSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Halaman cetak bukti terima dan catatan serah terima.
 *
 * Tiga hal yang dijaga di sini, dan ketiganya soal sesuatu yang tidak terlihat
 * di test view biasa:
 *
 * 1. `@page` ikut benar untuk setiap kertas. Ukuran yang salah tidak merusak
 *    tampilan, dan gejalanya baru muncul di depan printer: struk 80 mm keluar
 *    tercetak di tengah A4, lalu pemotong thermal memotong seluruh A4 itu.
 *
 * 2. Halaman `GET` tidak pernah menulis apa pun. Kalau pencetakan dihitung
 *    dari sini, satu muat ulang karena koneksi putus akan menambah satu catatan,
 *    dan tidak ada yang bisa melihat jumlah aslinya lagi.
 *
 * 3. Draf dan dokumen yang dibatalkan tidak bisa dicetak. Kertas yang
 *    menyatakan barang sudah masuk, untuk barang yang belum pernah masuk, lebih
 *    buruk daripada tidak ada kertas sama sekali.
 */
class ConsignmentReceiptPrintTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_page_carries_the_numbers_of_the_document(): void
    {
        $consignment = $this->completedWithLots();

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.consignment-in.bukti-terima', $consignment))
            ->assertOk()
            ->assertSee($consignment->doc_no)
            ->assertSee($consignment->consignor->name)
            ->assertSee($consignment->stockLots->sortBy('sequence')->first()->sku)
            ->assertSee('Penitip');
    }

    #[Test]
    public function both_signer_blocks_are_present(): void
    {
        $consignment = $this->completedWithLots();

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.consignment-in.bukti-terima', $consignment))
            ->assertOk()
            ->assertSee('Petugas Toko')
            ->assertSee($consignment->consignor->name);
    }

    #[Test]
    #[DataProvider('paperCases')]
    public function the_print_dialog_gets_the_paper_the_owner_chose(PaperSize $paper, string $expected): void
    {
        Setting::set(PrintSettings::PAPER_KEY, $paper->value);

        $consignment = $this->completedWithLots();

        $response = $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.consignment-in.bukti-terima', $consignment))
            ->assertOk();

        $this->assertStringContainsString('size: '.$expected, $response->getContent());
    }

    /**
     * @return array<string, array{0: PaperSize, 1: string}>
     */
    public static function paperCases(): array
    {
        return [
            'struk 58' => [PaperSize::Mm58, '58mm auto'],
            'struk 80' => [PaperSize::Mm80, '80mm auto'],
            'A4' => [PaperSize::A4, 'A4'],
        ];
    }

    #[Test]
    public function the_footer_names_when_and_by_whom_it_was_printed(): void
    {
        $consignment = $this->completedWithLots();
        $printer = User::factory()->owner()->create(['name' => 'Rangga Saputra']);

        $response = $this->actingAs($printer)
            ->get(route('inbound.consignment-in.bukti-terima', $consignment))
            ->assertOk();

        $response->assertSee('Dicetak:');
        $response->assertSee('oleh Rangga Saputra');
        $response->assertSee('WIB', escape: false);
    }

    #[Test]
    public function the_paper_does_not_print_its_own_toolbar(): void
    {
        $consignment = $this->completedWithLots();

        $content = $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.consignment-in.bukti-terima', $consignment))
            ->assertOk()
            ->getContent();

        // Toolbar ada di HTML, tapi disembunyikan saat print lewat CSS. Yang
        // diperiksa di sini adalah bahwa toolbar itu ada sebagai elemen
        // tersendiri -- kalau tidak, tidak ada yang bisa disembunyikan.
        $this->assertStringContainsString('receipt-toolbar', (string) $content);
    }

    #[Test]
    public function opening_the_page_writes_no_audit_row(): void
    {
        $consignment = $this->completedWithLots();

        $staff = User::factory()->owner()->create();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($staff)
                ->get(route('inbound.consignment-in.bukti-terima', $consignment))
                ->assertOk();
        }

        $this->assertSame(0, $this->handoverLogs($consignment)->count());
    }

    #[Test]
    public function auto_print_only_appears_when_it_was_asked_for(): void
    {
        $consignment = $this->completedWithLots();
        $staff = User::factory()->owner()->create();

        $withoutFlag = $this->actingAs($staff)
            ->get(route('inbound.consignment-in.bukti-terima', $consignment))
            ->assertOk()
            ->getContent();

        // Yang diperiksa skrip auto-print-nya, bukan `window.print()` secara
        // umum: tombol "Cetak" di bilah alat memang memanggilnya, dan itu
        // selalu ada. Yang harus hilang tanpa `?auto=1` adalah pemanggilan
        // berjadwal yang membuka dialog print sendiri.
        $this->assertStringNotContainsString('window.setTimeout(() =>', (string) $withoutFlag);

        $withFlag = $this->actingAs($staff)
            ->get(route('inbound.consignment-in.bukti-terima', ['consignment' => $consignment, 'auto' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('window.setTimeout(() =>', (string) $withFlag);
        $this->assertStringContainsString('window.print()', (string) $withFlag);
    }

    #[Test]
    public function a_draft_cannot_be_printed_as_a_receipt(): void
    {
        $consignment = $this->completedWithLots();
        $consignment->update(['status' => ConsignmentStatus::Draft]);

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.consignment-in.bukti-terima', $consignment))
            ->assertNotFound();

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('inbound.consignment-in.bukti-terima.serahkan', $consignment))
            ->assertNotFound();
    }

    #[Test]
    public function a_voided_document_cannot_be_printed_as_a_receipt(): void
    {
        $consignment = $this->completedWithLots();
        $consignment->update(['status' => ConsignmentStatus::Void]);

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.consignment-in.bukti-terima', $consignment))
            ->assertNotFound();
    }

    #[Test]
    public function the_first_handover_is_recorded_as_a_handover(): void
    {
        $consignment = $this->completedWithLots();

        $this->actingAs(User::factory()->owner()->create())
            ->from(route('inbound.consignment-in.detail', $consignment))
            ->post(route('inbound.consignment-in.bukti-terima.serahkan', $consignment))
            ->assertRedirect(route('inbound.consignment-in.detail', $consignment))
            ->assertSessionHasNoErrors();

        $log = $this->handoverLogs($consignment)->firstOrFail();

        $this->assertSame('CONSIGNMENT_RECEIPT_HANDED_OVER', $log->action);
        $this->assertSame(1, $log->after['count']);
    }

    #[Test]
    public function further_printings_are_recorded_as_reprints_without_a_ceiling(): void
    {
        $consignment = $this->completedWithLots();
        $staff = User::factory()->owner()->create();

        $this->actingAs($staff)->post(route('inbound.consignment-in.bukti-terima.serahkan', $consignment));

        foreach (range(1, 6) as $ignored) {
            $this->actingAs($staff)
                ->post(route('inbound.consignment-in.bukti-terima.serahkan', $consignment));
        }

        $actions = $this->handoverLogs($consignment)->pluck('action')->all();

        // Tidak ada batas atasnya: struk yang hilang tidak membuat barang hilang,
        // dan membatasi cetakan hanya mendorong orang memanipulasi catatan.
        $this->assertCount(7, $actions);
        $this->assertSame(
            'CONSIGNMENT_RECEIPT_HANDED_OVER',
            $actions[0],
            'Penyerahan pertama bukan cetakan ulang.',
        );
        $this->assertSame(
            ['CONSIGNMENT_RECEIPT_REPRINT'],
            array_values(array_unique(array_slice($actions, 1))),
            'Setelah yang pertama, semuanya cetakan ulang.',
        );
    }

    #[Test]
    public function the_detail_page_reports_how_often_the_receipt_was_handed_over(): void
    {
        $consignment = $this->completedWithLots();
        $staff = User::factory()->owner()->create();

        $this->actingAs($staff)
            ->get(route('inbound.consignment-in.detail', $consignment))
            ->assertOk()
            ->assertSee('BELUM DICETAK');

        $this->actingAs($staff)->post(route('inbound.consignment-in.bukti-terima.serahkan', $consignment));

        $this->actingAs($staff)
            ->get(route('inbound.consignment-in.detail', $consignment))
            ->assertOk()
            ->assertSee('SUDAH DISERAHKAN')
            ->assertSee('Cetak Ulang Bukti Terima');
    }

    #[Test]
    public function the_history_row_offers_the_receipt_only_for_completed_documents(): void
    {
        $completed = $this->completedWithLots();
        $draft = $this->completedWithLots(['doc_no' => 'CI-DRAFT']);
        $draft->update(['status' => ConsignmentStatus::Draft]);

        // SKU lot unik di seluruh tabel, jadi dokumen kedua memakai SKU sendiri.
        // Kalau tidak, pembuatan lot kedua gagal sebelum baris yang diperiksa
        // sempat ada, dan test-nya jadi lulus karena alasan yang salah.

        $response = $this->actingAs(User::factory()->owner()->create())
            ->get(route('inbound.consignment-in.riwayat'))
            ->assertOk();

        $content = (string) $response->getContent();
        $url = route('inbound.consignment-in.bukti-terima', $completed);

        $this->assertStringContainsString($url, $content);
        $this->assertStringNotContainsString(route('inbound.consignment-in.bukti-terima', $draft), $content);
    }

    #[Test]
    public function a_guest_cannot_reach_the_receipt(): void
    {
        $consignment = $this->completedWithLots();

        $this->get(route('inbound.consignment-in.bukti-terima', $consignment))->assertRedirect(route('login'));
    }

    // ================= Endpoint byte thermal =================

    #[Test]
    public function the_thermal_endpoint_returns_esc_pos_bytes_for_a_completed_document(): void
    {
        $consignment = $this->completedWithLots();

        $response = $this->actingAs(User::factory()->owner()->create())
            ->post(route('inbound.consignment-in.bukti-terima.thermal', $consignment))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('paper', '80mm')
            ->assertJsonPath('width', 48);

        $bytes = base64_decode((string) $response->json('bytesB64'), true);

        $this->assertNotFalse($bytes);
        $this->assertNotNull($bytes);
        $this->assertNotEmpty($bytes);
        // Semua sepaket ESC/POS mulai dari reset printer.
        $this->assertSame("\x1b", $bytes[0]);
        $this->assertStringContainsString($consignment->doc_no, $bytes);
    }

    #[Test]
    public function the_thermal_endpoint_follows_the_global_paper_setting(): void
    {
        $consignment = $this->completedWithLots();

        Setting::set(PrintSettings::PAPER_KEY, PaperSize::Mm58->value);

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('inbound.consignment-in.bukti-terima.thermal', $consignment))
            ->assertOk()
            ->assertJsonPath('paper', '58mm')
            ->assertJsonPath('width', 32);
    }

    #[Test]
    public function a4_is_refused_by_the_thermal_endpoint_instead_of_printing_garbage(): void
    {
        $consignment = $this->completedWithLots();

        Setting::set(PrintSettings::PAPER_KEY, PaperSize::A4->value);

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('inbound.consignment-in.bukti-terima.thermal', $consignment))
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);
    }

    #[Test]
    public function a_draft_cannot_fetch_thermal_bytes(): void
    {
        $consignment = $this->completedWithLots();
        $consignment->update(['status' => ConsignmentStatus::Draft]);

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('inbound.consignment-in.bukti-terima.thermal', $consignment))
            ->assertNotFound();
    }

    #[Test]
    public function a_guest_cannot_fetch_thermal_bytes(): void
    {
        $consignment = $this->completedWithLots();

        $this->post(route('inbound.consignment-in.bukti-terima.thermal', $consignment))->assertRedirect(route('login'));
    }

    private function handoverLogs(Consignment $consignment)
    {
        return AuditLog::query()
            ->where('entity', 'Consignment')
            ->where('entity_id', $consignment->id)
            ->whereIn('action', ['CONSIGNMENT_RECEIPT_HANDED_OVER', 'CONSIGNMENT_RECEIPT_REPRINT'])
            ->orderBy('id');
    }

    private function completedWithLots(array $attributes = []): Consignment
    {
        $consignment = Consignment::factory()->completed()->create($attributes);

        foreach ([1 => 5, 2 => 3] as $sequence => $qty) {
            StockLot::factory()->ownedBy($consignment->consignor)->create([
                'consignment_id' => $consignment->id,
                'sequence' => $sequence,
                'sku' => sprintf('CN%02d-HW-%03d', $consignment->id, $sequence),
                'qty_received' => $qty,
                'qty_on_hand' => $qty,
            ]);
        }

        return $consignment->fresh(['consignor', 'stockLots']);
    }
}
