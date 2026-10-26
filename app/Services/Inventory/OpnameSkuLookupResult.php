<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\StockLot;

/**
 * Satu lot yang boleh dipilih sebagai cakupan opname.
 *
 * Angka sistem sengaja tidak ada di sini: picker dipakai sebelum sesi dimulai,
 * tetapi membawa qty ke layar tetap membuka kebiasaan yang sama dengan layar
 * penghitungan. Yang dibutuhkan untuk memilih hanya identitas barang dan rak.
 */
final readonly class OpnameSkuLookupResult
{
    public function __construct(
        public string $sku,
        public string $name,
        public string $series,
        public ?string $rack,
        public ?string $color = null,
        public ?int $year = null,
    ) {}

    public static function fromLot(StockLot $lot): self
    {
        return new self(
            sku: $lot->sku,
            name: $lot->product?->name ?? $lot->sku,
            series: $lot->product?->series?->name ?? '',
            rack: $lot->rack?->code,
            color: $lot->product?->color,
            year: $lot->product?->year,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'name' => $this->name,
            'series' => $this->series,
            'rack' => $this->rack,
            'color' => $this->color ?? '',
            'year' => $this->year,
        ];
    }
}
