<?php

namespace App\Enums;

use App\Support\Format;

/**
 * Aksi yang tercatat di `audit_logs`.
 *
 * `audit_logs.action` disimpan sebagai teks, bukan sebagai kolom enum. Itu
 * disengaja: kolomnya `varchar(60)` dan sudah terisi, sementara mengubahnya
 * menjadi enum berarti menyentuh SETIAP titik pemanggilan `AuditLogger` di
 * seluruh aplikasi. Enum di sini hanya satu arah -- dari kode aksi ke nama yang
 * dibacakan manusia -- jadi baris lama yang kodenya tidak dikenal pun masih
 * bisa ditampilkan.
 *
 * Karena itu setiap pembacaan kode di sini wajib punya nilai cadangan yang
 * jujur. Menebak kode yang tidak dikenal menghasilkan "`Update Something`" di
 * halaman laporan audit, dan itu lebih buruk daripada "`UPDATE_SOMETHING`":
 * yang pertama terlihat seperti penjelasan, yang kedua terlihat seperti data.
 */
enum AuditAction: string
{
    // Data master.
    case Created = 'CREATED';
    case Updated = 'UPDATED';
    case Deleted = 'DELETED';
    case Active = 'ACTIVE';
    case Archived = 'ARCHIVED';
    case Review = 'REVIEW';
    case Import = 'IMPORT';

    // Penerimaan barang.
    case ReceiveOwn = 'RECEIVE_OWN';
    case ReceiveConsign = 'RECEIVE_CONSIGN';

    // Otorisasi.
    case AuthorizePin = 'AUTHORIZE_PIN';

    // Siklus cetak label.
    case PrintLabels = 'PRINT_LABELS';
    case ConfirmLabels = 'CONFIRM_LABELS';
    case FailLabels = 'FAIL_LABELS';
    case RetryLabels = 'RETRY_LABELS';
    case ReprintLabels = 'REPRINT_LABELS';

    // Anomali cetak ulang. Dicatat terpisah dari `ReprintLabels` supaya laporan
    // anomali bisa menyaringnya tanpa ikut menghitung semua cetakan biasa.
    case ReprintOverQty = 'LABEL_REPRINT_OVER_QTY';
    case ReprintDailyLimit = 'LABEL_REPRINT_DAILY_LIMIT';

    // Serah terima bukti terima titipan ke penitip.
    case HandOverReceipt = 'CONSIGNMENT_RECEIPT_HANDED_OVER';
    case HandOverReceiptReprint = 'CONSIGNMENT_RECEIPT_REPRINT';

    // Siklus shift kasir.
    case OpenShift = 'OPEN_SHIFT';
    case CloseShift = 'CLOSE_SHIFT';

    // Penataan rak dan siklus opname.
    case TransferRack = 'TRANSFER_RACK';
    case OpnameStart = 'OPNAME_START';
    case OpnameSubmit = 'OPNAME_SUBMIT';
    case OpnameApprove = 'OPNAME_APPROVE';
    case OpnameReject = 'OPNAME_REJECT';
    case OpnameCancel = 'OPNAME_CANCEL';

    // Retur ke penitip (RTV). Eksekusinya dicatat terpisah dari pembuatan dan
    // staging karena hanya eksekusilah yang mengurangi stok; sebuah sesi bisa
    // dibuat dan dibatalkan berkali-kali tanpa satu unit pun bergerak.
    case RtvCreate = 'RTV_CREATE';
    case RtvStaging = 'RTV_STAGING_MOVE';
    case RtvExecute = 'RTV_EXECUTE';
    case RtvCancel = 'RTV_CANCEL';

    // Pengaturan.
    case UpdatePrinterSettings = 'UPDATE_PRINTER_SETTINGS';
    case UpdateWaTemplate = 'UPDATE_WA_TEMPLATE';
    case UpdateReceiptPrinterSettings = 'UPDATE_RECEIPT_PRINTER_SETTINGS';
    case UpdatePosSettings = 'UPDATE_POS_SETTINGS';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Dibuat',
            self::Updated => 'Diubah',
            self::Deleted => 'Dihapus',
            self::Active => 'Diaktifkan',
            self::Archived => 'Diarsipkan',
            self::Review => 'Ditinjau',
            self::Import => 'Impor',
            self::ReceiveOwn => 'Terima Stok Sendiri',
            self::ReceiveConsign => 'Terima Titipan',
            self::AuthorizePin => 'PIN Diberikan',
            self::PrintLabels => 'Label Dikirim ke Printer',
            self::ConfirmLabels => 'Label Dikonfirmasi',
            self::FailLabels => 'Label Gagal Dicetak',
            self::RetryLabels => 'Label Dikembalikan ke Antrean',
            self::ReprintLabels => 'Cetak Ulang Diminta',
            self::ReprintOverQty => 'Cetak Ulang Melebihi Qty',
            self::ReprintDailyLimit => 'Cetak Ulang Melebihi Jatah',
            self::HandOverReceipt => 'Bukti Terima Diserahkan',
            self::HandOverReceiptReprint => 'Bukti Terima Dicetak Ulang',
            self::OpenShift => 'Shift Kasir Dibuka',
            self::CloseShift => 'Shift Kasir Ditutup',
            self::TransferRack => 'Barang Pindah Rak',
            self::OpnameStart => 'Sesi Opname Dimulai',
            self::OpnameSubmit => 'Sesi Opname Diajukan',
            self::OpnameApprove => 'Selisih Opname Disetujui',
            self::OpnameReject => 'Selisih Opname Ditolak',
            self::OpnameCancel => 'Sesi Opname Dibatalkan',
            self::RtvCreate => 'Sesi RTV Dibuat',
            self::RtvStaging => 'Barang Dipindah ke Rak Staging RTV',
            self::RtvExecute => 'RTV Dieksekusi',
            self::RtvCancel => 'Sesi RTV Dibatalkan',
            self::UpdatePrinterSettings => 'Pengaturan Printer Diubah',
            self::UpdateWaTemplate => 'Template WhatsApp Diubah',
            self::UpdateReceiptPrinterSettings => 'Pengaturan Printer Struk Diubah',
            self::UpdatePosSettings => 'Pengaturan POS Diubah',
        };
    }

    /**
     * Warna badge di laporan.
     *
     * Yang menentukan warna bukan "dibuat" atau "dihapus" semata, tapi apakah
     * seseorang perlu berhenti dan membacanya. Cetak ulang, kegagalan cetak,
     * dan perubahan pengaturan memakai warna peringatan: ketiganya mengubah
     * angka yang dipakai orang lain saat menghitung stok dan riwayat label.
     */
    public function type(): string
    {
        return match ($this) {
            self::Deleted, self::Archived, self::FailLabels => 'error',
            self::Created, self::Active, self::ConfirmLabels, self::AuthorizePin,
            self::HandOverReceipt => 'success',
            self::ReprintOverQty, self::ReprintDailyLimit, self::OpnameApprove,
            self::OpnameCancel, self::RtvExecute => 'warning',
            self::PrintLabels, self::RetryLabels, self::ReprintLabels,
            self::HandOverReceiptReprint, self::TransferRack, self::OpnameStart,
            self::OpnameSubmit, self::OpnameReject,
            self::RtvCreate, self::RtvStaging, self::RtvCancel,
            self::UpdateReceiptPrinterSettings,
            self::UpdatePosSettings => 'info',
            default => 'neutral',
        };
    }

    /**
     * Nama yang ditampilkan untuk kode aksi apa pun, terdaftar atau tidak.
     *
     * Kode yang tidak dikenal dikembalikan apa adanya, bukan diterjemahkan
     * dengan menebak kata. `UPDATE_SOMETHING` dibaca manusia sebagai "ada
     * sesuatu yang belum saya kenal"; `Update Something` dibaca sebagai
     * penjelasan, dan itu tidak jujur.
     */
    public static function labelFor(?string $code): string
    {
        if ($code === null || $code === '') {
            return Format::EMPTY;
        }

        return self::tryFrom($code)?->label() ?? $code;
    }

    /**
     * Warna badge untuk kode aksi apa pun, terdaftar atau tidak.
     *
     * Kode asing mendapat warna netral, bukan warna aksi tertentu. Menempelkan
     * warna merah pada baris yang belum dipahami menghasilkan alarm palsu, dan
     * alarm palsu yang sering diabaikan akan berhenti dibaca sama sekali.
     */
    public static function typeFor(?string $code): string
    {
        return self::tryFrom($code ?? '')?->type() ?? 'neutral';
    }
}
