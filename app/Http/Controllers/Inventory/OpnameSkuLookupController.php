<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Pages\OpnameController;
use App\Http\Requests\SearchProductRequest;
use App\Services\Inventory\OpnameSkuLookup;
use App\Services\Inventory\OpnameSkuLookupResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Pencarian SKU untuk modal Mulai Sesi opname.
 *
 * Dipisah dari {@see OpnameController} karena
 * bentuknya berbeda dari halaman: yang ini menjawab JSON untuk picker, tidak
 * merender apa pun, dan tidak punya halaman untuk dibuka.
 */
class OpnameSkuLookupController extends Controller
{
    public function __invoke(SearchProductRequest $request, OpnameSkuLookup $lookup): JsonResponse
    {
        return $this->answer($lookup->search($request->term()));
    }

    /**
     * @param  Collection<int, OpnameSkuLookupResult>  $items
     */
    private function answer(Collection $items): JsonResponse
    {
        return response()->json([
            'items' => $items
                ->map(fn (OpnameSkuLookupResult $item): array => $item->toArray())
                ->values(),
        ]);
    }
}
