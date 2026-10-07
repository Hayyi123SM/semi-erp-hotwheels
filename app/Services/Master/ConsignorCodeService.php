<?php

namespace App\Services\Master;

use Illuminate\Support\Facades\DB;

class ConsignorCodeService
{
    /**
     * Buat kode penitip berikutnya (CN01, CN02, ... CN99, CN100, CN101).
     * Selalu memakai urutan tertinggi + 1 sehingga kode tidak pernah dipakai ulang.
     */
    public function next(): string
    {
        $codes = DB::table('consignors')->pluck('consignor_code');
        $max = $codes
            ->map(fn (string $code) => (int) substr($code, 2))
            ->max() ?? 0;

        $sequence = $max + 1;

        return 'CN'.str_pad((string) $sequence, max(2, strlen((string) $sequence)), '0', STR_PAD_LEFT);
    }
}
