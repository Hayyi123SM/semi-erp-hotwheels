<?php

declare(strict_types=1);

namespace App\Services\Print;

use App\Enums\PaperSize;
use App\Enums\PrintMethod;
use App\Models\Setting;

/**
 * Pengaturan cetak dokumen: ukuran kertas dan cara cetak.
 *
 * Satu sumber kebenaran untuk kedua hal itu. Sebelumnya kertas punya dua rumah
 * (`receipt.paper` untuk bukti terima dan `pos.receipt_paper` untuk struk POS)
 * yang bisa saling bertentangan dan tidak ada yang tahu mana yang berlaku.
 * Sekarang kertas tinggal satu di `print.paper`, dan `print.method` menentukan
 * bagaimana dokumen dikirim ke printer.
 *
 * Pembacaan tidak pernah melempar, sama seperti `ReceiptPrinterSettings` lama:
 * baris `Setting` bisa berisi apa saja, termasuk nilai lama yang sudah tidak
 * dikenal. Nilai yang tidak terbaca menghasilkan bawaan, bukan halaman yang
 * gagal dimuat -- karena halaman cetak justru sedang dipegang Staff di depan
 * penitip.
 *
 * Kompatibilitas ke belakang: instalasi lama menyimpan kertas di `receipt.paper`
 * atau `pos.receipt_paper`. Keduanya tetap dibaca sebagai fallback sampai
 * `print.paper` disimpan, jadi toko tidak perlu kehilangan pengaturannya saat
 * versi ini pertama kali dijalankan. Yang lama TIDAK pernah ditulis lagi di
 * sini -- key global adalah satu-satunya tempat penyimpanan.
 */
final class PrintSettings
{
    public const PAPER_KEY = 'print.paper';

    public const METHOD_KEY = 'print.method';

    /**
     * Kertas yang dulu tersimpan untuk bukti terima titipan.
     */
    public const LEGACY_RECEIPT_PAPER_KEY = 'receipt.paper';

    /**
     * Kertas yang dulu tersimpan untuk struk POS.
     */
    public const LEGACY_POS_PAPER_KEY = 'pos.receipt_paper';

    private const PAPER_DEFAULT = PaperSize::Mm80;

    /**
     * Cara cetak untuk dokumen berikutnya, dengan bawaannya kalau belum disimpan.
     *
     * Default `Browser` bukan `Thermal`: browser adalah satu-satunya cara yang
     * pernah dipakai aplikasi, dan instalasi yang belum menyentuh pengaturan ini
     * tidak boleh diam-diam mencoba bicara langsung ke printer.
     */
    public function method(): PrintMethod
    {
        return $this->storedMethod() ?? PrintMethod::Browser;
    }

    /**
     * Cara cetak yang benar-benar tersimpan, atau `null` kalau belum pernah
     * disimpan atau isinya tidak dikenal.
     *
     * Bedanya dari `method()` penting untuk form Pengaturan: form harus bisa
     * membedakan "Owner memilih browser" dari "Owner belum pernah menyentuh
     * pengaturan ini".
     */
    public function storedMethod(): ?PrintMethod
    {
        $stored = Setting::many([self::METHOD_KEY => null])[self::METHOD_KEY];

        return is_string($stored) ? PrintMethod::fromSetting($stored) : null;
    }

    /**
     * Kertas untuk dokumen berikutnya, dengan bawaannya kalau belum disimpan.
     *
     * Bawaannya struk 80 mm. Bukan karena 80 mm paling umum, tapi karena 80 mm
     * muat semua kolom tanpa membungkus: lebar yang lebih sempit memaksa
     * rincian jadi dua baris per SKU, dan struk yang angkanya terlanjur
     * terbelah membuat penitip menghitung ulang sendiri -- persis yang
     * seharusnya dihilangkan oleh struk ini.
     */
    public function paper(): PaperSize
    {
        return $this->storedPaper()
            ?? $this->legacyPaper(self::LEGACY_RECEIPT_PAPER_KEY)
            ?? $this->legacyPaper(self::LEGACY_POS_PAPER_KEY)
            ?? self::PAPER_DEFAULT;
    }

    /**
     * Kertas global yang benar-benar tersimpan, atau `null`.
     *
     * Hanya key global yang dilihat: key legacy hanya fallback di `paper()`,
     * dan menganggap legacy sebagai "tersimpan" akan membuat form Pengaturan
     * menampilkan pilihan yang belum pernah dipilih Owner di tempat ini.
     */
    public function storedPaper(): ?PaperSize
    {
        $stored = Setting::many([self::PAPER_KEY => null])[self::PAPER_KEY];

        return is_string($stored) ? PaperSize::fromSetting($stored) : null;
    }

    /**
     * Kertas dari salah satu key legacy, atau `null` kalau tidak dikenal.
     */
    private function legacyPaper(string $key): ?PaperSize
    {
        $stored = Setting::many([$key => null])[$key];

        return is_string($stored) ? PaperSize::fromSetting($stored) : null;
    }
}
