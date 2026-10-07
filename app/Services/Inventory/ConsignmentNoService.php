<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Consignment;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Nomor dokumen consignment dengan pola CI-YYYYMMDD-NNNN.
 *
 * Nomor tidak disimpan di kolom urutan tersendiri: ia diturunkan dari baris
 * terakhir pada hari yang sama, sehingga dua dokumen hari berbeda tidak pernah
 * bentrok, dan retry alami menaikkan nomor setelah dokumen sebelumnya disimpan.
 * Constraint UNIQUE pada `consignments.doc_no` menjadi jaring pengaman terakhir
 * bila dua commit bersamaan menebak nomor yang sama.
 */
final class ConsignmentNoService
{
    public function next(?DateTimeInterface $date = null): string
    {
        $date ??= now();

        $prefix = 'CI-'.$date->format('Ymd').'-';

        $last = Consignment::query()
            ->where('doc_no', 'like', $prefix.'%')
            ->latest('id')
            ->value('doc_no');

        $sequence = $last === null ? 1 : ((int) Str::afterLast((string) $last, '-')) + 1;

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
