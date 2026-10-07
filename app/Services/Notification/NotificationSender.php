<?php

namespace App\Services\Notification;

use App\Enums\NotificationStatus;
use App\Models\Consignment;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notification\Transport\WhatsappTransport;
use App\Support\WhatsappNumber;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Penyiapan dan pengiriman notifikasi.
 *
 * Yang dikehendaki di sini, berurutan dari yang paling mudah salah:
 *
 * 1. Pesan gagal tidak pernah membatalkan dokumen. Tabel penanganan error
 *    inbound menyatakan "WhatsApp gagal terkirim -> dokumen tetap selesai".
 *    Notifikasi karena itu ditulis di luar transaksi commit, dan kegagalan di
 *    sini tidak pernah dilempar ke pemanggil.
 *
 * 2. Idempoten. `notification_key`-nya unique, jadi "Kirim" ditekan dua kali
 *    karena operator tidak yakin tombolnya masuk menghasilkan satu notifikasi
 *    dengan dua percobaan, bukan dua nota untuk penitip yang sama.
 *
 * 3. Gagal karena alasan yang tidak akan hilang sendiri tidak diulang. Opt-in
 *    yang belum dicentang adalah keputusan penitip, bukan gangguan sesaat;
 *    mencobanya lima kali dalam enam hari hanya menambah lima baris gagal di
 *    log tanpa pernah mengubah hasilnya.
 */
class NotificationSender
{
    /**
     * Jeda sebelum percobaan berikutnya, dalam hitungan menit.
     *
     * SRS 6.5: 1m, 5m, 30m, 2hari, 6hari, maksimal lima percobaan. Yang
     * disimpan hanya menitnya supaya pengujian tidak perlu memanipulasi tanggal
     * absolut -- `Carbon::setTestNow` cukup untuk menggeser satu jam, tapi
     * tidak untuk "kembali keesokan hari" tanpa menulis ulang expectativas.
     *
     * @var list<int>
     */
    private const array BACKOFF_MINUTES = [1, 5, 30, 2880, 8640];

    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly WhatsappTransport $transport,
    ) {}

    /**
     * Siapkan notifikasi untuk satu dokumen, tanpa mengirim.
     *
     * Dipisah dari `send()` karena ada pemanggil yang hanya butuh tahu
     * "pesan ini sudah disiapkan": halaman detail menampilkan statusnya, dan
     * operator belum tentu mau mengirim dari sana.
     */
    public function prepare(Consignment $consignment, NotificationTemplate $template): Notification
    {
        $notification = Notification::query()->firstOrCreate(
            ['notification_key' => self::keyFor($template, $consignment)],
            [
                'channel' => 'WHATSAPP',
                'template_name' => $template->value,
                'recipient' => (string) ($consignment->consignor?->wa_number ?? ''),
                'consignment_id' => $consignment->getKey(),
            ],
        );

        /**
         * Status ditulis eksplisit setelah baris dibuat, bukan mengandalkan
         * default kolom dan bukan dengan mass-assignment.
         *
         * Dua masalah ikut dipecah di sini. Default kolom ada di database tapi
         * belum ada di objek yang dikembalikan `firstOrCreate()`, jadi pemanggil
         * yang langsung membaca `$notification->status` mendapat `null`. Dan
         * `status` sengaja tidak mass-assignable supaya request dari browser tidak
         * bisa menuliskannya -- jadi `create()` juga akan membuangnya diam-diam.
         * Melewati keduanya berarti satu galat yang menyebut "member function on
         * null", bukan "status kosong" yang jelas.
         */
        if ($notification->wasRecentlyCreated) {
            $notification->forceFill(['status' => NotificationStatus::Pending])->saveQuietly();
        }

        return $notification;
    }

    /**
     * Kirim satu notifikasi, atau jelaskan kenapa tidak bisa.
     *
     * Tidak pernah melempar galat, termasuk galat yang tidak terduga. Pemanggil
     * atau commit sudah selesai, dan satu-satunya hal yang benar setelah
     * notifikasi gagal adalah mencatatnya sambil membiarkan dokumen tetap utuh.
     *
     * Karena itu `send()` dibungkus `try/catch` penuh atas `Throwable`, bukan
     * hanya `Exception` yang sudah diprediksi. Janji "tidak melempar" yang dibuat
     * service ini adalah hal yang bisa diuji; transport-nya belum. Begitu ada
     * kelas yang melempar -- koneksi putus, pustaka yang diperbarui, binding
     * yang keliru -- yang hilang hanya notifikasi, karena itu bisa dikirim ulang
     * dari halaman dokumen. Yang tidak bisa diulang adalah commit barang.
     */
    public function send(Notification $notification, ?User $actor = null): Notification
    {
        if (! $notification->status->isOpen()) {
            return $notification;
        }

        $blocker = $this->blockedBy($notification);

        if ($blocker !== null) {
            return $this->markFailed($notification, $blocker);
        }

        try {
            $body = $notification->body ?? $this->bodyFor($notification);
            $result = $this->transport->send($notification->recipient, $body);
        } catch (Throwable $exception) {
            report($exception);

            /**
             * Galat tak terduga dianggap belum bisa dicoba lagi.
             *
             * Yang terjadi setelah lima percobaan menandai ia permanen, jadi
             * tidak perlu tebak. Yang perlu dijaga hanya satu: jangan sampai
             * exception-nya ikut naik dan menggagalkan commit.
             */
            return $this->reschedule($notification, sprintf(
                'Gangguan tak terduga saat mengirim: %s',
                $exception->getMessage(),
            ));
        }

        if ($result->sent) {
            return $this->markSent($notification, $actor);
        }

        if (! $result->retryable) {
            return $this->markFailed($notification, (string) $result->failure);
        }

        return $this->reschedule($notification, (string) $result->failure);
    }

    /**
     * Isi pesan dan simpan salinannya.
     *
     * Disimpan, bukan sekadar dihitung sekali jalan, karena `body` adalah satu-
     *-satunya catatan isi pesan yang benar-benar dikirim. Kalau isinya hanya
     * hidup di memori sampai transport mengembalikan hasil, maka begitu notifikasi
     * gagal lalu template diubah di Pengaturan, isi yang sebenarnya terkirim sudah
     * tidak ada di mana pun -- dan pertanyaan "apa yang sebenarnya kita kirim ke
     * penitip?" tidak punya jawaban.
     */
    private function bodyFor(Notification $notification): string
    {
        $body = new ConsignmentReceipt($notification->consignment);
        $rendered = $body->body();

        $notification->snapshotBody($rendered);

        return $rendered;
    }

    /**
     * Siapkan sekaligus kirim, untuk jalur otomatis setelah commit.
     */
    public function sendReceipt(Consignment $consignment, ?User $actor = null): Notification
    {
        $notification = $this->prepare($consignment, NotificationTemplate::ConsignmentReceipt);

        if ($notification->status->isSettled()) {
            return $notification;
        }

        return $this->send($notification, $actor);
    }

    /**
     * Alasan kenapa notifikasi ini tidak boleh dikirim, atau `null` kalau boleh.
     *
     * Opt-in diperiksa di sini dan bukan di pemanggil karena ada dua pemanggil
     * -- otomatis setelah commit dan tombol "Kirim Ulang" -- dan opt-in adalah
     * syarat yang tidak boleh dilewati salah satunya.
     */
    private function blockedBy(Notification $notification): ?string
    {
        $consignor = $notification->consignment?->consignor;

        if ($consignor === null) {
            return 'Dokumen ini tidak punya penitip, jadi tidak ada yang bisa diberi tahu.';
        }

        if ($consignor->wa_opt_in_at === null) {
            return $consignor->wa_number === null
                ? 'Penitip belum mengisi nomor WhatsApp.'
                : 'Penitip belum mencentang opt-in WhatsApp.';
        }

        if (! WhatsappNumber::isValid($consignor->wa_number)) {
            return 'Nomor WhatsApp penitip tidak valid.';
        }

        return null;
    }

    private function markSent(Notification $notification, ?User $actor): Notification
    {
        $notification->forceFill([
            'status' => NotificationStatus::Sent->value,
            'attempts' => $notification->attempts + 1,
            'sent_at' => now(),
            'error_message' => null,
            'requested_by' => $actor?->getKey() ?? $notification->requested_by,
        ])->saveQuietly();

        return $notification;
    }

    /**
     * @param  int|null  $attempts  Berapa banyak percobaan yang sudah terpakai.
     *                              Kosongkan kalau pemanggilnya sudah menghitung
     *                              sendiri -- dan itulah sebabnya parameter ini ada.
     */
    private function markFailed(Notification $notification, string $reason, ?int $attempts = null): Notification
    {
        $notification->forceFill([
            'status' => NotificationStatus::Failed->value,
            'attempts' => $attempts ?? $notification->attempts + 1,
            'failed_at' => now(),
            // `next_attempt_at` dikosongkan supaya notifikasi ini tidak ikut
            // diambil antrean. Kalau dibiarkan terisi, pengirim akan
            // mengambilnya lagi nanti dan memunculkan kegagalan yang sama.
            'next_attempt_at' => null,
            'error_message' => $reason,
        ])->saveQuietly();

        return $notification;
    }

    /**
     * Tandai percobaan berikutnya, atau nyatakan sudah tidak ada yang tersisa.
     */
    private function reschedule(Notification $notification, string $reason): Notification
    {
        $attempts = $notification->attempts + 1;

        /**
         * Jumlah percobaan diteruskan ke `markFailed`, bukan dibiarkan dihitung
         * lagi di sana.
         *
         * Kalau tidak, percobaan kelima tercatat sebagai enam. Angka
         * itu muncul sebagai "6 percobaan" di halaman dokumen, dan tidak ada yang
         * bisa menjelaskan dari mana enam datangnya -- batasnya lima, dan
         * tidak ada jalur yang boleh menambahkannya.
         */
        if ($attempts >= self::MAX_ATTEMPTS) {
            return $this->markFailed(
                $notification,
                sprintf('Gagal setelah %d percobaan. %s', $attempts, $reason),
                $attempts,
            );
        }

        $notification->forceFill([
            'status' => NotificationStatus::Pending->value,
            'attempts' => $attempts,
            'next_attempt_at' => $this->nextAttemptAt($attempts),
            'error_message' => $reason,
        ])->saveQuietly();

        return $notification;
    }

    /**
     * Kapan percobaan berikutnya jatuh tempo.
     *
     * Indeksnya `attempts` yang baru, jadi percobaan pertama dijeda satu menit
     * dan bukan langsung dicoba lagi di detik yang sama.
     */
    private function nextAttemptAt(int $attempts): Carbon
    {
        $index = max(0, min($attempts - 1, count(self::BACKOFF_MINUTES) - 1));

        return now()->addMinutes(self::BACKOFF_MINUTES[$index]);
    }

    /**
     * Kunci idempotensi, persis seperti contoh di SRS 6.5:
     * `consignment_receipt:{consignment_id}`.
     */
    public static function keyFor(NotificationTemplate $template, Consignment $consignment): string
    {
        return $template->value.':'.$consignment->getKey();
    }
}
