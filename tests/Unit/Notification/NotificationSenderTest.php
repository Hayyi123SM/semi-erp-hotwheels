<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Enums\NotificationStatus;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notification\NotificationSender;
use App\Services\Notification\NotificationTemplate;
use App\Services\Notification\Transport\TransportResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Aturan utama yang diuji di sini berasal dari tabel penanganan error inbound:
 * "WhatsApp gagal terkirim -> dokumen tetap COMPLETED".
 *
 * Itu bukan keterangan tambahan, itu urutan kejadian. Kalau pengiriman dinilai
 * sebelum commit, satu penitip yang nomornya salah membuat seluruh penerimaan
 * barang ditolak; Staff mengulang; barangnya sudah ada di rak. Karena itu
 * `send()` tidak pernah melempar galat, dan syarat opt-in diperiksa di dalam
 * service -- bukan di pemanggil yang bisa lupa.
 *
 * Batas lima percobaan berlaku untuk retry otomatis, bukan untuk tombol
 * "Coba Lagi". bedanya nyata: retry otomatis berjalan tanpa siapa pun di
 * depan layar, sedangkan "Coba Lagi" ditekan manusia yang baru saja
 * memperbaiki nomornya di profil penitip. Menolaknya karena sudah lima kali
 * gagal akan membuat notifikasi itu mustahil dikirim tanpa jalan lain.
 */
class NotificationSenderTest extends TestCase
{
    use RefreshDatabase;

    private const string WA = '6281234567890';

    private function sender(FakeTransport $transport): NotificationSender
    {
        return new NotificationSender($transport);
    }

    private function document(?string $wa = self::WA, bool $optIn = true): Consignment
    {
        $consignor = Consignor::factory()->create([
            'wa_number' => $wa,
            'wa_opt_in_at' => $optIn ? now()->subMonth() : null,
        ]);

        return Consignment::factory()->completed()->create([
            'doc_no' => 'CI-20260929-0042',
            'consignor_id' => $consignor->id,
            'consignment_date' => '2026-09-29',
            'qty_claimed' => 1,
            'qty_received' => 1,
            'created_by' => User::factory()->staff()->create(),
        ]);
    }

    private function prepared(array $consignorOverrides = []): Notification
    {
        $consignor = Consignor::factory()->create($consignorOverrides);

        $consignment = Consignment::factory()->completed()->create([
            'doc_no' => 'CI-20260929-0042',
            'consignor_id' => $consignor->id,
            'consignment_date' => '2026-09-29',
            'qty_claimed' => 1,
            'qty_received' => 1,
            'created_by' => User::factory()->staff()->create(),
        ]);

        return Notification::factory()->create([
            'notification_key' => NotificationSender::keyFor(NotificationTemplate::ConsignmentReceipt, $consignment),
            'consignment_id' => $consignment->id,
            'recipient' => (string) $consignor->wa_number,
        ]);
    }

    private function optIn(): Notification
    {
        return $this->prepared(['wa_number' => self::WA, 'wa_opt_in_at' => now()->subMonth()]);
    }

    #[Test]
    public function sending_records_sent_attempt_and_actor(): void
    {
        $notification = $this->optIn();
        $actor = User::factory()->staff()->create();
        $transport = FakeTransport::alwaysSucceeds();

        $sent = $this->sender($transport)->send($notification, $actor);

        $this->assertSame(NotificationStatus::Sent, $sent->status);
        $this->assertSame(1, $sent->attempts);
        $this->assertNotNull($sent->sent_at);
        $this->assertSame($actor->id, $sent->requested_by);
        $this->assertNull($sent->error_message);
    }

    /**
     * Opt-in adalah syarat, bukan saran.
     *
     * Tanpa opt-in, e-receipt berarti toko mengirim pesan ke penitip yang
     * tidak meminta diberi tahu. SRS 6.5 hanya mengizinkan nomor dengan
     * `wa_opt_in_at`, jadi ini batas privasi dan bukan pilihan fitur.
     */
    #[Test]
    public function consignor_without_opt_in_is_never_messaged(): void
    {
        $notification = $this->prepared(['wa_number' => self::WA, 'wa_opt_in_at' => null]);
        $transport = FakeTransport::alwaysSucceeds();

        $result = $this->sender($transport)->send($notification);

        $this->assertSame(0, $transport->calls, 'Transport tidak boleh dipanggil untuk nomor tanpa opt-in.');
        $this->assertSame(NotificationStatus::Failed, $result->status);
        $this->assertStringContainsString('opt-in', (string) $result->error_message);
    }

    #[Test]
    public function consignor_without_any_number_says_so_instead_of_complaining_about_opt_in(): void
    {
        $notification = $this->prepared(['wa_number' => null, 'wa_opt_in_at' => null]);

        $result = $this->sender(FakeTransport::alwaysSucceeds())->send($notification);

        $this->assertSame(NotificationStatus::Failed, $result->status);
        $this->assertStringContainsString('belum mengisi nomor', (string) $result->error_message);
    }

    /**
     * Pesan errornya harus bisa dibaca orang, bukan kode.
     *
     * "blocked by opt-in" di layar membuat operator harus menduga; kalimatnya
     * harus langsung menjelaskan bagian mana dari profil penitip yang perlu
     * diperbaiki.
     */
    #[Test]
    public function failure_reason_names_the_field_to_fix(): void
    {
        $notification = $this->prepared(['wa_number' => '0812-000', 'wa_opt_in_at' => now()->subMonth()]);

        $result = $this->sender(FakeTransport::alwaysSucceeds())->send($notification);

        $this->assertSame(NotificationStatus::Failed, $result->status);
        $this->assertStringContainsString('tidak valid', (string) $result->error_message);
    }

    /**
     * `notification_key` unique adalah penjaga idempotensi.
     *
     * Tombol "Kirim" yang ditekan dua kali karena operator tidak yakin
     * masuknya harus berakhir di satu notifikasi dengan satu percobaan, bukan
     * dua baris yang masing-masing akan dikirim ke penitip yang sama.
     */
    #[Test]
    public function two_sends_for_one_document_produce_one_notification(): void
    {
        $document = $this->document();
        $transport = FakeTransport::alwaysSucceeds();
        $sender = $this->sender($transport);

        $first = $sender->sendReceipt($document);
        $second = $sender->sendReceipt($document->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $transport->calls);
        $this->assertSame(1, $second->attempts, 'Kirim kedua tidak boleh menambah percobaan setelah terkirim.');
    }

    /**
     * Isi yang benar-benar dikirim ikut disimpan.
     *
     * Kalau tidak, pertanyaan "apa yang sebenarnya terkirim ke penitip pada
     * tanggal itu?" tidak bisa dijawab begitu template Pengaturan diubah --
     * yang tersisa hanya template yang sekarang, yang bukan yang terkirim.
     */
    #[Test]
    public function the_body_actually_sent_is_snapshotted(): void
    {
        $notification = $this->optIn();
        $transport = FakeTransport::alwaysSucceeds();

        $this->sender($transport)->send($notification);

        $stored = $notification->fresh();

        $this->assertNotNull($transport->lastBody());
        $this->assertSame($transport->lastBody(), $stored->body);
        $this->assertStringContainsString('CI-20260929-0042', (string) $stored->body);
    }

    /**
     * Percobaan kedua tidak memakai template yang baru.
     *
     * Setelah percobaan pertama gagal, template bisa saja sudah diubah Staff.
     * Memakai template baru untuk percobaan kedua berarti retry diam-diam
     * mengirim pesan berbeda dari yang gagal, dan membandingkan log jadi tidak
     * berarti.
     */
    #[Test]
    public function retry_uses_the_snapshotted_body_not_the_current_template(): void
    {
        $notification = $this->optIn();
        $sender = $this->sender(FakeTransport::alwaysDeferred());

        $first = $sender->send($notification);
        $this->assertSame(NotificationStatus::Pending, $first->status);
        $this->assertNotNull($first->fresh()->body);

        Setting::set(NotificationTemplate::ConsignmentReceipt->settingKey(), 'TEMPLAT BARU');

        $transport = FakeTransport::alwaysSucceeds();
        $this->sender($transport)->send($first->fresh());

        $this->assertStringNotContainsString('TEMPLAT BARU', (string) $transport->lastBody());
        $this->assertStringContainsString('Bukti Terima Titipan', (string) $transport->lastBody());
    }

    /**
     * Backoff SRS 6.5: 1m, 5m, 30m, 2hari, 6hari.
     *
     * Dicek sebagai tanggal absolut, bukan "lebih besar dari sekarang":
     * angka yang salah akan lolos kalau hanya dibandingkan dengan `now()`, dan
     * jeda satu menit bisa lolos sebagai "1 menit atau kurang".
     *
     * @return list<array{0: int, 1: string}>
     */
    public static function backoffSchedule(): array
    {
        return [
            'percobaan 1 → 1 menit' => [1, '1 minute'],
            'percobaan 2 → 5 menit' => [2, '5 minutes'],
            'percobaan 3 → 30 menit' => [3, '30 minutes'],
            'percobaan 4 → 2 hari' => [4, '2 days'],
        ];
    }

    #[Test]
    public function retry_schedules_the_exact_srs_backoff(): void
    {
        $notification = $this->optIn();
        $sender = $this->sender(FakeTransport::alwaysDeferred());

        Carbon::setTestNow(Carbon::parse('2026-09-29 10:00:00'));

        try {
            foreach (self::backoffSchedule() as [$attempt, $expected]) {
                $result = $sender->send($notification->fresh());

                $this->assertSame(
                    NotificationStatus::Pending,
                    $result->status,
                    "Percobaan {$attempt} seharusnya ditunda, bukan dianggap gagal permanen.",
                );
                $this->assertSame(
                    Carbon::now()->add($expected)->toDateTimeString(),
                    (string) $result->next_attempt_at,
                    "Jeda setelah percobaan {$attempt} tidak sesuai SRS.",
                );

                Carbon::setTestNow(Carbon::now()->add($expected));
            }
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(4, $notification->fresh()->attempts);
    }

    /**
     * Retry otomatis berhenti setelah lima percobaan.
     *
     * Yang diuji adalah berakhirnya penjadwalan: begitu `next_attempt_at`
     * kosong dan status `FAILED`, tidak ada lagi antrean yang akan mengambilnya
     * -- dan setiap hari selama berbulan-bulan tidak akan muncul lagi. Yang
     * tidak diuji di sini adalah prohibiting "Coba Lagi", karena itu keputusan
     * manusia, bukan antrean; ia punya test sendiri di bawah.
     */
    #[Test]
    public function automatic_retry_stops_after_five_attempts(): void
    {
        $notification = $this->optIn();
        $sender = $this->sender(FakeTransport::alwaysDeferred());

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $notification = $sender->send($notification->fresh());
        }

        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertSame(5, $notification->attempts, 'Batasnya lima percobaan; angka lebih besar tidak punya asal-usulnya.');
        $this->assertNull($notification->next_attempt_at, 'Notifikasi gagal tidak boleh dijadwalkan ulang.');
        $this->assertStringContainsString('Gagal setelah 5 percobaan', (string) $notification->error_message);
    }

    /**
     * "Gagal setelah 5 percobaan" bukan angka yang lahir sendiri.
     *
     * Limanya harus sama dengan yang tercatat di kolom `attempts`. Kalau
     * `markFailed()` menghitung sekali lagi di dalam dirinya sendiri, barisnya
     * menjadi enam sementara pesannya masih berbunyi lima -- dan tidak ada yang
     * bisa menjelaskan dari mana angka kedua itu datangnya.
     */
    #[Test]
    public function exhaustion_message_agrees_with_the_recorded_attempt_count(): void
    {
        $notification = $this->optIn();
        $sender = $this->sender(FakeTransport::alwaysDeferred());

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $notification = $sender->send($notification->fresh());
        }

        $this->assertStringContainsString(
            (string) $notification->attempts,
            (string) $notification->error_message,
            'Jumlah di pesan harus sama dengan jumlah yang tercatat.',
        );
    }

    /**
     * Setelah data diperbaiki, "Coba Lagi" harus berhasil.
     *
     * Inilah yang membuat tombol di halaman dokumen berguna. Notifikasi yang
     * sudah lima kali gagal otomatis masih boleh dicoba manusia yang baru saja
     * memperbaiki nomornya di profil penitip.
     */
    #[Test]
    public function manual_retry_still_works_after_the_automatic_budget_is_gone(): void
    {
        $notification = $this->optIn();
        $sender = $this->sender(FakeTransport::alwaysDeferred());

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $notification = $sender->send($notification->fresh());
        }

        $this->assertSame(NotificationStatus::Failed, $notification->status);

        $transport = FakeTransport::alwaysSucceeds();
        $manual = $this->sender($transport)->send($notification->fresh());

        $this->assertSame(NotificationStatus::Sent, $manual->status);
        $this->assertSame(6, $manual->attempts, 'Percobaan manusia memang percobaan, dan harus ikut tercatat.');
        $this->assertNull($manual->error_message);
    }

    /**
     * Backoff tidak dipakai untuk kegagalan yang tidak akan hilang sendiri.
     *
     * Opt-in yang belum dicentang adalah keputusan penitip. Menunggu lima kali
     * dalam enam hari tidak mengubah keputusan itu, dan setiap percobaan hanya
     * menambah satu baris gagal di log.
     */
    #[Test]
    public function permanent_failure_is_not_scheduled_for_retry(): void
    {
        $notification = $this->optIn();

        $result = $this->sender(FakeTransport::sequence(TransportResult::refused('Template ditolak Meta.')))
            ->send($notification);

        $this->assertSame(NotificationStatus::Failed, $result->status);
        $this->assertNull($result->next_attempt_at);
        $this->assertSame('Template ditolak Meta.', $result->error_message);
    }

    /**
     * Status yang sudah keluar tidak dikirim ulang, meski transport bisa dipanggil.
     */
    #[Test]
    public function already_sent_notification_is_never_resent(): void
    {
        $notification = $this->optIn();
        $sender = $this->sender(FakeTransport::alwaysSucceeds());

        $sender->send($notification);

        $transport = FakeTransport::alwaysSucceeds();
        $again = $this->sender($transport)->send($notification->fresh());

        $this->assertSame(0, $transport->calls, 'Pesan yang sudah diserahkan tidak boleh dipanggil lagi.');
        $this->assertSame(NotificationStatus::Sent, $again->status);
        $this->assertSame(1, $again->attempts);
    }

    /**
     * Gagal lalu dicoba lagi harus tetap satu baris.
     */
    #[Test]
    public function retry_after_failure_reuses_the_same_row(): void
    {
        $document = $this->document();

        $first = $this->sender(FakeTransport::alwaysDeferred())->sendReceipt($document);
        $this->assertSame(1, $first->attempts);

        $second = $this->sender(FakeTransport::alwaysSucceeds())->sendReceipt($document->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(NotificationStatus::Sent, $second->status);
        $this->assertSame(2, $second->attempts);
    }

    /**
     * Transport harus menerima nomor penitip, bukan nomor tanpa awalan.
     */
    #[Test]
    public function transport_receives_the_stored_number(): void
    {
        $notification = $this->optIn();
        $transport = FakeTransport::alwaysSucceeds();

        $this->sender($transport)->send($notification);

        $this->assertSame([self::WA], $transport->recipients);
    }

    /**
     * Kunci idempotensi persis seperti contoh di SRS 6.5.
     */
    #[Test]
    public function idempotency_key_matches_the_srs_example(): void
    {
        $document = $this->document();

        $this->assertSame(
            'consignment_receipt:'.$document->getKey(),
            NotificationSender::keyFor(NotificationTemplate::ConsignmentReceipt, $document),
        );
    }

    /**
     * Menyiapkan dua kali untuk satu dokumen tidak menghasilkan baris kedua.
     *
     * `prepare()` dipanggil dari halaman detail setiap kali operator membuka
     * dokumen, jadi ini jalur yang paling sering dijalankan -- bukan hanya
     * saat tombol ditekan.
     */
    #[Test]
    public function prepare_is_idempotent_across_page_loads(): void
    {
        $document = $this->document();
        $sender = $this->sender(FakeTransport::alwaysSucceeds());

        $first = $sender->prepare($document, NotificationTemplate::ConsignmentReceipt);
        $second = $sender->prepare($document->fresh(), NotificationTemplate::ConsignmentReceipt);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(0, $sender->prepare($document->fresh(), NotificationTemplate::ConsignmentReceipt)->attempts);
    }

    /**
     * Notifikasi yang baru disiapkan harus langsung bisa dibaca statusnya.
     *
     * `firstOrCreate()` mengembalikan objek yang hanya berisi atribut yang
     * diisi, dan `status` sengaja tidak mass-assignable. Kalau tidak diisi
     * eksplisit setelah pembuatan, baris pertama di database punya `PENDING`
     * sementara objek di memori punya `null` -- dan halaman dokumen yang baru
     * dibuka akan gagal saat membaca badge statusnya.
     */
    #[Test]
    public function a_freshly_prepared_notification_has_a_readable_status(): void
    {
        $document = $this->document();

        $notification = $this->sender(FakeTransport::alwaysSucceeds())
            ->prepare($document, NotificationTemplate::ConsignmentReceipt);

        $this->assertSame(NotificationStatus::Pending, $notification->status);
        $this->assertTrue($notification->status->isOpen());
    }
}
