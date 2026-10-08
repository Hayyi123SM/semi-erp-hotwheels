<?php

namespace App\Services\Report;

use Carbon\Carbon;

/**
 * Batas waktu satu laporan, dalam satu tempat.
 *
 * Semua laporan Phase A membaca rentang `from`/`to` pada kolom `sold_at` atau
 * `created_at`. Menebak bentuk "[awal, akhir]" di setiap service akan membuat
 * satu service lupa mengakhiri `to` di jam 23:59:59, dan laporan kemarin baru
 * benar besok pagi. `span()` mengembalikan dua titik waktu yang sudah rapi:
 * awal hari dari `from` (atau 30 hari ke belakang), akhir hari dari `to`
 * (atau sekarang).
 */
final class ReportPeriod
{
    /**
     * @return array{Carbon, Carbon}
     */
    public static function span(?string $from, ?string $to): array
    {
        $endDate = ($to !== null && $to !== '') ? Carbon::parse($to) : now();
        $startDate = ($from !== null && $from !== '') ? Carbon::parse($from) : $endDate->copy()->subDays(29);

        return [$startDate->startOfDay(), $endDate->endOfDay()];
    }
}
