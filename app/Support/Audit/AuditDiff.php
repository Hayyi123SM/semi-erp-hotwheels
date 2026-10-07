<?php

namespace App\Support\Audit;

/**
 * Membaca pasangan `before`/`after` menjadi daftar perubahan yang bisa ditampilkan.
 *
 * Pemikiran di sini adalah bahwa kedua sisi tidak pernah berjanji simetris.
 * Bentuk nyata di database:
 *
 * - `before` kosong, `after` penuh. Baris pembuatan: `CREATED` produk menyimpan
 *   seluruh isi baris baru sebagai `after` dan `null` sebagai `before`.
 * - `before` dan `after` terisi, kuncinya sama. Perpindahan status label.
 * - `before` terisi, `after` kosong. Encrypted field yang dihapus.
 * - Kuncinya berbeda sebagian. Misalnya `qr_side_cm` dihapus sementara
 *   `default_template` berubah.
 *
 * Diff dihitung dari gabungan kunci kedua sisi, lalu disaring ke field yang
 * benar-benar berubah. Kalau tidak disaring, `CREATED` produk akan menampilkan
 * tujuh belas baris "sebelum: tidak ada", yang menutupi satu-satunya perubahan
 * yang benar-benar terjadi.
 */
final class AuditDiff
{
    /**
     * @return list<AuditFieldChange>
     */
    public static function between(?array $before, ?array $after): array
    {
        $before ??= [];
        $after ??= [];

        $fields = array_values(array_unique([
            ...array_keys($before),
            ...array_keys($after),
        ]));

        sort($fields);

        $changes = [];

        foreach ($fields as $field) {
            $change = new AuditFieldChange($field, $before[$field] ?? null, $after[$field] ?? null);

            if ($change->changed()) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    /**
     * Ringkasan satu baris untuk kolom daftar.
     *
     * Hanya field pertama yang ditampilkan. Daftar log dibaca sambil berdiri di
     * depan printer, jadi "3 field berubah" lebih berguna daripada tiga baris
     * yang harus digulir; sisanya ada di panel yang dibuka per baris.
     *
     * @param  list<AuditFieldChange>  $changes
     */
    public static function summary(array $changes): string
    {
        if ($changes === []) {
            return 'Tidak ada perubahan field';
        }

        $first = $changes[0];

        if (count($changes) === 1) {
            return $first->field.': '.$first->display($first->before).' -> '.$first->display($first->after);
        }

        return $first->field.': '.$first->display($first->before).' -> '.$first->display($first->after)
            .' (+'.(count($changes) - 1).' perubahan)';
    }
}
