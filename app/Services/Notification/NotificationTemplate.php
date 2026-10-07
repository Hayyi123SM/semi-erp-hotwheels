<?php

namespace App\Services\Notification;

use App\Models\Setting;

/**
 * Template pesan, satu per jenis notifikasi.
 *
 * Yang ditulis di sini adalah nama variabel, bukan nomor. Cloud API menerima
 * parameter posisional (`{{1}}` sampai `{{8}}`), dan urutan itu disimpan
 * terpisah di {@see self::srsParameters()} supaya tidak pernah ikut berubah
 * kalau seseorang mengedit body di Pengaturan: pemetaan ke Cloud API nanti
 * menyalin daftar itu, bukan menebak urutan dari teks yang sedang tampil.
 *
 * Yang dipakai body tetap daftar nama. Kalau body memakai nomor (`{{3}}`)
 * sementara Pengaturan menampilkan nama, Staff yang mengganti template tidak
 * punya cara tahu yang mana isi kolom ketiganya.
 */
enum NotificationTemplate: string
{
    /**
     * Bukti terima titipan, dikirim setelah commit konsinyasi (FR-IB-16).
     */
    case ConsignmentReceipt = 'consignment_receipt';

    /**
     * Body bawaan, persis teks SRS dengan nomor parameter diganti nama.
     *
     * `qty` dipakai terpisah dari `item_count` karena "12 item" dan "40 pcs"
     * adalah dua angka yang berbeda, dan menggabungkannya membuat salah satu
     * dari keduanya hilang tanpa jejak.
     */
    public function defaultBody(): string
    {
        return match ($this) {
            self::ConsignmentReceipt => implode("\n", [
                '*Bukti Terima Titipan — {store}*',
                'No. Dokumen : {doc_no}',
                'Penitip     : {consignor_name} ({consignor_code})',
                'Tanggal     : {date}',
                'Total       : {item_count} item / {qty} pcs',
                '',
                'Rincian lengkap (skema komisi, harga, dan SKU):',
                '{detail}',
                '',
                'Simpan pesan ini sebagai bukti. Balas jika ada ketidaksesuaian.',
            ]),
        };
    }

    /**
     * Nama variabel yang wajib ada, dalam urutan parameter SRS.
     *
     * Urutan ini adalah kontrak dengan Cloud API: `{{1}}` adalah `store`,
     * `{{2}}` adalah `doc_no`, dan seterusnya sampai `{{8}}` adalah `detail`.
     * Baris judul SRS memakai `{{1}}` sementara nomor dokumen ada di baris
     * berikutnya, jadi `{{1}}` tidak mungkin `doc_no` -- kalau begitu, nomor
     * dokumen hanya punya satu parameter dan ada nama toko yang tidak pernah
     * sampai ke penitip.
     *
     * Body boleh menyusun ulang dan boleh mengulang variabel yang sama, tapi
     * daftar ini tidak boleh diurutkan ulang: yang menentukan posisi parameter
     * Cloud API adalah urutan di sini, bukan urutan kemunculan di teks.
     *
     * @return list<string>
     */
    public function srsParameters(): array
    {
        return match ($this) {
            self::ConsignmentReceipt => [
                'store',
                'doc_no',
                'consignor_name',
                'consignor_code',
                'date',
                'item_count',
                'qty',
                'detail',
            ],
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::ConsignmentReceipt => 'Bukti Terima Titipan',
        };
    }

    /**
     * Kunci setelan tempat body template disimpan.
     */
    public function settingKey(): string
    {
        return 'wa.template.'.$this->value;
    }

    /**
     * Body yang aktif: yang di-simpan Staff kalau ada, kalau tidak bawaan.
     */
    public function body(): string
    {
        $stored = Setting::get($this->settingKey());

        return is_string($stored) && trim($stored) !== '' ? $stored : $this->defaultBody();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $template): string => $template->value, self::cases());
    }
}
