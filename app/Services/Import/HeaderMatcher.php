<?php

namespace App\Services\Import;

use App\Support\ImportSchemas;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Menerka kolom mana pada berkas yang jadi kolom mana pada sistem.
 *
 * Halaman pemetaan dibangun dengan asumsi file orang sudah memakai nama kolom
 * yang sama dengan sistem. Kenyataannya orang datang dengan "Harga",
 * "Harga Jual", "Harga Jual (Rp)", dan "harga jual" untuk field yang sama --
 * jadi dropdown pemetaanstarting kosong memaksa orang membaca 13 baris dan
 * memilih satu per satu, padahal jawabannya sudah ada di depan mata.
 *
 * Kelas ini menebak. Hasilnya SELALU bisa diubah di UI, karena tebakan yang
 * salah lebih buruk daripada dropdown kosong: tebakan yang salah terlihat
 * meyakinkan, sedangkan dropdown kosong minimal jujur soal tidak tahu.
 *
 * Dua aturan yang dijaga supaya tebakan tidak merusak file:
 *
 * 1. Satu kolom berkas hanya boleh jadi satu field. Kalau dua field competes
 *    untuk header yang sama, yang skornya lebih tinggi menang dan yang kalah
 *    dibiarkan kosong -- bukan keduanya dipetakan ke kolom yang sama, yang
 *    berarti satu nilai ditulis ke dua atribut.
 * 2. Skor dihitung di semua pasangan (field, kolom) dulu, baruXOleh yang
 *    terbaik diambil satu per satu. Kalau field yang diurutkan lebih dulu
 *    langsung mengambil kolom yang sebenarnya lebih cocok untuk field lain,
 *    pemetaannya salah dan tidak ada yang memperbaikinya.
 */
class HeaderMatcher
{
    /** Header sama persis dengan nama field, setelah dibersihkan. */
    private const EXACT = 100;

    /** Salah satu nama field memuat yang lain, utuh sebagai satu frasa. */
    private const CONTAINS = 80;

    /**
     * Saran pemetaan: kunci item skema => huruf kolom.
     *
     * @param  array<int, mixed>  $header  Baris judul kolom dari berkas.
     * @return array<string, string>
     */
    public function suggest(string $module, array $header): array
    {
        $schema = ImportSchemas::for($module);

        $pairs = [];
        foreach ($schema['items'] as $order => $item) {
            foreach ($header as $index => $cell) {
                $score = $this->score($cell, $item);

                if ($score > 0) {
                    $pairs[] = [
                        'key' => $item['key'],
                        'store' => (string) ($item['store'] ?? $item['key']),
                        'index' => (int) $index,
                        'text' => $this->normalize((string) $cell),
                        'score' => $score,
                        'order' => $order,
                    ];
                }
            }
        }

        // Skor dulu, baru urutan item sebagai pemutus seri: dua field yang
        // sama-sama cocok untuk satu kolom harus memilih yang menang dengan
        // aturan, bukan dengan urutan kebetulan.
        usort($pairs, fn (array $a, array $b) => ($b['score'] <=> $a['score']) ?: ($a['order'] <=> $b['order']));

        $suggestions = [];
        $claimed = [];

        foreach ($pairs as $pair) {
            // Kolom yang sudah diambil dan field yang sudah punya kolom
            // dilewati, bukan ditimpa.
            if (isset($claimed[$pair['index']]) || isset($suggestions[$pair['key']])) {
                continue;
            }

            // Katalog punya dua item yang mengisi satu atribut: "Nama Produk
            // -- Kolom 1" dan "-- Kolom 2". Kalau keduanya memakai kolom
            // dengan judul sama -- dan berkas orang memang sering punya dua
            // kolom "Nama Produk" -- hasilnya bukan dua bagian nama yang
            // digabung, tapi satu nama yang tertulis dua kali. Yang kedua
            // lebih baik dikosongkan daripada diisi dengan pengulangan.
            foreach ($claimed as $taken) {
                if ($taken['store'] === $pair['store'] && $taken['text'] === $pair['text']) {
                    continue 2;
                }
            }

            $claimed[$pair['index']] = $pair;
            $suggestions[$pair['key']] = Coordinate::stringFromColumnIndex($pair['index'] + 1);
        }

        return $suggestions;
    }

    /**
     * @param  mixed  $cell
     * @param  array<string, mixed>  $item
     */
    private function score($cell, array $item): int
    {
        $header = $this->normalize((string) $cell);

        if ($header === '') {
            return 0;
        }

        $best = 0;

        foreach ($this->candidates($item) as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if ($header === $candidate) {
                return self::EXACT;
            }

            if ($this->holds($candidate, $header) || $this->holds($header, $candidate)) {
                $best = max($best, self::CONTAINS);
            }
        }

        return $best;
    }

    /**
     * Nama-nama yang boleh dianggap sebagai milik sebuah field.
     *
     * Label apa adanya dipakai lebih dulu karena itu yang ditulis di template,
     * jadi file dari template kita sendiri akan cocok persis. Varian tanpa
     * keterangan dalam kurung dan tanpa nomor kolom dipakai setelahnya, supaya
     * "Harga Jual (Rp)" di template tetap dikenali oleh file yang heading-nya
     * cuma "Harga Jual".
     *
     * @param  array<string, mixed>  $item
     * @return string[]
     */
    private function candidates(array $item): array
    {
        $label = (string) ($item['label'] ?? '');
        $bare = $this->stripNotes($label);

        $candidates = [
            $this->normalize($label),
            $this->normalize($bare),
            $this->normalize($this->stripParentheticals($label)),
            // Kunci dan atribut dipakai sebagai jaring pengaman terakhir:
            // "kode_casting" di berkas akan dikenali meski labelnya jauh.
            $this->normalize((string) ($item['key'] ?? '')),
            $this->normalize((string) ($item['store'] ?? $item['key'] ?? '')),
        ];

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Buang keteranganrottlingan di ujung label -- "Kolom 1", "Kolom 2".
     *
     * Field nama produk punya dua item ("Kolom 1" dan "Kolom 2") yang sama
     * sama menyimpan ke satu atribut. Kalau nomor ini ikut dipertahankan,
     * keduanya jadi nama berbeda dan hanya yang pertama yang dikenali.
     *
     * @param  array<string, mixed>  $item
     */
    private function stripNotes(string $label): string
    {
        return (string) preg_replace('/\s*[-–—(]?\s*kolom\s*\d+\s*\)?\s*$/iu', '', $label);
    }

    private function stripParentheticals(string $label): string
    {
        return (string) preg_replace('/\([^)]*\)/u', ' ', $label);
    }

    /**
     * Apakah satu frasa memuat frasa lain utuh, bukan sebagai potongan kata.
     *
     * Tanpa batas kata, "kode" ikut cocok dengan "menadekode"; dan field
     * "Seri" akan mengambil header "Seri Produk" yang sebenarnya milik kolom
     * lain.
     */
    private function holds(string $haystack, string $needle): bool
    {
        return (bool) preg_match('/\b'.preg_quote($needle, '/').'\b/u', $haystack);
    }

    private function normalize(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
