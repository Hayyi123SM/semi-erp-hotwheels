<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Models\Rack;
use App\Models\StockLot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Pindahkan banyak lot sekaligus ke satu rak (FR-IC-04, jalur massal).
 *
 * Rak tujuan memakai aturan yang sama persis dengan `PindahRakRequest`,
 * karena tetap satu rak tujuan untuk seluruh kirim. Yang berbeda hanya
 * sumber lot-nya: berkas satu baris digantikan daftar id, dan daftar itu
 * dibatasi -- pemindahan massal berjalan dalam satu transaksi, jadi
 * batasnya menjaga agar satu kirim tetap berada di skala yang wajar
 * ditinjau orang lewat toast hasilnya.
 *
 * Lot yang sudah berada di rak tujuan atau stoknya kosong sengaja TIDAK
 * divalidasi di sini: pemeriksaan itu urusan service, dan keputusannya
 * "lewati, jangan gagalkan" -- sehingga memilih 50 baris, 3 di antaranya
 * sudah di rak tujuan, tetap memindahkan 47 sisanya.
 */
class PindahRakBulkRequest extends FormRequest
{
    /**
     * Jumlah lot maksimal dalam satu kali kirim.
     *
     * Lima halaman tabel penuh pada ukuran terbesar (100 baris/halaman);
     * angka ini pagar transaksi, bukan batas bisnis -- memindahkan lebih
     * banyak dari itu berarti memecahnya menjadi beberapa kirim.
     */
    public const int MAX_LOTS = 500;

    public function rules(): array
    {
        return [
            'lot_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_LOTS],
            'lot_ids.*' => ['integer', 'distinct', Rule::exists('stock_lots', 'id')],
            'rack_id' => [
                'required',
                'integer',
                Rule::exists('racks', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'reason' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'lot_ids.required' => 'Pilih dulu lot yang mau dipindahkan.',
            'lot_ids.min' => 'Pilih dulu lot yang mau dipindahkan.',
            'lot_ids.max' => 'Satu kali pindah maksimal '.self::MAX_LOTS.' lot. Pecah menjadi beberapa kirim.',
            'lot_ids.*.integer' => 'Ada pilihan lot yang tidak berbentuk id.',
            'lot_ids.*.distinct' => 'Ada lot yang terpilih dua kali.',
            'lot_ids.*.exists' => 'Sebagian lot terpilih sudah tidak ada di database. Muat ulang halaman lalu pilih lagi.',
            'rack_id.required' => 'Pilih rak tujuan dulu.',
            'rack_id.exists' => 'Rak tujuan tidak ditemukan atau sedang nonaktif.',
            'reason.max' => 'Alasan pemindahan maksimal 200 karakter.',
        ];
    }

    /**
     * Lot terpilih, diurutkan id-nya supaya urutan pemrosesan tidak
     * bergantung pada urutan checkbox di layar.
     *
     * @return Collection<int, StockLot>
     */
    public function lots(): Collection
    {
        return StockLot::query()
            ->whereKey($this->input('lot_ids'))
            ->orderBy('id')
            ->get();
    }

    /**
     * Rak tujuan, setelah divalidasi.
     */
    public function rack(): Rack
    {
        return Rack::query()->findOrFail((int) $this->input('rack_id'));
    }

    /**
     * Alasan opsional, dinormalkan: spasi murni berarti tidak ada alasan,
     * supaya kolom `reason` pada tiap gerakan tidak terisi butir kosong.
     *
     * Satu alasan untuk seluruh kirim, tercatat sama pada setiap lot --
     * pemindahan massal adalah satu keputusan, bukan keputusan per baris.
     */
    public function reason(): ?string
    {
        $reason = trim((string) $this->input('reason', ''));

        return $reason === '' ? null : $reason;
    }
}
