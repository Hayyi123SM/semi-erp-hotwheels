<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\ProductSeries;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductSeriesController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index()
    {
        return response()->json(
            ProductSeries::orderBy('name')
                ->withCount('products')
                ->get(['id', 'code', 'name']),
        );
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        $data = $this->validated($request, null);

        $series = ProductSeries::create($data);
        $this->audit->created($series);

        return response()->json(['message' => 'Seri '.$series->name.' dibuat.', 'series' => $series], 201);
    }

    public function update(Request $request, ProductSeries $series)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        $before = $series->getAttributes();
        $series->fill($this->validated($request, $series))->save();
        $this->audit->updated($series, $before);

        return response()->json(['message' => 'Seri diperbarui.', 'series' => $series]);
    }

    public function destroy(ProductSeries $series)
    {
        abort_unless(auth()->user()->isOwner(), 403);

        if ($series->products()->exists()) {
            throw ValidationException::withMessages([
                'series' => 'Seri "'.$series->name.'" tidak dapat dihapus karena masih dipakai '.$series->products()->count().' produk.',
            ]);
        }

        $this->audit->delete($series);
        $series->delete();

        return response()->json(['message' => 'Seri '.$series->name.' dihapus.']);
    }

    private function validated(Request $request, ?ProductSeries $series): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('product_series', 'code')->ignore($series)],
        ]);
    }
}
