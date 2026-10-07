<?php

declare(strict_types=1);

namespace App\Services\Label;

/**
 * Kode rak dalam bentuk yang benar-benar keluar dari printer.
 *
 * Prefiks `RK:` ditambahkan di satu tempat ini, bukan di renderer dan bukan
 * di pemanggil. Kalau tiap pihak menambahkan sendiri, cepat atau lambat ada
 * jalur yang membuat label rak tanpa prefiks dan label itu ikut tertukar dengan
 * SKU saat scan (§1.4.2, BR-10).
 *
 * Dipisah dari `HtmlLabelRenderer` juga supaya validasi panjang kode menghitung
 * string yang sama persis dengan yang dirender. Kalau validasi menghitung
 * `A-01-03` sementara printer mencetak `RK:A-01-03`, batas yang dijaga meleset
 * tiga karakter.
 */
final readonly class RackCode
{
    public const PREFIX = 'RK:';

    public static function printable(string $code): string
    {
        $code = trim($code);

        return str_starts_with($code, self::PREFIX) ? $code : self::PREFIX.$code;
    }
}
