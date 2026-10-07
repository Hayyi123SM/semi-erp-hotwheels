<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Consignor;
use App\Support\WhatsappNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu nomor WhatsApp milik satu penitip.
 *
 * `Rule::unique('consignors', 'wa_number')` terlihat cukup untuk aturan ini, tapi
 * ia membandingkan nilai yang diketik dengan nilai yang tersimpan apa adanya,
 * sedangkan mutator menyimpan bentuk polos. Tanpa aturan ini, `0812-3456-7890`
 * lolos validasi karena tidak sama byte demi byte dengan `+6281234567890`, lalu
 * dinormalkan oleh mutator, lalu menabrak unique index di database dan berakhir
 * sebagai 500 untuk Staff yang tidak bisa melakukan apa pun selain mengulang.
 *
 * Bandingannya dilakukan di PHP, pada hasil normalisasi kedua sisi, bukan lewat
 * `where`. Alasannya bukan selera: kalau hanya menyamakan bentuk yang sudah
 * dinormalkan, aturan ini kembali rapuh begitu ada satu baris legacy yang belum
 * tersentuh -- persis kondisi yang membuat seluruh mekanisme ini ada. Migration
 * memang membersihkan data lama, tapi menjandalkannya sendirian berarti satu
 * impor atau satu perintah tinker bisa memperburuk keadaan tanpa ada yang
 * menyadarinya, dan gejalanya muncul sebagai 500.
 *
 * Karena itu seluruh kolom `wa_number` diambil, bukan satu baris. Murah di
 * sini: tabel ini berisi data penitip, bukan log transaksi, dan aturan ini
 * dipanggil hanya saat menyimpan master -- bukan di jalur panas. Baris yang
 * `wa_number`-nya kosong dilewati, karena `null` berarti "belum diisi" dan bukan
 * nomor yang sedang ditagih.
 */
class UniqueWhatsappNumber implements ValidationRule
{
    /**
     * Id penitip yang sedang diedit, agar penitip tidak bentrok dengan dirinya
     * sendiri.
     *
     * Bentuknya boleh model, bukan cuma angka: pemanggilnya menyalin route
     * binding apa adanya, dan `Route::route('consignor')` mengembalikan objek
     * Consignor. `whereKeyNot()` sudah menerima model, jadi tidak ada yang perlu
     * diurai di sini.
     */
    public function __construct(private readonly Model|int|string|null $ignore = null) {}

    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $normalized = WhatsappNumber::normalize((string) $value);

        if ($normalized === null) {
            // Bentuknya sudah ditolak aturan format; di sini tidak ada yang
            // bisa dibandingkan, jadi biarkan aturan format yang menyampaikannya.
            return;
        }

        $taken = Consignor::query()
            ->whereNotNull('wa_number')
            ->when($this->ignore !== null, fn ($query) => $query->whereKeyNot($this->ignore))
            ->pluck('wa_number')
            ->contains(fn (string $stored): bool => WhatsappNumber::normalize($stored) === $normalized);

        if ($taken) {
            $fail('Nomor WhatsApp ini sudah dipakai penitip lain.');
        }
    }
}
