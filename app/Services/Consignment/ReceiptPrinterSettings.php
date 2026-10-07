<?php

declare(strict_types=1);

namespace App\Services\Consignment;

use App\Enums\PaperSize;
use App\Models\Setting;
use App\Services\Print\PrintSettings;

/**
 * Pengaturan yang menyangkut alur bukti terima titipan.
 *
 * Kertas bukti terima tidak lagi milik kelas ini: bukti terima memakai
 * `PrintSettings::paper()`, sumber tunggal yang juga dipakai cetakan lain.
 * Yang tersisa di sini hanya `post_commit_mode`, perilaku khusus setelah commit
 * consignment -- hal yang tidak dimiliki cetakan lain.
 *
 * Metode `paper()` dan `storedPaper()` dipertahankan sebagai jalan pintas ke
 * global supaya pemanggil yang sudah terbiasa (`InboundController`, test)
 * tidak perlu tahu dari mana kertas berasal.
 *
 * Pembacaan tidak pernah melempar, sama seperti `LabelPrinterSettings`: baris
 * `Setting` bisa berisi apa saja, termasuk nilai lama yang sudah tidak dikenal.
 * Nilai yang tidak terbaca menghasilkan bawaan, bukan halaman yang gagal dimuat,
 * karena halaman ini justru sedang dipegang Staff ketika menyerahkan barang.
 */
final class ReceiptPrinterSettings
{
    public const POST_COMMIT_MODE_KEY = 'receipt.post_commit_mode';

    public function __construct(
        private readonly PrintSettings $printSettings,
    ) {}

    /**
     * Kertas untuk cetak berikutnya, dengan bawaannya kalau belum disimpan.
     */
    public function paper(): PaperSize
    {
        return $this->printSettings->paper();
    }

    /**
     * Kertas global yang benar-benar tersimpan, atau `null`.
     */
    public function storedPaper(): ?PaperSize
    {
        return $this->printSettings->storedPaper();
    }

    /**
     * Perilaku setelah commit consignment.
     *
     * - auto_print: redirect ke bukti terima dengan ?auto=1 (default, backward compatible)
     * - preview: redirect ke bukti terima tanpa auto print
     * - go_to_labels: redirect ke halaman cetak label
     */
    public function postCommitMode(): string
    {
        $stored = Setting::many([self::POST_COMMIT_MODE_KEY => null])[self::POST_COMMIT_MODE_KEY];
        $mode = is_string($stored) && $stored !== '' ? $stored : 'auto_print';

        return in_array($mode, ['auto_print', 'preview', 'go_to_labels'], true)
            ? $mode
            : 'auto_print';
    }
}
