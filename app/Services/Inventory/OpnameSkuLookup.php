<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\StockLot;
use App\Support\Sql\LikePattern;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Pencarian SKU untuk modal Mulai Sesi opname.
 *
 * Hasilnya dibatasi ke lot yang juga diterima {@see OpnameService::start()}
 * untuk cakupan SKU. Lot kosong ikut ditawarkan karena unit yang ditemukan
 * kembali tetap harus bisa dihitung; lot yang sudah keluar dari gudang tetap
 * tidak ditawarkan. Payload tidak membawa qty agar blind count tidak bocor
 * sebelum sesi dimulai.
 */
final class OpnameSkuLookup
{
    private const int MIN_TERM_LENGTH = 2;

    private const int SEARCH_LIMIT = 30;

    /**
     * @return Collection<int, OpnameSkuLookupResult>
     */
    public function search(?string $term): Collection
    {
        $term = trim((string) $term);

        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            return new Collection;
        }

        return StockLot::query()
            ->opnameAdjustable()
            ->where(function (Builder $group) use ($term): void {
                $like = LikePattern::contains($term);

                $group->whereRaw(LikePattern::clause('`stock_lots`.`sku`'), [$like])
                    ->orWhereHas('product', fn (Builder $product) => $product->whereRaw(
                        LikePattern::clause('`name`'),
                        [$like],
                    ));
            })
            ->with(['product.series', 'rack'])
            ->orderByRaw('CASE WHEN `stock_lots`.`sku` = ? THEN 0 ELSE 1 END', [$term])
            ->orderBy('stock_lots.sku')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->map(fn (StockLot $lot): OpnameSkuLookupResult => OpnameSkuLookupResult::fromLot($lot));
    }
}
