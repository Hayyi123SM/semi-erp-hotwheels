<?php

declare(strict_types=1);

namespace App\Http\Requests\Inbound;

use App\Enums\BlisterCondition;
use App\Enums\CardCondition;
use App\Services\Spreadsheet\SpreadsheetService;
use App\Support\Enums;
use App\Support\Numbers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Auto-save draft daftar produk Stock In Pribadi.
 *
 * Aturannya jauh lebih longgar daripada `StoreStockInPribadiRequest`: draft ada
 * justru supaya form boleh dalam keadaan setengah jadi, dan kunci commit sudah
 * dipegang request commit. Menolak draft setengah isi berarti form dipaksa
 * memilih antara kehilangan pekerjaan atau mengarang nilai supaya lolos.
 *
 * Yang tetap dijaga sama dengan `SaveConsignmentDraftRequest`: `exists` untuk
 * produk dan rak, serta rentang qty dan HPP. Jadi draft tidak bisa dipakai
 * untuk menyelundupkan SKU fiktif yang baru ketahuan saat form di-commit.
 *
 * HPP dinormalisasi seperti `StoreStockInPribadiRequest` -- `1.200` yang
 * diketik pembeli adalah `1200` -- supaya nilai yang tersimpan sama persis
 * dengan yang akan diterima server saat commit.
 */
class SaveStockInPribadiDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->input('items');

        // Bukan array dibiarkan apa adanya, supaya rule `array` yang menolaknya.
        // Menggantinya dengan `[]` di sini akan membuat payload yang rusak
        // diterima seolah pengguna memang tidak mengirim baris apa pun.
        if (! is_array($input)) {
            return;
        }

        $items = [];

        foreach ($input as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $row['qty'] = Numbers::integer($row['qty'] ?? null);
            $row['cost_price'] = Numbers::integer($row['cost_price'] ?? null);

            $items[$index] = $row;
        }

        $this->merge(['items' => $items]);
    }

    public function rules(): array
    {
        return [
            // `nullable`, bukan `required`: daftar kosong adalah permintaan yang
            // sah, dan maknanya adalah pembatalan -- persis seperti draft kasir.
            //
            // Batas barisnya sengaja diambil dari `SpreadsheetService::MAX_ROWS`
            // (2000): draft memegang daftar yang sama dengan yang bisa dihasilkan
            // impor. Kalau batasnya berbeda, impor 2000 baris lulus sementara
            // auto-save dan commit form menolak -- pekerjaan hilang diam-diam.
            'items' => ['nullable', 'array', 'max:'.SpreadsheetService::MAX_ROWS],

            'items.*.id' => ['nullable', 'string', 'max:64'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.casting_code' => ['nullable', 'string', 'max:100'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.series_code' => ['nullable', 'string', 'max:100'],

            // Metadata tampilan dari picker/barcode, ikut tersimpan di draft
            // supaya badge seri, warna, dan tahun tetap terbawa lintas perangkat.
            // Semua nullable: payload lama (atau picker kasir lama) tidak
            // memuatnya dan tidak boleh ditolak karenanya.
            'items.*.series_name' => ['nullable', 'string', 'max:100'],
            'items.*.color' => ['nullable', 'string', 'max:100'],
            'items.*.year' => ['nullable', 'integer'],

            'items.*.qty' => ['nullable', 'integer', 'between:1,999'],
            'items.*.cost_price' => ['nullable', 'integer', 'between:0,4294967295'],
            'items.*.rack_id' => ['nullable', 'integer', 'exists:racks,id'],
            'items.*.card_condition' => ['nullable', Rule::in(Enums::values(CardCondition::class))],
            'items.*.blister_condition' => ['nullable', Rule::in(Enums::values(BlisterCondition::class))],
        ];
    }
}
