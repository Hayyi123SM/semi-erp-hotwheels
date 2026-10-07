<?php

declare(strict_types=1);

namespace App\Services\Master;

use App\Support\WhatsappNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Menyamakan `consignors.wa_number` ke satu bentuk, untuk data yang sudah ada.
 *
 * Mutator `Consignor::$wa_number` sudah menormalkan setiap penulisan baru, jadi
 * yang tersisa hanyalah baris lama yang masih menyimpan bentuk yang diketik
 * manusia. Sisanya tidak boleh dibiarkan: unique index membandingkan nilai
 * mentah, sehingga `0812-3456-7890` dan `+6281234567890` terbaca sebagai dua
 * penitip berbeda -- padahal setelah keduanya dinormalkan, keduanya sama, dan
 * penulisan kedua berakhir sebagai 500.
 *
 * Logikanya hidup di sini, bukan di dalam migration, karena migration tidak
 * bisa diuji: `RefreshDatabase` sudah menjalankannya sebelum baris uji ada, jadi
 * `artisan migrate` di dalam test tidak melakukan apa-apa. Kelas ini bisa
 * dipanggil langsung, jadi tabrakan legacy bisa dibuktikan dengan test dan bukan
 * hanya diasumsikan benar.
 *
 * Urutan langkahnya penting. Pemenang tabrakan dikosongkan lebih dulu, baru
 * sisanya ditulis dalam bentuk polos. Kalau sebaliknya, penulisan kedua sudah
 * lebih dulu melanggar unique index di tengah proses.
 */
class ConsignorWhatsappNormalizer
{
    public function run(): NormalizeResult
    {
        $rows = DB::table('consignors')
            ->select(['id', 'wa_number', 'wa_opt_in_at', 'created_at'])
            ->whereNotNull('wa_number')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return new NormalizeResult;
        }

        $normalized = [];

        foreach ($rows as $row) {
            $normalized[$row->id] = WhatsappNumber::normalize($row->wa_number);
        }

        $emptied = $this->emptyTheLosers($rows, $normalized);
        $rewritten = $this->rewriteTheRest($rows, $normalized, $emptied);

        $this->report($emptied, $rewritten);

        return new NormalizeResult($rewritten, $emptied);
    }

    /**
     * Kosongkan nomor yang kalah tabrakan, dan kembalikan apa yang ada di sana.
     *
     * Dikosongkan, bukan dihapus: `wa_number` nullable dan seluruh data
     * penitipnya tetap utuh, jadi Owner bisa mengisinya lagi lewat form.
     *
     * @param  Collection<int, object>  $rows
     * @param  array<int, string|null>  $normalized
     * @return array<int, string|null> id yang dikosongkan => nilai lamanya
     */
    private function emptyTheLosers(Collection $rows, array $normalized): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $target = $normalized[$row->id];

            if ($target === null) {
                continue;
            }

            $groups[$target][] = $row;
        }

        $emptied = [];

        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }

            usort($group, $this->whoDeservesItFirst(...));

            // Yang pertama menang; sisanya kehilangan nomor.
            foreach (array_slice($group, 1) as $loser) {
                $emptied[$loser->id] = $loser->wa_number;
                DB::table('consignors')->where('id', $loser->id)->update(['wa_number' => null]);
            }
        }

        return $emptied;
    }

    /**
     * Who deserves the number first, when two rows want the same one.
     *
     * Yang punya `wa_opt_in_at` didahulukan, karena hanya mereka yang punya
     * persetujuan tercatat untuk dihubungi. Tanpa opt-in, yang lebih lama
     * menang: nomor yang lebih dulu diklaim lebih mungkin memang milik orang
     * itu sejak awal daripada salah ketik belakangan.
     */
    private function whoDeservesItFirst(object $a, object $b): int
    {
        $aOptIn = $a->wa_opt_in_at === null ? 1 : 0;
        $bOptIn = $b->wa_opt_in_at === null ? 1 : 0;

        if ($aOptIn !== $bOptIn) {
            return $aOptIn <=> $bOptIn;
        }

        $aCreated = (string) ($a->created_at ?? '');
        $bCreated = (string) ($b->created_at ?? '');

        if ($aCreated !== $bCreated) {
            return $aCreated <=> $bCreated;
        }

        // Id sebagai pemutus terakhir supaya hasilnya selalu sama untuk input
        // yang sama, walau `created_at` ikut identik.
        return $a->id <=> $b->id;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @param  array<int, string|null>  $normalized
     * @param  array<int, string|null>  $emptied
     */
    private function rewriteTheRest(Collection $rows, array $normalized, array $emptied): int
    {
        $rewritten = 0;

        foreach ($rows as $row) {
            if (array_key_exists($row->id, $emptied)) {
                continue;
            }

            $target = $normalized[$row->id];

            if ($target === null || $target === $row->wa_number) {
                continue;
            }

            DB::table('consignors')->where('id', $row->id)->update(['wa_number' => $target]);
            $rewritten++;
        }

        return $rewritten;
    }

    /**
     * @param  array<int, string|null>  $emptied
     */
    private function report(array $emptied, int $rewritten): void
    {
        if ($emptied === [] && $rewritten === 0) {
            return;
        }

        // `Migration` tidak punya facility output, jadi hasilnya ditulis ke log.
        // Baris yang dikosongkan harus ada jejaknya: nomor itu hilang dari
        // database, dan tanpa catatan ini tidak ada yang mengetahuinya.
        Log::info('Normalisasi wa_number consignors selesai.', [
            'baris_ditulis' => $rewritten,
            'baris_dikosongkan' => count($emptied),
            'detail_kosong' => $emptied,
        ]);
    }
}
