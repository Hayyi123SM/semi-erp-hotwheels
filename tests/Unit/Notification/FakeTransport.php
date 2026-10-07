<?php

declare(strict_types=1);

namespace Tests\Unit\Notification;

use App\Services\Notification\Transport\TransportResult;
use App\Services\Notification\Transport\WhatsappTransport;

/**
 * Transport yang jawabannya sudah ditentukan, untuk menguji
 * `NotificationSender` tanpa bergantung pada jaringan.
 *
 * Dua alasan kenapa `wa.me` sungguhan tidak bisa dipakai di sini.
 *
 * Yang pertama: `WaMeTransport` hanya bisa gagal dengan satu cara, yaitu
 * nomornya tidak valid. Jadi jalur yang paling rawan -- retry, backoff, dan
 * batas lima percobaan -- tidak akan pernah tersentuh kalau tes memakai
 * transport asli. Justru jalur itulah yang paling jarang gagal dan paling
 * mahal kalau sampai bocor, karena gejalanya muncul berminggu-minggu setelahnya.
 *
 * Yang kedua: menguji "coba lagi" lewat tautan berarti menghitung tautan
 * yang dibuat, bukan transport yang dipanggil. Dua hal berbeda, dan yang
 * kedua menguji bentuk di bawah ini.
 */
final class FakeTransport implements WhatsappTransport
{
    public int $calls = 0;

    /** @var list<string> */
    public array $recipients = [];

    /** @var list<string> */
    public array $bodies = [];

    /**
     * @param  list<TransportResult>  $queue  Balasan berurutan. Setelah habis,
     *                                        dipakai kembali hasil terakhir --
     *                                        supaya "selalu gagal" cukup ditulis
     *                                        sekali, bukan diulang tiap iterasi.
     */
    public function __construct(
        private array $queue = [],
    ) {
        if ($this->queue === []) {
            $this->queue = [TransportResult::sent()];
        }
    }

    public static function alwaysSucceeds(): self
    {
        return new self([TransportResult::sent()]);
    }

    public static function alwaysDeferred(string $reason = 'SRS sedang sibuk.'): self
    {
        return new self([TransportResult::deferred($reason)]);
    }

    public static function sequence(TransportResult ...$results): self
    {
        return new self($results);
    }

    public function send(string $recipient, string $body): TransportResult
    {
        $index = min($this->calls, count($this->queue) - 1);

        $this->calls++;
        $this->recipients[] = $recipient;
        $this->bodies[] = $body;

        return $this->queue[$index];
    }

    public function handoffLink(string $recipient, string $body): ?string
    {
        return 'https://wa.me/'.$recipient.'?text='.urlencode($body);
    }

    public function lastBody(): ?string
    {
        return $this->bodies === [] ? null : $this->bodies[array_key_last($this->bodies)];
    }
}
