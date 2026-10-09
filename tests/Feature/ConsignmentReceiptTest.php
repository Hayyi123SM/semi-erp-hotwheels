<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConsignmentStatus;
use App\Enums\NotificationStatus;
use App\Http\Middleware\OwnerOnly;
use App\Models\AuditLog;
use App\Models\Consignment;
use App\Models\Consignor;
use App\Models\Product;
use App\Models\ProductSeries;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notification\NotificationTemplate;
use App\Services\Notification\Transport\TransportResult;
use App\Services\Notification\Transport\WhatsappTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AssertsReceiptRedirect;
use Tests\TestCase;
use Tests\Unit\Notification\FakeTransport;

/**
 * E-receipt dikirim setelah commit, dan kegagalannya tidak boleh membatalkan
 * penerimaan barang.
 *
 * Urutan ini yang diuji di sini, bukan hanya isi pesannya. "Pesan gagal tapi
 * dokumen tetap COMPLETED" bukan keterangan di tabel penanganan error: kalau
 * pengiriman dievaluasi di dalam transaksi commit, satu penitip yang nomornya
 * salah membuat seluruh penerimaan barang ditolak, Staff mengulang, dan
 * barangnya sudah ada di rak. Yang hilang bukan notifikasi -- yang hilang
 * adalah pencatatan barang.
 *
 * Karena itu test di bawah memaksa transport gagal, lalu memeriksa dokumennya
 * masih `COMPLETED`. Kalau tidak, kegagalan notifikasi akan lolos sebagai
 * "test hijau" selama transport-nya hanya pernah berhasil.
 */
class ConsignmentReceiptTest extends TestCase
{
    use AssertsReceiptRedirect;
    use RefreshDatabase;

    private const string WA = '6281234567890';

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->owner()->create();
        $this->actingAs($this->staff);
    }

    private function swapTransport(WhatsappTransport $transport): void
    {
        $this->app->instance(WhatsappTransport::class, $transport);
    }

    private function consignor(array $overrides = []): Consignor
    {
        return Consignor::factory()->create(array_merge([
            'consignor_code' => 'CN42',
            'name' => 'Koleksi Andre',
            'wa_number' => self::WA,
            'wa_opt_in_at' => now()->subMonth(),
        ], $overrides));
    }

    /**
     * Commit satu konsinyasi lewat HTTP, bukan lewat service.
     *
     * Hook notifikasi menempel di controller, jadi mengujinya dengan memanggil
     * service-nya secara langsung akan melewati justru bagian yang sedang
     * diuji.
     */
    private function commitConsignment(Consignor $consignor, ?ProductSeries $series = null): Consignment
    {
        $series ??= ProductSeries::factory()->create(['code' => 'HW']);
        $product = Product::factory()->create(['series_id' => $series->id]);

        $this->post('/inbound/consignment-in', [
            'consignor_id' => $consignor->id,
            'consignment_date' => '2026-09-29',
            'source' => 'Drop-off',
            'verified' => '1',
            'items' => [
                ['product_id' => $product->id, 'qty' => '2', 'card_condition' => 'MINT', 'blister_condition' => 'CLEAR'],
            ],
        ]);

        return Consignment::query()->orderByDesc('id')->firstOrFail();
    }

    #[Test]
    public function committing_a_consignment_prepares_its_e_receipt(): void
    {
        $this->swapTransport(FakeTransport::alwaysSucceeds());

        $consignment = $this->commitConsignment($this->consignor());

        $notification = $consignment->notifications()->sole();

        $this->assertSame('consignment_receipt', $notification->template_name);
        $this->assertSame('WHATSAPP', $notification->channel);
        $this->assertSame(self::WA, $notification->recipient);
        $this->assertSame('consignment_receipt:'.$consignment->getKey(), $notification->notification_key);
    }

    #[Test]
    public function the_message_is_handed_over_and_marked_sent(): void
    {
        $transport = FakeTransport::alwaysSucceeds();
        $this->swapTransport($transport);

        $consignment = $this->commitConsignment($this->consignor());

        $this->assertSame(1, $transport->calls);
        $this->assertSame([self::WA], $transport->recipients);
        $this->assertStringContainsString($consignment->doc_no, (string) $transport->lastBody());

        $notification = $consignment->notifications()->sole();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertNotNull($notification->sent_at);
        $this->assertSame($this->staff->id, $notification->requested_by);
    }

    /**
     * Ini inti dari seluruh fitur: kegagalan WA tidak boleh membatalkan commit.
     */
    #[Test]
    public function a_failed_message_still_leaves_the_document_completed(): void
    {
        $this->swapTransport(FakeTransport::alwaysDeferred('SRS sedang sibuk.'));

        $consignment = $this->commitConsignment($this->consignor());

        $notification = $consignment->notifications()->sole();

        $this->assertSame(ConsignmentStatus::Committed, $consignment->fresh()->status, 'Dokumen tidak boleh jadi batal karena pesan gagal.');
        $this->assertSame(1, $consignment->stockLots()->count(), 'Barang harus tetap tercatat.');
        $this->assertSame(2, $consignment->stockLots()->sum('qty_on_hand'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'RECEIVE_CONSIGN',
            'entity' => 'Consignment',
            'entity_id' => $consignment->id,
        ]);

        $this->assertSame(NotificationStatus::Pending, $notification->status);
        $this->assertNotNull($notification->next_attempt_at, 'Gagal sementara harus dijadwalkan ulang.');
    }

    /**
     * Galat dari transport pun tidak boleh menggagalkan commit.
     *
     * `send()` memang tidak melempar galat, tapi itu janji kode, bukan jaminan.
     * Kalau suatu saat ada transport yang melempar -- koneksi putus, kelas yang
     * salah di-bind, pustaka yang diperbarui -- commit tidak boleh ikut tersedak.
     * Yang boleh hilang cuma notifikasi, karena itu bisa dikirim ulang dari
     * halaman dokumen; yang tidak bisa diulang adalah commit.
     *
     * Dua kasus, bukan satu, dan perbedaannya bukan sekadar "--". `Throwable`
     * adalah satu-satunya cara menangkap galat yang tidakextends `Exception`,
     * yaitu bug programmer, binding keliru, dan ketidakcocokan tipe. Menguji
     * cuma `RuntimeException` membuat penanganan galat yang paling mungkin
     * muncul justru tidak pernah diuji, dan `catch (Exception)` yang terlihat
     * benar bisa lolos semua test di sini.
     */
    #[Test]
    #[DataProvider('throwableClasses')]
    public function a_transport_that_throws_does_not_roll_back_the_commit(string $throwable): void
    {
        $throwing = new class($throwable) implements WhatsappTransport
        {
            public function __construct(private readonly string $throwable) {}

            public function send(string $recipient, string $body): TransportResult
            {
                throw new $this->throwable('Koneksi ke Meta putus.');
            }

            public function handoffLink(string $recipient, string $body): ?string
            {
                return null;
            }
        };

        $this->swapTransport($throwing);

        $consignment = $this->commitConsignment($this->consignor());

        $this->assertSame(ConsignmentStatus::Committed, $consignment->fresh()->status, 'Dokumen tidak boleh jadi batal karena pesan gagal.');
        $this->assertSame(1, $consignment->stockLots()->count());

        // Notifikasi harus dijadwalkan ulang, bukan hilang atau menggagalkan commit.
        $this->assertDatabaseHas('notifications', [
            'consignment_id' => $consignment->id,
            'status' => NotificationStatus::Pending->value,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function throwableClasses(): array
    {
        return [
            'exception' => [\RuntimeException::class],
            'error' => [\Error::class],
            'type error' => [\TypeError::class],
        ];
    }

    /**
     * Tanpa opt-in, tidak ada pesan yang keluar.
     *
     * Ini batas privasi yang tidak bisa ditawar: SRS 6.5 hanya mengizinkan nomor
     * dengan `wa_opt_in_at`, dan opt-in adalah keputusan penitip yang dicatat.
     */
    #[Test]
    public function a_consignor_who_never_opted_in_is_not_messaged(): void
    {
        $transport = FakeTransport::alwaysSucceeds();
        $this->swapTransport($transport);

        $consignment = $this->commitConsignment($this->consignor(['wa_opt_in_at' => null]));

        $this->assertSame(0, $transport->calls);

        $notification = $consignment->notifications()->sole();
        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertStringContainsString('opt-in', (string) $notification->error_message);
        $this->assertSame(ConsignmentStatus::Committed, $consignment->fresh()->status, 'Dokumen tidak boleh jadi batal karena pesan gagal.');
    }

    /**
     * Menerima barang dua kali untuk satu penitip tidak mengirim dua nota.
     */
    #[Test]
    public function a_second_document_gets_its_own_notification(): void
    {
        $this->swapTransport(FakeTransport::alwaysSucceeds());
        $consignor = $this->consignor();
        $series = ProductSeries::factory()->create(['code' => 'HW']);

        $first = $this->commitConsignment($consignor, $series);
        $second = $this->commitConsignment($consignor, $series);

        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(
            ['consignment_receipt:'.$first->id, 'consignment_receipt:'.$second->id],
            $consignor->consignments()->join('notifications', 'notifications.consignment_id', '=', 'consignments.id')
                ->orderBy('notifications.id')
                ->pluck('notifications.notification_key')
                ->all(),
        );
    }

    // ----- Halaman dokumen -----

    #[Test]
    public function the_document_page_shows_the_status_and_a_wa_me_link(): void
    {
        $this->swapTransport(FakeTransport::alwaysSucceeds());
        $consignment = $this->commitConsignment($this->consignor());

        $this->get(route('inbound.consignment-in.detail', $consignment))
            ->assertOk()
            ->assertSee('Bukti Terima WhatsApp', escape: false)
            ->assertSee('Diserahkan ke Staff')
            ->assertSee('https://wa.me/'.self::WA, escape: false);
    }

    /**
     * Tanpa opt-in tidak boleh ada tombol yang mengirim apa pun.
     *
     * Halaman cukup menampilkan statusnya. Menyembunyikan tautannya saja tidak
     * cukup kalau tombolnya masih ada -- operator akan menekan tombol yang
     * ada, melihat toast galat, dan mencoba lagi.
     */
    #[Test]
    public function the_document_page_offers_no_handoff_without_opt_in(): void
    {
        $this->swapTransport(FakeTransport::alwaysSucceeds());
        $consignment = $this->commitConsignment($this->consignor(['wa_opt_in_at' => null]));

        $this->get(route('inbound.consignment-in.detail', $consignment))
            ->assertOk()
            ->assertSee('Gagal Dikirim')
            ->assertDontSee('https://wa.me/', escape: false)
            ->assertDontSee('Buka WhatsApp');
    }

    /**
     * Menarik opt-in setelah pesan disiapkan harus menutup jalan keluar.
     *
     * Skenarionya sempit tapi nyata: pesan sempat gagal sementara sehingga
     * notifikasi masih `PENDING`, lalu penitip mencabut persetujuannya sebelum
     * ada yang menekan kirim. Notifikasi masih terbuka, jadi halaman akan
     * menawarimu tautan, padahal persetujuannya sudah dicabut. Kalau penjaga
     * opt-in di halaman dokumen hanya mengandalkan status notifikasi, tautan
     * tetap muncul dan pesannya dikirim ke orang yang sudah bilang tidak.
     *
     * Yang diuji di sini bukan `NotificationSender` (opt-in sudah diujinya),
     * tapi `handoffLink()` di controller. Keduanya memeriksa hal yang sama dari
     * sisi berbeda, dan hanya satu yang terlihat di layar.
     */
    #[Test]
    public function withdrawing_opt_in_removes_the_handoff_link_from_the_document_page(): void
    {
        $this->swapTransport(FakeTransport::alwaysDeferred('SRS sedang sibuk.'));
        $consignor = $this->consignor();
        $consignment = $this->commitConsignment($consignor);

        $this->assertSame(
            NotificationStatus::Pending,
            $consignment->notifications()->sole()->status,
            'Prasyarat: notifikasi harus masih terbuka supaya halaman menawarkan kirim.',
        );

        $consignor->forceFill(['wa_opt_in_at' => null])->saveQuietly();

        $this->get(route('inbound.consignment-in.detail', $consignment))
            ->assertOk()
            ->assertDontSee('https://wa.me/', escape: false)
            ->assertDontSee('Buka WhatsApp');
    }

    /**
     * Halaman boleh dibuka Staff tanpa hak Owner.
     */
    #[Test]
    public function staff_can_open_the_document_page_and_send_the_receipt(): void
    {
        // rute ini Owner-only di produksi; tes ini menguji aturan bisnisnya
        $this->withoutMiddleware(OwnerOnly::class);

        $staff = User::factory()->staff()->create();
        $this->actingAs($staff);

        $this->swapTransport(FakeTransport::alwaysSucceeds());
        $consignment = $this->commitConsignment($this->consignor());

        $this->get(route('inbound.consignment-in.detail', $consignment))->assertOk();

        $this->post(route('inbound.consignment-in.e-receipt', $consignment))
            ->assertRedirect();

        $this->assertSame(
            NotificationStatus::Sent,
            $consignment->notifications()->sole()->status,
        );
    }

    /**
     * Tombol yang ditekan dua kali tidak menambah percobaan.
     */
    #[Test]
    public function pressing_send_twice_does_not_send_twice(): void
    {
        $this->swapTransport(FakeTransport::alwaysSucceeds());
        $consignment = $this->commitConsignment($this->consignor());

        $this->post(route('inbound.consignment-in.e-receipt', $consignment));
        $this->post(route('inbound.consignment-in.e-receipt', $consignment));

        $notification = $consignment->notifications()->sole();

        $this->assertSame(1, $notification->attempts);
        $this->assertDatabaseCount('notifications', 1);
    }

    /**
     * Tamu tidak boleh bisa mengirim.
     */
    #[Test]
    public function guests_cannot_send_a_receipt(): void
    {
        $this->swapTransport(FakeTransport::alwaysSucceeds());
        $consignment = $this->commitConsignment($this->consignor());

        $before = $consignment->notifications()->sole()->attempts;

        auth()->logout();

        $this->post(route('inbound.consignment-in.e-receipt', $consignment))->assertRedirect(route('login'));
        $this->assertSame(
            $before,
            $consignment->notifications()->sole()->attempts,
            'Percobaan dari tamu tidak boleh tercatat sama sekali.',
        );
    }

    /**
     * Notifikasi yang belum disiapkan tetap punya halaman yang bisa dibuka.
     *
     * Ini kondisi nyata untuk dokumen lama yang dibuat sebelum D6: tidak ada
     * baris notifikasi, tapi halamannya harus tetap terbuka. Kalau halaman ikut
     * gagal, satu dokumen lama membuat halaman Riwayat tidak bisa dibuka
     * sama sekali.
     */
    #[Test]
    public function a_document_without_a_notification_still_renders(): void
    {
        $this->get(route('inbound.consignment-in.detail', Consignment::factory()->completed()->create([
            'doc_no' => 'CI-20260101-0001',
        ])))->assertOk()->assertSee('CI-20260101-0001');
    }

    // ----- Pengaturan template -----

    #[Test]
    public function the_settings_page_lists_the_template_and_its_srs_order(): void
    {
        $this->get(route('setting.wa-template'))
            ->assertOk()
            ->assertSee('Bukti Terima Titipan')
            ->assertSee('{{1}}', escape: false)
            ->assertSee('{doc_no}', escape: false)
            ->assertSee('BAWAAN');
    }

    #[Test]
    public function a_valid_template_is_saved_and_actually_used(): void
    {
        $body = implode("\n", [
            '*Bukti Terima Titipan — {store}*',
            'No. : {doc_no}',
            'Dari : {consignor_name} ({consignor_code})',
            'Pada : {date}',
            '{item_count} item / {qty} pcs',
            '{detail}',
        ]);

        $this->put(route('setting.wa-template.update'), [
            'template' => 'consignment_receipt',
            'body' => $body,
        ])->assertSessionHasNoErrors();

        $this->assertSame($body, Setting::get(NotificationTemplate::ConsignmentReceipt->settingKey()));

        /**
         * `Setting` tidak punya primary key, jadi identitasnya masuk ke
         * `entity_key`. Dulu kuncinya dikirim ke `entity_id`, dan MySQL
         * menolaknya karena kolom itu `unsignedBigInteger` -- halaman ini
         * 500 di produksi sementara seluruh test tetap hijau, karena SQLite
         * tidak menegakkan tipe kolom.
         */
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'UPDATE_WA_TEMPLATE',
            'entity' => 'Setting',
            'entity_id' => null,
            'entity_key' => NotificationTemplate::ConsignmentReceipt->settingKey(),
        ]);

        // Dan isinya harus bisa dibaca sebagai diff: field yang sama di kedua
        // sisi, bukan `from`/`to` bersarang yang hanya bisa dibuka kalau tahu
        // bentuknya.
        $log = AuditLog::where('action', 'UPDATE_WA_TEMPLATE')->sole();

        // Dibanding tanpa urutan: MySQL menyusun ulang kunci objek pada tipe
        // `json`-nya, jadi urutan field bukan sesuatu yang bisa dijanjikan.
        $this->assertEqualsCanonicalizing(
            array_keys($log->after),
            array_keys($log->before),
            'before dan after harus punya field yang sama supaya bisa ditampilkan berdampingan.',
        );
        $this->assertNotSame($log->before['body'], $log->after['body']);

        // Dan template itu harus dipakai untuk pesan berikutnya, bukan disimpan
        // lalu diabaikan.
        $transport = FakeTransport::alwaysSucceeds();
        $this->swapTransport($transport);
        $this->commitConsignment($this->consignor());

        $this->assertStringContainsString('*Bukti Terima Titipan', (string) $transport->lastBody());
        $this->assertStringNotContainsString('Simpan pesan ini sebagai bukti', (string) $transport->lastBody());
    }

    /**
     * Variabel yang dibuang tidak boleh bisa disimpan.
     *
     * Pesan yang kehilangan `{qty}` tetap terkirim dan tetap terlihat utuh --
     * hanya saja totalsnya tidak ada. Tidak ada error, tidak ada yang melapor, dan
     * penitip yang menghitung sendiri yang menemukan. Jadi ini ditolak saat
     * menyimpan, bukan saat mengirim.
     */
    #[Test]
    public function a_template_missing_a_variable_is_rejected(): void
    {
        $this->put(route('setting.wa-template.update'), [
            'template' => 'consignment_receipt',
            'body' => 'Terima {doc_no} dari {consignor_name} ({consignor_code}) pada {date}. {item_count} item.',
        ])->assertSessionHasErrors('body');

        $this->assertNull(Setting::get(NotificationTemplate::ConsignmentReceipt->settingKey()));
    }

    /**
     * Variabel asing ditolak, bukan dibiarkan kosong.
     */
    #[Test]
    public function a_template_with_an_unknown_variable_is_rejected(): void
    {
        $body = str_replace('{qty} pcs', '{harga} pcs', NotificationTemplate::ConsignmentReceipt->defaultBody());

        $this->put(route('setting.wa-template.update'), [
            'template' => 'consignment_receipt',
            'body' => $body,
        ])->assertSessionHasErrors('body');

        $this->assertNull(Setting::get(NotificationTemplate::ConsignmentReceipt->settingKey()));
    }

    /**
     * Pesan errornya harus menyebut variabel yang bermasalah.
     */
    #[Test]
    public function the_validation_message_names_the_missing_variable(): void
    {
        $this->put(route('setting.wa-template.update'), [
            'template' => 'consignment_receipt',
            'body' => str_replace('{detail}', '', NotificationTemplate::ConsignmentReceipt->defaultBody()),
        ])->assertSessionHasErrors('body');

        $messages = session('errors')->getBag('default')->get('body');

        $this->assertNotEmpty($messages);
        $this->assertStringContainsString(
            'Variabel {detail}',
            implode(' ', $messages),
            'Pesan validasi harus menyebut variabel yang justru dibuang.',
        );
    }

    /**
     * Halaman Pengaturan harus menampilkan galat itu kembali ke Staff.
     *
     * Galat yang benar tapi tidak pernah tampil sama saja tidak ada. Kembali ke
     * halaman tanpa pesan membuat orang mengira simpanannya berhasil.
     */
    #[Test]
    public function the_settings_page_shows_the_validation_error_again(): void
    {
        $this->from(route('setting.wa-template'))
            ->put(route('setting.wa-template.update'), [
                'template' => 'consignment_receipt',
                'body' => str_replace('{detail}', '', NotificationTemplate::ConsignmentReceipt->defaultBody()),
            ]);

        $this->followingRedirects()
            ->put(route('setting.wa-template.update'), [
                'template' => 'consignment_receipt',
                'body' => str_replace('{detail}', '', NotificationTemplate::ConsignmentReceipt->defaultBody()),
            ])
            ->assertSee('Variabel {detail} belum dipakai', escape: false)
            ->assertSee(str_replace('{detail}', '', NotificationTemplate::ConsignmentReceipt->defaultBody()), escape: false);
    }

    #[Test]
    public function the_template_cannot_be_blank(): void
    {
        $this->put(route('setting.wa-template.update'), [
            'template' => 'consignment_receipt',
            'body' => '   ',
        ])->assertSessionHasErrors('body');
    }

    #[Test]
    public function an_unknown_template_kind_is_rejected(): void
    {
        $this->put(route('setting.wa-template.update'), [
            'template' => 'settlement_statement',
            'body' => NotificationTemplate::ConsignmentReceipt->defaultBody(),
        ])->assertSessionHasErrors('template');
    }

    /**
     * Log di halaman Pengaturan harus log sungguhan.
     *
     * Kalau masih hardcoded, Staff melihat tiga baris karangan yang tidak
     * pernah terjadi, dan tidak bisa mencari pesan yang benar-benar gagal
     * padahal notifikasinya ada di database.
     */
    #[Test]
    public function the_settings_log_lists_real_notifications(): void
    {
        $this->swapTransport(FakeTransport::alwaysSucceeds());
        $consignment = $this->commitConsignment($this->consignor(['consignor_code' => 'CN77']));

        $this->get(route('setting.wa-template'))
            ->assertOk()
            ->assertSee($consignment->doc_no)
            ->assertSee('Diserahkan ke Staff');
    }

    /**
     * Halaman kosong tidak boleh disamarkan sebagai punya isi.
     */
    #[Test]
    public function the_settings_log_says_so_when_there_is_nothing_yet(): void
    {
        $this->get(route('setting.wa-template'))
            ->assertOk()
            ->assertSee('Belum ada pengiriman');
    }

    #[Test]
    public function guests_cannot_read_or_change_templates(): void
    {
        auth()->logout();

        $this->put(route('setting.wa-template.update'), [
            'template' => 'consignment_receipt',
            'body' => NotificationTemplate::ConsignmentReceipt->defaultBody(),
        ])->assertRedirect();

        $this->get(route('setting.wa-template'))->assertRedirect();

        $this->assertNull(Setting::get(NotificationTemplate::ConsignmentReceipt->settingKey()));
    }

    /**
     * Template bawaan harus tetap bisa dipakai, jadi tidak boleh ada yang
     * menggantinya diam-diam.
     */
    #[Test]
    public function the_untouched_default_body_is_still_offered_for_editing(): void
    {
        $this->get(route('setting.wa-template'))
            ->assertOk()
            ->assertSee(NotificationTemplate::ConsignmentReceipt->defaultBody(), escape: false);
    }
}
