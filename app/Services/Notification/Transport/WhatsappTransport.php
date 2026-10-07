<?php

namespace App\Services\Notification\Transport;

/**
 * WAJIB mengembalikan `deferred()` hanya untuk kegagalan yang akan hilang
 * sendiri, dan `refused()` untuk yang tidak. Nomor yang tidak ada atau opt-in
 * yang belum dicentang termasuk yang kedua: mengulangnya hanya mengisi log
 * dengan percobaan yang pasti gagal lagi, dan menahan Staff dengan tombol
 * "Kirim Ulang" yang memang tidak akan menolong.
 */
interface WhatsappTransport
{
    /**
     * Coba kirim satu pesan.
     *
     * @param  string  $recipient  nomor dalam bentuk tersimpan: `62` lalu digit
     */
    public function send(string $recipient, string $body): TransportResult;

    /**
     * Tautan yang bisa dibuka operator, kalau ada.
     *
     * Hanya relevan untuk transport yang butuh bantuan manusia. Driver yang
     * mengirim sendiri mengembalikan `null`, karena tidak ada yang perlu dibuka
     * -- dan menampilkan tombol "Buka WhatsApp" untuk pesan yang sudah terkirim
     * hanya menggoda Staff mengirimnya dua kali.
     */
    public function handoffLink(string $recipient, string $body): ?string;
}
