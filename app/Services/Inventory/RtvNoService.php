<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\RtvNote;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Nomor dokumen retur dengan pola RTV-YYYYMMDD-NNN.
 *
 * Pola yang sama dengan nomor sesi opname (`OpnameNoService`) dan nomor
 * dokumen consignment (`ConsignmentNoService`): nomor diturunkan dari baris
 * terakhir pada hari yang sama, bukan dari kolom urutan tersendiri, sehingga
 * dua dokumen di hari berbeda tidak pernah bentrok dan retry alami menaikkan
 * nomor setelah dokumen sebelumnya tersimpan. Constraint UNIQUE pada
 * `rtv_notes.rtv_no` menjadi jaring pengaman terakhir.
 *
 * Nomor diberikan saat dokumen dibuat, bukan saat eksekusi: sejak baris
 * pertama tersimpan, dokumen itu adalah sesuatu yang bisa dirujuk orang -- di
 * rak staging, di catatan penitip -- dan rujukan tidak boleh berubah hanya
 * karena eksekusinya mundur.
 */
final class RtvNoService
{
    public function next(?DateTimeInterface $date = null): string
    {
        $date ??= now();

        $prefix = 'RTV-'.$date->format('Ymd').'-';

        $last = RtvNote::query()
            ->where('rtv_no', 'like', $prefix.'%')
            ->latest('id')
            ->value('rtv_no');

        $sequence = $last === null ? 1 : ((int) Str::afterLast((string) $last, '-')) + 1;

        return $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }
}
