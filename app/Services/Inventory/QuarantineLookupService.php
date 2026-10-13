<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\CardCondition;
use App\Enums\LotStatus;
use App\Models\StockLot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Pencarian kandidat SKU untuk kasus karantina (BR-11).
 *
 * Spesifikasi menyarankan pencarian fuzzy `pg_trgm`, tetapi aplikasi ini
 * berjalan di MySQL yang tidak punya trigram. Penggantinya adalah pencarian
 * sederhana dengan pembobotan: tiap kriteria yang cocok menambah skor, lalu
 * kandidat diurutkan berdasarkan skor. Ambang skor tidak dipakai untuk menyisih
 * kan -- yang menyisihkan adalah tidak ada satu pun kriteria yang cocok.
 */
class QuarantineLookupService
{
    /**
     * Pencarian tanpa satu pun kriteria menghasilkan tidak ada kandidat,
     * bukan seluruh isi Live Stock.
     */
    public function search(
        ?string $keyword = null,
        ?string $series = null,
        ?string $color = null,
        ?CardCondition $condition = null,
    ): array {
        if ($keyword === null && $series === null && $color === null && $condition === null) {
            return ['lots' => collect(), 'ambiguous' => false];
        }

        $query = StockLot::query()
            ->where('status', LotStatus::Available->value)
            ->where('qty_on_hand', '>', 0)
            ->with(['product.series', 'consignor', 'rack']);

        if ($keyword !== null) {
            $query->whereHas('product', fn ($q) => $q->where('name', 'like', '%'.$keyword.'%'));
        }

        if ($series !== null) {
            $query->whereHas('product.series', fn ($q) => $q->where('name', 'like', '%'.$series.'%'));
        }

        if ($color !== null) {
            $query->whereHas('product', fn ($q) => $q->where('color', 'like', '%'.$color.'%'));
        }

        if ($condition !== null) {
            $query->where('card_condition', $condition->value);
        }

        $lots = $query->orderBy('sku')->get();

        $scored = $lots->map(function (StockLot $lot) use ($keyword, $series, $color, $condition): StockLot {
            $lot->score = $this->score($lot, $keyword, $series, $color, $condition);

            return $lot;
        })->filter(fn (StockLot $lot): bool => $lot->score > 0);

        $ordered = $scored
            ->sortBy([
                ['score', 'desc'],
                ['qty_on_hand', 'desc'],
                ['sku', 'asc'],
            ])
            ->values();

        $ambiguous = $this->markAmbiguous($ordered);

        return ['lots' => $ordered, 'ambiguous' => $ambiguous];
    }

    /**
     * Total skor kecocokan lot dengan kriteria pencarian.
     */
    private function score(
        StockLot $lot,
        ?string $keyword,
        ?string $series,
        ?string $color,
        ?CardCondition $condition,
    ): int {
        $score = 0;

        if ($keyword !== null) {
            $name = self::normalize($lot->product?->name ?? '');
            $seriesName = self::normalize($lot->product?->series?->name ?? '');
            $keyword = self::normalize($keyword);

            if ($keyword !== '' && $name === $keyword) {
                $score += 15;
            }

            foreach (self::tokens($keyword) as $token) {
                if (self::contains($name, $token)) {
                    $score += 20;
                }
                if (self::contains($seriesName, $token)) {
                    $score += 10;
                }
            }
        }

        if ($series !== null && $lot->product?->series?->name !== null) {
            if (self::normalize($series) === self::normalize($lot->product->series->name)) {
                $score += 15;
            } elseif (self::contains(self::normalize($lot->product->series->name), self::normalize($series))) {
                $score += 8;
            }
        }

        if ($color !== null && $lot->product?->color !== null) {
            if (self::normalize($color) === self::normalize($lot->product->color)) {
                $score += 8;
            } elseif (self::contains(self::normalize($lot->product->color), self::normalize($color))) {
                $score += 4;
            }
        }

        if ($condition !== null && $lot->card_condition === $condition) {
            $score += 10;
        }

        return $score;
    }

    /**
     * Tandai kandidat ambigu: ada kandidat lain produk & kondisi yang sama
     * dari pemilik berbeda dengan skor yang setara (BR-11). Untuk kandidat
     * demikian, Assign ditolak sampai pemilik dikonfirmasi.
     *
     * @param  Collection<int, StockLot>  $lots
     */
    private function markAmbiguous(Collection $lots): bool
    {
        $groups = $lots->groupBy(fn (StockLot $lot): string => $lot->product_id.'|'.($lot->card_condition?->value ?? ''));

        $any = false;

        foreach ($groups as $group) {
            if ($group->count() < 2) {
                continue;
            }

            foreach ($group as $lot) {
                $rival = $group->first(fn (StockLot $other): bool => $other->is($lot) === false
                    && $other->score !== null
                    && abs($other->score - $lot->score) <= 20
                    && ($other->owner_type !== $lot->owner_type || $other->consignor_id !== $lot->consignor_id));

                if ($rival !== null) {
                    $lot->ambiguous = true;
                    $any = true;
                }
            }
        }

        return $any;
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $normalized): array
    {
        return array_values(array_filter(preg_split('/\s+/', $normalized) ?: [], fn (string $t): bool => $t !== ''));
    }

    private static function normalize(string $value): string
    {
        return Str::lower(preg_replace('/[^a-z0-9 ]+/u', ' ', $value) ?? '');
    }

    private static function contains(string $haystack, string $needle): bool
    {
        return $needle !== '' && str_contains($haystack, $needle);
    }
}
