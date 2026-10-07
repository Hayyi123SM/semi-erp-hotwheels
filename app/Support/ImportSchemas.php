<?php

namespace App\Support;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Enums\ConsignorStatus;
use App\Enums\DiscountPolicy;
use App\Enums\LossLiability;
use App\Enums\PackagingType;
use App\Enums\ProductStatus;
use App\Enums\RackType;
use App\Enums\Role;
use App\Enums\SchemeType;
use App\Enums\SettlementCycle;
use App\Rules\UniqueWhatsappNumber;
use App\Rules\WhatsappNumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Skema kolom untuk Impor Excel & mapping manual.
 *
 * Satu item memiliki:
 *  - key        : kunci unik formulir (name input di halaman mapping)
 *  - label      : label yang tampil di UI mapping
 *  - store      : atribut model tujuan (default = key)
 *  - combineOrder : untuk menggabungkan 2 kolom menjadi satu nilai (mis. nama produk)
 *  - type       : string | money | integer | date | boolean | tags | enum | lookup
 *  - enum       : kelas enum (saat type = enum)
 *  - lookup     : 'series' | 'consignor' (saat type = lookup)
 *  - rules      : rule validasi (kunci = store key)
 */
class ImportSchemas
{
    public static function all(): array
    {
        return ['penitip', 'katalog', 'rak', 'pengguna'];
    }

    public static function for(string $module): array
    {
        return match ($module) {
            'penitip' => self::penitip(),
            'katalog' => self::katalog(),
            'rak' => self::rak(),
            'pengguna' => self::pengguna(),
            default => throw new \InvalidArgumentException("Modul impor [$module] tidak dikenal."),
        };
    }

    /**
     * Daftar kolom spreadsheet (A, B, C ...).
     */
    public static function columns(int $count): array
    {
        $columns = [];
        for ($i = 0; $i < $count; $i++) {
            $columns[] = Coordinate::stringFromColumnIndex($i + 1);
        }

        return $columns;
    }

    private static function penitip(): array
    {
        return [
            'module' => 'penitip',
            'title' => 'Import Data Penitip',
            'subtitle' => 'Kolom dengan tanda * wajib. Abaikan kolom yang tidak perlu dipetakan.',
            'items' => [
                ['key' => 'name', 'label' => 'Nama Penitip', 'store' => 'name', 'type' => 'string', 'required' => true, 'rules' => ['name' => ['required', 'max:255']]],
                ['key' => 'wa_number', 'label' => 'WhatsApp', 'store' => 'wa_number', 'type' => 'string', 'rules' => ['wa_number' => ['nullable', 'max:30', new WhatsappNumberFormat, new UniqueWhatsappNumber]]],
                ['key' => 'address', 'label' => 'Alamat', 'store' => 'address', 'type' => 'string', 'rules' => ['address' => ['nullable', 'string']]],
                ['key' => 'agreement_date', 'label' => 'Tanggal Perjanjian', 'store' => 'agreement_date', 'type' => 'date', 'rules' => ['agreement_date' => ['nullable', 'date']]],
                ['key' => 'scheme_type', 'label' => 'Skema (PERCENTAGE/NETT/FLAT)', 'store' => 'scheme_type', 'type' => 'enum', 'enum' => SchemeType::class, 'default' => 'PERCENTAGE', 'rules' => ['scheme_type' => ['required', 'in:PERCENTAGE,NETT,FLAT']]],
                ['key' => 'scheme_rate', 'label' => 'Persen (%)', 'store' => 'scheme_rate', 'type' => 'percentage', 'rules' => ['scheme_rate' => ['required_if:scheme_type,PERCENTAGE', 'nullable', 'numeric', 'between:0,100']]],
                ['key' => 'scheme_amount', 'label' => 'Nilai Skema (Rp/unit)', 'store' => 'scheme_amount', 'type' => 'money', 'rules' => ['scheme_amount' => ['required_if:scheme_type,NETT,FLAT', 'nullable', 'integer', 'min:0']]],
                ['key' => 'settlement_cycle', 'label' => 'Siklus (WEEKLY/BIWEEKLY/MONTHLY)', 'store' => 'settlement_cycle', 'type' => 'enum', 'enum' => SettlementCycle::class, 'default' => 'MONTHLY', 'rules' => ['settlement_cycle' => ['required', 'in:WEEKLY,BIWEEKLY,MONTHLY']]],
                ['key' => 'min_payout', 'label' => 'Minimal Payout (Rp)', 'store' => 'min_payout', 'type' => 'money', 'rules' => ['min_payout' => ['nullable', 'integer', 'min:0']]],
                ['key' => 'discount_policy', 'label' => 'Diskon', 'store' => 'discount_policy', 'type' => 'enum', 'enum' => DiscountPolicy::class, 'default' => 'STORE_BEARS', 'rules' => ['discount_policy' => ['in:STORE_BEARS,SHARED']]],
                ['key' => 'loss_liability', 'label' => 'Risiko Kehilangan', 'store' => 'loss_liability', 'type' => 'enum', 'enum' => LossLiability::class, 'default' => 'STORE', 'rules' => ['loss_liability' => ['in:STORE,CONSIGNOR,SHARED']]],
                ['key' => 'bank_name', 'label' => 'Bank', 'store' => 'bank_name', 'type' => 'string', 'rules' => ['bank_name' => ['nullable', 'max:191']]],
                ['key' => 'bank_account', 'label' => 'No. Rekening', 'store' => 'bank_account', 'type' => 'string', 'rules' => ['bank_account' => ['nullable', 'max:191']]],
                ['key' => 'bank_holder', 'label' => 'Atas Nama', 'store' => 'bank_holder', 'type' => 'string', 'rules' => ['bank_holder' => ['nullable', 'max:191']]],
                ['key' => 'status', 'label' => 'Status (ACTIVE/SUSPENDED/ARCHIVED)', 'store' => 'status', 'type' => 'enum', 'enum' => ConsignorStatus::class, 'default' => 'ACTIVE', 'rules' => ['status' => ['in:ACTIVE,SUSPENDED,ARCHIVED']]],
            ],
        ];
    }

    private static function katalog(): array
    {
        return [
            'module' => 'katalog',
            'title' => 'Import Katalog Produk',
            'subtitle' => 'Nama produk dapat berasal dari 1 sampai 2 kolom (digabung dengan spasi).',
            'items' => [
                ['key' => 'name:p1', 'label' => 'Nama Produk — Kolom 1', 'store' => 'name', 'combineOrder' => 1, 'type' => 'string', 'required' => true, 'rules' => ['name' => ['required', 'max:255']]],
                ['key' => 'name:p2', 'label' => 'Nama Produk — Kolom 2 (opsional)', 'store' => 'name', 'combineOrder' => 2, 'type' => 'string'],
                ['key' => 'series_id', 'label' => 'Seri (nama atau ID)', 'store' => 'series_id', 'type' => 'lookup', 'lookup' => 'series', 'rules' => ['series_id' => ['nullable', 'integer', 'exists:product_series,id']]],
                ['key' => 'casting_code', 'label' => 'Kode Casting', 'store' => 'casting_code', 'type' => 'string', 'rules' => ['casting_code' => ['nullable', 'max:255']]],
                ['key' => 'year', 'label' => 'Tahun', 'store' => 'year', 'type' => 'integer', 'rules' => ['year' => ['nullable', 'integer', 'between:1900,2100']]],
                ['key' => 'color', 'label' => 'Warna', 'store' => 'color', 'type' => 'string', 'rules' => ['color' => ['nullable', 'max:191']]],
                ['key' => 'packaging_type', 'label' => 'Kemasan (CARDED/BOXED/LOOSE)', 'store' => 'packaging_type', 'type' => 'enum', 'enum' => PackagingType::class, 'default' => 'CARDED', 'rules' => ['packaging_type' => ['in:CARDED,BOXED,LOOSE']]],
                ['key' => 'card_condition', 'label' => 'Kondisi Kardus', 'store' => 'card_condition', 'type' => 'enum', 'enum' => CardCondition::class, 'default' => 'MINT', 'rules' => ['card_condition' => ['in:'.implode(',', Enums::values(CardCondition::class))]]],
                ['key' => 'blister_condition', 'label' => 'Kondisi Blister', 'store' => 'blister_condition', 'type' => 'enum', 'enum' => BlisterCondition::class, 'default' => 'CLEAR', 'rules' => ['blister_condition' => ['in:'.implode(',', Enums::values(BlisterCondition::class))]]],
                ['key' => 'factory_barcode_ref', 'label' => 'Ref Barcode Pabrik', 'store' => 'factory_barcode_ref', 'type' => 'string', 'rules' => ['factory_barcode_ref' => ['nullable', 'max:30']]],
                ['key' => 'default_list_price', 'label' => 'Harga Jual (Rp)', 'store' => 'default_list_price', 'type' => 'money', 'required' => true, 'rules' => ['default_list_price' => ['required', 'integer', 'min:0']]],
                ['key' => 'tags', 'label' => 'Tags (pisahkan ;)', 'store' => 'tags', 'type' => 'tags', 'rules' => ['tags' => ['nullable', 'array', 'max:10']]],
                ['key' => 'status', 'label' => 'Status (ACTIVE/INACTIVE)', 'store' => 'status', 'type' => 'enum', 'enum' => ProductStatus::class, 'default' => 'ACTIVE', 'rules' => ['status' => ['in:ACTIVE,INACTIVE']]],
            ],
        ];
    }

    private static function rak(): array
    {
        return [
            'module' => 'rak',
            'title' => 'Import Lokasi Rak',
            'subtitle' => 'Kode rak otomatis diubah huruf kapital, contoh: A-S1-L1.',
            'items' => [
                ['key' => 'code', 'label' => 'Kode Rak', 'store' => 'code', 'type' => 'string', 'required' => true, 'rules' => ['code' => ['required', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]*$/', 'unique:racks,code']]],
                ['key' => 'zone', 'label' => 'Zona', 'store' => 'zone', 'type' => 'string', 'rules' => ['zone' => ['nullable', 'max:10']]],
                ['key' => 'type', 'label' => 'Tipe (DISPLAY/STORAGE/QUARANTINE/RTV_STAGING)', 'store' => 'type', 'type' => 'enum', 'enum' => RackType::class, 'default' => 'DISPLAY', 'rules' => ['type' => ['in:'.implode(',', Enums::values(RackType::class))]]],
                ['key' => 'capacity', 'label' => 'Kapasitas (item)', 'store' => 'capacity', 'type' => 'integer', 'rules' => ['capacity' => ['nullable', 'integer', 'min:0']]],
                ['key' => 'is_active', 'label' => 'Aktif (YA/TIDAK)', 'store' => 'is_active', 'type' => 'boolean', 'default' => true, 'rules' => ['is_active' => ['boolean']]],
            ],
        ];
    }

    private static function pengguna(): array
    {
        return [
            'module' => 'pengguna',
            'title' => 'Import Pengguna & Role',
            'subtitle' => 'Username unik. PIN opsional 6 digit. Password wajib diisi.',
            'items' => [
                ['key' => 'name', 'label' => 'Nama Lengkap', 'store' => 'name', 'type' => 'string', 'required' => true, 'rules' => ['name' => ['required', 'max:255']]],
                ['key' => 'username', 'label' => 'Username', 'store' => 'username', 'type' => 'string', 'required' => true, 'rules' => ['username' => ['required', 'max:255', 'regex:/^[A-Za-z0-9._-]+$/']]],
                ['key' => 'email', 'label' => 'Email', 'store' => 'email', 'type' => 'string', 'rules' => ['email' => ['nullable', 'email', 'max:255']]],
                ['key' => 'role', 'label' => 'Role (OWNER/STAFF)', 'store' => 'role', 'type' => 'enum', 'enum' => Role::class, 'default' => 'STAFF', 'rules' => ['role' => ['required', 'in:OWNER,STAFF']]],
                ['key' => 'is_active', 'label' => 'Aktif (YA/TIDAK)', 'store' => 'is_active', 'type' => 'boolean', 'default' => true, 'rules' => ['is_active' => ['boolean']]],
                ['key' => 'pin', 'label' => 'PIN (6 digit, opsional)', 'store' => 'pin', 'type' => 'string', 'rules' => ['pin' => ['nullable', 'digits:6']]],
                ['key' => 'password', 'label' => 'Password Awal', 'store' => 'password', 'type' => 'string', 'required' => true, 'rules' => ['password' => ['required', 'string', 'min:8']]],
            ],
        ];
    }
}
