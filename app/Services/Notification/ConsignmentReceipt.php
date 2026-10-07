<?php

namespace App\Services\Notification;

use App\Models\Consignment;
use App\Services\Consignment\ReceiptContent;

/**
 * Body pesan bukti terima titipan (FR-IB-16) untuk WhatsApp.
 *
 * Class ini sekarang cuma penyusun teks. Angkanya semuanya ada di
 * `App\Services\Consignment\ReceiptContent`, karena bukti terima yang sama juga
 * dicetak di kertas -- dan dua medium itu harus mengulang angka yang sama.
 *
 * Yang tidak boleh masuk ke pesan: nomor rekening, nama bank, dan nomor WhatsApp
 * orang lain. Konsinyasi berarti satu pesan bisa dibaca lebih dari satu orang.
 */
final readonly class ConsignmentReceipt
{
    private ReceiptContent $content;

    public function __construct(
        Consignment $consignment,
    ) {
        $this->content = new ReceiptContent($consignment);
    }

    /**
     * Nilai setiap variabel yang dikenali template.
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        return $this->content->variables();
    }

    /**
     * Body akhir, setelah nama variabelnya diganti isinya.
     *
     * `strtr()` dipakai, bukan `str_replace()`, supaya nama yang menjadi awalan
     * nama lain tidak saling menimpa: `str_replace(['{doc_no}', '{doc}'], ...)`
     * akan merusak `{doc_no}` kalau template punya `{doc}`. Urutan penggantian
     * `strtr()` bergantung pada panjang kunci, bukan pada urutan array.
     *
     * Kurung kurawal ikut ditambahkan pada kuncinya. `strtr()` mengganti kunci
     * dengan nilai apa adanya -- ia tidak tahu bahwa kunci itu nama variabel --
     * jadi `strtr($body, ['doc_no' => 'CI-01'])` menulis `{CI-01}` di tempat
     * `{doc_no}` tadinya, bukan `CI-01`. Pesannya tetap terkirim dan tetap
     * terlihat utuh, hanya setiap angka dan nama tertutup kurung kurawal. Tidak
     * ada error, tidak ada yang melapor, dan penitip yang menghitung sendiri
     * angkanya yang menemukan.
     *
     * Variabel yang tidak dikenal sengaja dibiarkan apa adanya, bukan
     * dikosongkan. Mengganti `{harga}` diam-diam jadi teks kosong menghasilkan
     * pesan yang terlihat rapi tapi salah, dan yang menyadarinya lambat adalah
     * penitip.
     */
    public function body(?string $template = null): string
    {
        $body = $template ?? NotificationTemplate::ConsignmentReceipt->body();

        $replacements = [];

        foreach ($this->variables() as $name => $value) {
            $replacements['{'.$name.'}'] = $value;
        }

        return trim(strtr($body, $replacements));
    }
}
