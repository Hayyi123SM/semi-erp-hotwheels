<?php

namespace App\Services\Notification\Transport;

use App\Support\WhatsappNumber;

/**
 * Transport resmi MVP: tautan `wa.me` untuk dikirim manual oleh Staff.
 *
 * `wa.me` bukan pilihan sementara yang menyedihkan. SRS 6.5 menetapkannya
 * sebagai fallback resmi, dan tanpa kredensial Cloud API tidak ada pilihan lain
 * yang jujur: menandai pesan "terkirim" padahal tidak ada yang dikirim adalah
 * kebohongan yang menutupi dirinya sendiri. Yang dilakukan di sini adalah
 * membangun tautannya, lalu menyatakan apa adanya bahwa pengirimannya masih di
 * tangan manusia.
 *
 * Karena tidak ada jaringan yang dipakai, `send()` hampir tidak pernah gagal.
 * Kegagalan yang tidak akan hilang sendiri -- opt-in belum dicentang, nomor
 * kosong -- ditangani `NotificationSender` sebagai prasyarat sebelum transport
 * dipanggil. Satu-satunya penolakan di sini adalah nomor yang bentuknya masih
 * tidak bisa dijadikan tautan, dan itu tidak akan membaik dengan dicoba lagi.
 */
final class WaMeTransport implements WhatsappTransport
{
    public function send(string $recipient, string $body): TransportResult
    {
        return $this->handoffLink($recipient, $body) === null
            ? TransportResult::refused('Nomor WhatsApp tidak bisa dijadikan tautan.')
            : TransportResult::sent();
    }

    public function handoffLink(string $recipient, string $body): ?string
    {
        return WhatsappNumber::chatLink($recipient, $body);
    }
}
