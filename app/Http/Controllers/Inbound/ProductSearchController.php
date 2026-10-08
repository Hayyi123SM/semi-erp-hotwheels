<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbound;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Pages\InboundController;
use App\Http\Requests\SearchProductRequest;
use App\Services\Inbound\ProductSearch;
use App\Services\Inbound\ProductSearchResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Pencarian produk untuk popup Stock In Pribadi.
 *
 * Dipisah dari {@see InboundController} karena
 * bentuknya berbeda dari halaman: yang ini menjawab JSON untuk panel picker,
 * tidak merender apa pun. Menaruhnya di `InboundController` berarti menambah
 * penyaji lama yang sudah memuat halaman, draft, label, dan cetak -- dengan
 * sekali lagi tugas yang tidak berkaitan dengan menyusun halaman.
 *
 * Bentuk jawabannya `{ items: [...] }`, sama dengan picker kasir, supaya panel
 * `product-picker.js` yang sama bisa dipakai tanpa cabang khusus di sisi klien.
 */
class ProductSearchController extends Controller
{
    public function __invoke(SearchProductRequest $request, ProductSearch $search)
    {
        $barcode = $request->barcode();

        if ($barcode !== null) {
            return $this->answer($search->findByBarcode($barcode));
        }

        return $this->answer($search->search($request->term()));
    }

    /**
     * Daftar hasil, selalu dalam bentuk `items` supaya pemanggil punya satu
     * jalur -- termasuk pemindaian barcode, yang jawabannya juga daftar (bisa
     * satu, bisa nol, dan kasus lebih dari satu tetap dikirim apa adanya lalu
     * diputuskan di layar).
     *
     * @param  Collection<int, ProductSearchResult>  $items
     */
    private function answer(Collection $items): JsonResponse
    {
        return response()->json([
            'items' => collect($items)
                ->map(fn (ProductSearchResult $item): array => $item->toArray())
                ->values(),
        ]);
    }
}
