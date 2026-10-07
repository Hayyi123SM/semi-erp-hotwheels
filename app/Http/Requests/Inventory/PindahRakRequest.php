<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use App\Models\Rack;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pindahkan satu lot ke rak lain (FR-IC-04).
 *
 * Rak nonaktif ditolak di sini, bukan hanya di service: pengguna layar harus
 * mendapat jawaban pada form yang sedang ia isi, bukan lemparan galat dari
 * lapis yang tidak terlihat. Service tetap memeriksa hal yang sama -- di sana
 * pemeriksaannya terhadap baris yang dibaca ulang di dalam transaksi, yang
 * adalah satu-satunya pemeriksaan yang benar-benar menjamin.
 */
class PindahRakRequest extends FormRequest
{
    public function rules(): array
    {
        return [
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
            'rack_id.required' => 'Pilih rak tujuan dulu.',
            'rack_id.exists' => 'Rak tujuan tidak ditemukan atau sedang nonaktif.',
            'reason.max' => 'Alasan pemindahan maksimal 200 karakter.',
        ];
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
     * supaya kolom `reason` di gerakan stok tidak terisi butir kosong yang
     * terlihat seperti keterangan.
     */
    public function reason(): ?string
    {
        $reason = trim((string) $this->input('reason', ''));

        return $reason === '' ? null : $reason;
    }
}
