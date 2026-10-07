<?php

namespace App\Support\Audit;

use App\Support\Format;

/**
 * Satu perubahan field, dibaca dari pasangan `before` dan `after`.
 *
 * Laporan audit menampilkan sebelum dan sesudah berdampingan, jadi bentuk
 * pertanyaannya selalu sama: field apa, dari nilai apa, ke nilai apa.
 * `AuditDiff` menjawabnya supaya tidak ada satu pun tempat di laporan yang
 * harus tahu bahwa `before` bisa berupa array, bisa `null`, dan kunci-kuncinya
 * belum tentu sama.
 */
final readonly class AuditFieldChange
{
    public function __construct(
        public string $field,
        public mixed $before,
        public mixed $after,
    ) {}

    /**
     * Apakah field ini benar-benar berpindah.
     *
     * Pembandingannya longgar untuk nilai tunggal, dengan sengaja. `before` dan
     * `after` datang dari JSON, jadi angka yang ditulis sebagai `1` di satu sisi
     * bisa kembali sebagai `"1"` di sisi lain tergantung mesin dan kolomnya.
     * Perbandingan ketat akan melaporkan perubahan palsu -- dan di halaman
     * laporan audit, perubahan palsu yang dipertanyakan lebih merusak
     * kepercayaan daripada perubahan yang luput sebentar.
     *
     * Yang tersusun di dalam array tetap dibandingkan persis. Longgar di sana
     * berarti harus menebak field mana yang angka dan mana yang teks, dan
     * tebukan itu salah begitu nama fieldnya berubah. Bentuk yang benar-benar
     * tersimpan di `before` dan `after` adalah bagian dari bukti, jadi ia
     * ditampilkan apa adanya.
     */
    public function changed(): bool
    {
        if ($this->before === null || $this->before === '' || $this->before === []) {
            return $this->after !== null && $this->after !== '' && $this->after !== [];
        }

        if ($this->after === null || $this->after === '' || $this->after === []) {
            return true;
        }

        if (is_scalar($this->before) && is_scalar($this->after)) {
            return (string) $this->before !== (string) $this->after;
        }

        return json_encode($this->before) !== json_encode($this->after);
    }

    /**
     * Nilai siap tampil untuk satu sisi.
     *
     * Nilai kosong jadi `Format::EMPTY` supaya kolom kosong di diff dibaca sebagai
     * "tidak ada nilai sebelumnya", bukan sebagai sel yang lupa diisi.
     */
    public function display(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return Format::EMPTY;
        }

        if (is_bool($value)) {
            return $value ? 'Ya' : 'Tidak';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
