<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Enums\NotificationStatus;
use App\Services\Notification\NotificationTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Status notifikasi tidak sekadar label tampilan.
 *
 * Yang menentukan adalah satu pertanyaan: "apakah pesan ini boleh dikirim
 * lagi?". Jawabannya menentukan apa yang terjadi ketika halaman dokumen
 * dibuka dua kali, ketika retry berjalan di belakang layar, dan ketika
 * tombol "Kirim Ulang" ditekan -- dan ketiganya berakhir di penitip yang
 * menerima nota ganda.
 *
 * Karena itu `SENT` tidak boleh masuk daftar boleh-kirim-lagi meski
 * transport-nya masih bisa dipanggil. `wa.me` yang sudah dibuka tidak bisa
 * diambil kembali, jadi mengulangnya bukan mengirim ulang: itu mengirim
 * kedua.
 */
class NotificationStatusTest extends TestCase
{
    #[Test]
    public function pending_and_failed_are_open_again_while_anything_sent_is_not(): void
    {
        $this->assertTrue(NotificationStatus::Pending->isOpen());
        $this->assertTrue(NotificationStatus::Failed->isOpen());

        $this->assertFalse(NotificationStatus::Sent->isOpen());
        $this->assertFalse(NotificationStatus::Delivered->isOpen());
        $this->assertFalse(NotificationStatus::Read->isOpen());
    }

    #[Test]
    public function only_failed_reports_itself_as_failed(): void
    {
        $this->assertTrue(NotificationStatus::Failed->isFailed());

        $this->assertFalse(NotificationStatus::Pending->isFailed());
        $this->assertFalse(NotificationStatus::Sent->isFailed());
        $this->assertFalse(NotificationStatus::Delivered->isFailed());
        $this->assertFalse(NotificationStatus::Read->isFailed());
    }

    #[Test]
    public function settled_means_the_message_is_already_out_there(): void
    {
        $this->assertTrue(NotificationStatus::Sent->isSettled());
        $this->assertTrue(NotificationStatus::Delivered->isSettled());
        $this->assertTrue(NotificationStatus::Read->isSettled());

        $this->assertFalse(NotificationStatus::Pending->isSettled());
        $this->assertFalse(NotificationStatus::Failed->isSettled());
    }

    /**
     * Alur SRS: `PENDING → SENT → DELIVERED → READ / FAILED`.
     *
     * @return list<array{0: NotificationStatus, 1: NotificationStatus}>
     */
    public static function legalTransitions(): array
    {
        return [
            'pending dapat dikirim' => [NotificationStatus::Pending, NotificationStatus::Sent],
            'pending dapat gagal' => [NotificationStatus::Pending, NotificationStatus::Failed],
            'sent dapat diterima webhook' => [NotificationStatus::Sent, NotificationStatus::Delivered],
            'sent dapat dibaca webhook' => [NotificationStatus::Sent, NotificationStatus::Read],
            'delivered dapat dibaca' => [NotificationStatus::Delivered, NotificationStatus::Read],
            'kegagalan sementara dapat dicoba lagi' => [NotificationStatus::Failed, NotificationStatus::Pending],
        ];
    }

    #[Test]
    #[DataProvider('legalTransitions')]
    public function legal_transitions_are_allowed(NotificationStatus $from, NotificationStatus $to): void
    {
        $this->assertTrue($from->canTransitionTo($to));
        $this->assertContains($to, $from->allowedTransitions());
    }

    /**
     * @return list<array{0: NotificationStatus, 1: NotificationStatus}>
     */
    public static function illegalTransitions(): array
    {
        return [
            'pending tidak langsung dibaca' => [NotificationStatus::Pending, NotificationStatus::Read],
            'pending tidak langsung diterima' => [NotificationStatus::Pending, NotificationStatus::Delivered],
            'read tidak bisa mundur ke pending' => [NotificationStatus::Read, NotificationStatus::Pending],
            'read tidak bisa jadi gagal' => [NotificationStatus::Read, NotificationStatus::Failed],
            'delivered tidak bisa mundur ke sent' => [NotificationStatus::Delivered, NotificationStatus::Sent],
            'status tidak bisa ke dirinya sendiri' => [NotificationStatus::Pending, NotificationStatus::Pending],
        ];
    }

    #[Test]
    #[DataProvider('illegalTransitions')]
    public function illegal_transitions_are_rejected(NotificationStatus $from, NotificationStatus $to): void
    {
        $this->assertFalse($from->canTransitionTo($to));
        $this->assertNotContains($to, $from->allowedTransitions());
    }

    /**
     * Badge harus bisa dipakai apa adanya di view.
     *
     * `x-ui.badge-status` punya empat warna, dan nilai di luar itu jatuh ke
     * warna biru lewat `?? $config['info']`. Jadi penulisan yang salah di sini
     * tidak akan terlihat sebagai galat: status "Gagal" akan tampil biru, dan
     * operator membacanya sebagai netral.
     */
    #[Test]
    public function every_status_maps_to_a_badge_type_that_actually_exists(): void
    {
        $supported = ['success', 'error', 'warning', 'info'];

        foreach (NotificationStatus::cases() as $status) {
            $this->assertContains(
                $status->type(),
                $supported,
                "Status {$status->value} memakai warna badge yang tidak tersedia.",
            );
            $this->assertNotSame('', $status->label());
        }
    }

    /**
     * Label "Diserahkan ke Staff" adalah keputusan, bukan pilihan kalimat.
     *
     * Dengan `wa.me` tidak ada bukti Meta yang mengirim pesan. Kalau labelnya
     * "Terkirim", halaman dokumen menyatakan sesuatu yang tidak diketahui
     * sistem, dan Staff berhenti memeriksa karena layar sudah bilang selesai.
     */
    #[Test]
    public function sent_is_labelled_as_handed_over_not_as_delivered(): void
    {
        $this->assertSame('Diserahkan ke Staff', NotificationStatus::Sent->label());
        $this->assertNotSame(NotificationStatus::Sent->label(), NotificationStatus::Delivered->label());
    }

    /**
     * Template harus menyebut variabel yang memang ada, semuanya.
     *
     * Kalau bawaan ini kehilangan satu variabel, validasi Pengaturan akan
     * menolak body yang persis sama dengan bawaan -- dan penolakan itu akan dibaca
     * sebagai "template bawaan rusak", bukan "saya lupa menambahkan variabel".
     * Uji ini supaya kelas template yang gagal diuji duluan, bukan settings.
     */
    #[Test]
    public function default_body_uses_every_srs_parameter_exactly_as_named(): void
    {
        foreach (NotificationTemplate::cases() as $template) {
            foreach ($template->srsParameters() as $name) {
                $this->assertStringContainsString(
                    '{'.$name.'}',
                    $template->defaultBody(),
                    "Template {$template->value} tidak memakai variabel {{$name}}.",
                );
            }

            $this->assertNotSame('', trim($template->defaultBody()));
        }
    }

    /**
     * Urutan parameter adalah kontrak dengan Cloud API, bukan gaya penulisan.
     *
     * `{{1}}` ada di baris judul dan `{{2}}` adalah nomor dokumen. Kalau
     * `doc_no` menempati posisi pertama, nomor dokumen hanya punya satu
     * parameter dan nama toko tidak pernah sampai ke penitip -- dan karena
     * template bawaan masih utuh, tidak ada apa pun yang gagal.
     */
    #[Test]
    public function srs_parameter_order_puts_store_first_and_doc_no_second(): void
    {
        $parameters = NotificationTemplate::ConsignmentReceipt->srsParameters();

        $this->assertSame('store', $parameters[0]);
        $this->assertSame('doc_no', $parameters[1]);
        $this->assertCount(8, $parameters, 'SRS memakai 8 parameter; jumlah ini harus tetap sama.');
        $this->assertSame($parameters, array_values(array_unique($parameters)));
    }
}
