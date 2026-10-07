<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Opname;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Nomor sesi opname dengan pola OPN-YYYYMMDD-NNN.
 *
 * Pola yang sama dengan nomor dokumen consignment (`ConsignmentNoService`):
 * nomor diturunkan dari baris terakhir pada hari yang sama, bukan dari kolom
 * urutan tersendiri, sehingga dua sesi di hari berbeda tidak pernah bentrok dan
 * retry alami menaikkan nomor setelah sesi sebelumnya tersimpan. Constraint
 * UNIQUE pada `opnames.opname_no` menjadi jaring pengaman terakhir.
 *
 * Tiga digit, bukan empat: sesi opname adalah kejadian harian yang jarang
 * melebihi beberapa belas, dan nomor yang lebih panjang dari isi rentangnya
 * hanya membuat baris di riwayat lebih sulit dibaca.
 */
final class OpnameNoService
{
    public function next(?DateTimeInterface $date = null): string
    {
        $date ??= now();

        $prefix = 'OPN-'.$date->format('Ymd').'-';

        $last = Opname::query()
            ->where('opname_no', 'like', $prefix.'%')
            ->latest('id')
            ->value('opname_no');

        $sequence = $last === null ? 1 : ((int) Str::afterLast((string) $last, '-')) + 1;

        return $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }
}
