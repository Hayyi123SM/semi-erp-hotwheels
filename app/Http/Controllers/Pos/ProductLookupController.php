<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchProductRequest;
use App\Services\Pos\ProductLookup;
use App\Services\Pos\ProductLookupResult;
use Illuminate\Http\JsonResponse;

/**
 * Pencarian produk untuk layar Kasir.
 *
 * Dipisah dari `PosController` karena bentuknya berbeda dari halaman: yang ini
 * menjawab JSON untuk panel picker, tidak merender apa pun, dan tidak punya
 * halaman untuk dibuka. `PosController` berisi halaman yang dibaca mata dan aksi
 * yang mengubah data -- menaruh pencarian produk di sana membuat satu kelas
 * dengan dua arah yang tidak berkaitan.
 */
class ProductLookupController extends Controller
{
    public function __invoke(SearchProductRequest $request, ProductLookup $lookup)
    {
        $barcode = $request->barcode();

        if ($barcode !== null) {
            return $this->answer($lookup->findByBarcode($barcode));
        }

        return $this->answer(null, $lookup->search($request->term()));
    }

    /**
     * Satu lot, atau daftar yang bisa kosong.
     *
     * `lot` dibedakan dari `items` supaya pemanggil bisa membedakan "tidak ada
     * barang dengan kode itu" dari "kode yang diketik belum cukup panjang untuk
     * dicari" tanpa harus memeriksa `items` sendiri.
     *
     * Keduanya dikirimkan pada setiap jawaban, dengan bentuk yang sama, jadi
     * pemanggil tidak perlu dua jalur terpisah hanya karena bentuk permintaannya
     * berbeda.
     */
    private function answer(?ProductLookupResult $lot, ?iterable $more = null): JsonResponse
    {
        $items = $more === null
            ? ($lot === null ? [] : [$lot])
            : $more;

        return response()->json([
            'lot' => $lot?->toArray(),
            'items' => collect($items)
                ->map(fn (ProductLookupResult $item): array => $item->toArray())
                ->values(),
        ]);
    }
}
