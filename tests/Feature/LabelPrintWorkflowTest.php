<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConsignmentStatus;
use App\Enums\LabelStatus;
use App\Models\Consignment;
use App\Models\LabelPrintJob;
use App\Models\StockLot;
use App\Models\User;
use App\Services\Inventory\LabelPrintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Aturan yang dijaga di sini: `labels_printed` hanya boleh naik saat
 * CONFIRMED, dan tidak boleh naik dua kali untuk label yang sama.
 *
 * Ini bukan detail kecil. Kalau counter naik begitu job dikirim ke printer,
 * lalu printer ternyata gagal di tengah, angka lot akan melebihi label yang
 * benar-benar menempel pada unit, dan konsignment bisa ditutup padahal masih
 * ada unit tanpa label.
 */
class LabelPrintWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->owner()->create();
    }

    private function lot(int $qty = 12): StockLot
    {
        return StockLot::factory()->create([
            'sku' => 'CN01-HW-001-U03',
            'owner_code' => 'CN01',
            'qty_received' => $qty,
            'labels_printed' => 0,
        ]);
    }

    private function job(StockLot $lot, int $copies = 1, LabelStatus|string $status = LabelStatus::Queued): LabelPrintJob
    {
        return LabelPrintJob::factory()->create([
            'lot_id' => $lot->id,
            'copies' => $copies,
            'status' => $status,
        ]);
    }

    /**
     * Kirim aksi label.
     *
     * Namanya bukan `post()` karena itu sudah dipakai TestCase bawaan, dan
     * menimpanya jadi fatal error. Argumennya NAMA route, bukan URL, supaya
     * `route()` hanya dipanggil sekali.
     */
    private function act(string $routeName, array $ids, array $extra = [])
    {
        $this->actingAs($this->operator);

        return $this->post(route($routeName), ['ids' => $ids] + $extra);
    }

    // ------------------------------------------------------------ counter ---

    #[Test]
    public function marking_as_printed_does_not_count_the_label_yet(): void
    {
        $lot = $this->lot();
        $job = $this->job($lot, copies: 3);

        $this->act('inbound.cetak-label.store', [$job->id])
            ->assertRedirect(route('inbound.cetak-label'));

        $this->assertSame(LabelStatus::Sent, $job->fresh()->status);
        // Ini inti dari D2: SENT bukan berarti label sudah jadi.
        $this->assertSame(0, $lot->fresh()->labels_printed);
    }

    #[Test]
    public function confirming_counts_the_label(): void
    {
        $lot = $this->lot();
        $job = $this->job($lot, copies: 3, status: LabelStatus::Sent);

        $this->act('inbound.cetak-label.confirm', [$job->id])
            ->assertRedirect(route('inbound.cetak-label'));

        $this->assertSame(LabelStatus::Confirmed, $job->fresh()->status);
        $this->assertSame(3, $lot->fresh()->labels_printed);
        $this->assertNotNull($job->fresh()->confirmed_at);
    }

    #[Test]
    public function confirming_twice_does_not_count_the_label_twice(): void
    {
        $lot = $this->lot();
        $job = $this->job($lot, copies: 3, status: LabelStatus::Sent);

        $this->act('inbound.cetak-label.confirm', [$job->id]);
        $this->act('inbound.cetak-label.confirm', [$job->id]);

        // Job kedua tidak lagi SENT, jadi tidak boleh menambah apa pun.
        $this->assertSame(3, $lot->fresh()->labels_printed);
    }

    #[Test]
    public function a_confirmed_job_cannot_be_confirmed_again(): void
    {
        $lot = $this->lot();
        $job = $this->job($lot, copies: 1, status: LabelStatus::Sent);

        $this->act('inbound.cetak-label.confirm', [$job->id]);
        $this->act('inbound.cetak-label.confirm', [$job->id])->assertSessionHasErrors('ids.0');

        $this->assertSame(1, $lot->fresh()->labels_printed);
    }

    #[Test]
    public function confirming_counts_each_job_once_in_a_batch(): void
    {
        $lot = $this->lot();
        $first = $this->job($lot, copies: 2, status: LabelStatus::Sent);
        $second = $this->job($lot, copies: 5, status: LabelStatus::Sent);

        $this->act('inbound.cetak-label.confirm', [$first->id, $second->id]);

        $this->assertSame(7, $lot->fresh()->labels_printed);
    }

    // ------------------------------------------------------------- failed ---

    #[Test]
    public function a_failed_print_does_not_count_the_label(): void
    {
        $lot = $this->lot();
        $job = $this->job($lot, copies: 2, status: LabelStatus::Sent);

        $this->act('inbound.cetak-label.fail', [$job->id], ['message' => 'Kertas habis'])
            ->assertRedirect(route('inbound.cetak-label'));

        $this->assertSame(LabelStatus::Failed, $job->fresh()->status);
        $this->assertSame(0, $lot->fresh()->labels_printed);
        $this->assertSame('Kertas habis', $job->fresh()->error_message);
    }

    #[Test]
    public function a_failure_reason_is_required(): void
    {
        $job = $this->job($this->lot(), status: LabelStatus::Sent);

        $this->act('inbound.cetak-label.fail', [$job->id], ['message' => '   '])
            ->assertSessionHasErrors('message');

        $this->assertSame(LabelStatus::Sent, $job->fresh()->status);
    }

    #[Test]
    public function a_failed_job_can_go_back_to_the_queue(): void
    {
        $lot = $this->lot();
        $job = $this->job($lot, status: LabelStatus::Failed);

        $this->act('inbound.cetak-label.retry', [$job->id])
            ->assertRedirect(route('inbound.cetak-label'));

        $job->refresh();
        $this->assertSame(LabelStatus::Queued, $job->status);
        $this->assertNull($job->error_message);
        $this->assertSame(0, $lot->fresh()->labels_printed);
    }

    /**
     * Retry harus memakai ulang isi label yang sudah dibekukan.
     *
     * Kalau `payload` ikut terhapus, cetakan ulang karena perubahan harga akan
     * menghasilkan label dengan harga yang sama seperti cetakan pertama --
     * persis alasan kenapa alasan `PRICE_CHANGE` itu ada.
     */
    #[Test]
    public function retry_keeps_the_frozen_label_content(): void
    {
        $job = $this->job($this->lot(), status: LabelStatus::Failed);
        $payload = ['sku' => 'CN01-HW-001-U03', 'list_price' => 50_000];
        $job->forceFill(['payload' => $payload])->save();

        $this->act('inbound.cetak-label.retry', [$job->id]);

        $this->assertSame($payload, $job->fresh()->payload);
    }

    // ------------------------------------------------- validasi transition ---

    #[Test]
    public function confirming_a_queued_job_is_rejected(): void
    {
        $lot = $this->lot();
        $job = $this->job($lot, status: LabelStatus::Queued);

        $this->act('inbound.cetak-label.confirm', [$job->id])->assertSessionHasErrors('ids.0');

        $this->assertSame(LabelStatus::Queued, $job->fresh()->status);
        $this->assertSame(0, $lot->fresh()->labels_printed);
    }

    #[Test]
    public function marking_as_printed_a_sent_job_is_rejected(): void
    {
        $job = $this->job($this->lot(), status: LabelStatus::Sent);

        $this->act('inbound.cetak-label.store', [$job->id])->assertSessionHasErrors('ids.0');
    }

    #[Test]
    public function a_batch_with_one_wrong_status_is_rejected_whole(): void
    {
        $lot = $this->lot();
        $queued = $this->job($lot, status: LabelStatus::Queued);
        $sent = $this->job($lot, status: LabelStatus::Sent);

        $this->act('inbound.cetak-label.store', [$queued->id, $sent->id])
            ->assertSessionHasErrors();

        // Tidak boleh ada yang berubah kalau satu id tidak memenuhi syarat:
        // setengah jadi lebih sulit dibaca daripada ditolak seluruhnya.
        $this->assertSame(LabelStatus::Queued, $queued->fresh()->status);
        $this->assertSame(LabelStatus::Sent, $sent->fresh()->status);
    }

    // ----------------------------------------------------- konsignment close ---

    #[Test]
    public function the_queue_page_offers_a_select_all_for_the_rows_it_shows(): void
    {
        $lot = $this->lot();
        $first = $this->job($lot);
        $second = $this->job($lot);

        $html = $this->actingAs($this->operator)
            ->get(route('inbound.cetak-label'))
            ->assertOk()
            ->getContent();

        // `indeterminate` adalah properti DOM, jadi dua keadaan sisanya tidak
        // bisa ditulis sebagai atribut HTML dan harus benar-benar terikat ke
        // elemen header ini.
        $this->assertStringContainsString('x-ref="selectAll"', $html);
        $this->assertStringContainsString('@change="toggleAll()"', $html);

        // Daftar id yang dikirim ke Alpine harus persis baris yang dirender:
        // kalau tidak, "pilih semua" akan mengirim job yang tidak ada di halaman.
        //
        // `@js` menulis `JSON.parse('...')` dengan tanda kutip di-escape untuk
        // string JavaScript, jadi yang diperiksa adalah isinya, bukan bentuk
        // string-nya. Urutan id juga tidak dijamin: query antrean tidak
        // diurutkan berdasarkan id.
        //
        // Pola di bawah menuntut kedua argumen ada. Kalau suatu saat hanya peta
        // status yang dikirim, regex tidak akan cocok dan test ini gagal --
        // bukan lolos diam-diam ke pemeriksaan yang lebih longgar.
        preg_match('/labelQueue\\(JSON\\.parse\(\'(.*?)\'\\), JSON\\.parse\(\'(.*?)\'\\)\\)/', $html, $matches);

        $this->assertNotEmpty($matches, 'x-data antrean harus mengirim peta status dan daftar id');

        $sentIds = json_decode($matches[2], true);

        $this->assertSame(
            [$first->id, $second->id],
            collect($sentIds)->sort()->values()->all(),
        );
    }

    #[Test]
    public function a_consignment_closes_when_every_label_is_confirmed(): void
    {
        $consignment = Consignment::factory()->create(['status' => ConsignmentStatus::Committed]);
        $lot = $this->lot();
        $lot->forceFill(['consignment_id' => $consignment->id])->save();

        $this->job($lot, status: LabelStatus::Confirmed);
        $pending = $this->job($lot, status: LabelStatus::Sent);

        $this->act('inbound.cetak-label.confirm', [$pending->id]);

        $this->assertSame(ConsignmentStatus::Completed, $consignment->fresh()->status);
    }

    #[Test]
    public function a_consignment_stays_open_while_a_label_is_unfinished(): void
    {
        $consignment = Consignment::factory()->create(['status' => ConsignmentStatus::Committed]);
        $lot = $this->lot();
        $lot->forceFill(['consignment_id' => $consignment->id])->save();

        $this->job($lot, status: LabelStatus::Failed);
        $pending = $this->job($lot, status: LabelStatus::Sent);

        $this->act('inbound.cetak-label.confirm', [$pending->id]);

        $this->assertSame(ConsignmentStatus::Committed, $consignment->fresh()->status);
    }

    #[Test]
    public function a_consignment_in_another_state_is_not_closed_by_confirmation(): void
    {
        $consignment = Consignment::factory()->create(['status' => ConsignmentStatus::Draft]);
        $lot = $this->lot();
        $lot->forceFill(['consignment_id' => $consignment->id])->save();

        $this->job($lot, status: LabelStatus::Confirmed);
        $pending = $this->job($lot, status: LabelStatus::Sent);

        $this->app->make(LabelPrintService::class)->confirm(collect([$pending->id]));

        $this->assertSame(ConsignmentStatus::Draft, $consignment->fresh()->status);
    }

    // --------------------------------------------------------------- audit ---

    #[Test]
    public function every_transition_is_audited(): void
    {
        $job = $this->job($this->lot(), status: LabelStatus::Sent);

        $this->act('inbound.cetak-label.confirm', [$job->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'CONFIRM_LABELS',
            'entity' => 'LabelPrintJob',
            'entity_id' => (string) $job->id,
        ]);
    }

    #[Test]
    public function guests_cannot_move_a_label(): void
    {
        $job = $this->job($this->lot(), status: LabelStatus::Sent);

        $this->post(route('inbound.cetak-label.confirm'), ['ids' => [$job->id]])
            ->assertRedirect(route('login'));

        $this->assertSame(LabelStatus::Sent, $job->fresh()->status);
    }
}
