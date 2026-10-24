<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseCategoryRequest;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->input('q'));

        $categories = ExpenseCategory::query()
            ->withCount('expenses')
            ->when($search !== '', function ($query) use ($search) {
                $query->where('name', 'like', '%'.$search.'%');
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return $this->page('pages.finance.expense-categories.index', compact('categories', 'search'));
    }

    public function create(): View
    {
        return $this->page('pages.finance.expense-categories.create');
    }

    public function store(ExpenseCategoryRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['slug'] = ExpenseCategory::generateSlug($data['name']);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        ExpenseCategory::create($data);

        return redirect()->route('finance.expense-categories.index')->with('status', 'Kategori pengeluaran berhasil ditambahkan.');
    }

    public function show(ExpenseCategory $kategori_pengeluaran): View
    {
        $kategori_pengeluaran->loadCount('expenses');
        $kategori_pengeluaran->load(['expenses' => function ($query) {
            $query->latest('expense_date')->limit(10);
        }]);

        return $this->page('pages.finance.expense-categories.show', ['category' => $kategori_pengeluaran]);
    }

    public function edit(ExpenseCategory $kategori_pengeluaran): View
    {
        return $this->page('pages.finance.expense-categories.edit', ['category' => $kategori_pengeluaran]);
    }

    public function update(ExpenseCategoryRequest $request, ExpenseCategory $kategori_pengeluaran): RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active', true);
        $data['updated_by'] = Auth::id();

        if (($data['name'] ?? null) && $data['name'] !== $kategori_pengeluaran->name) {
            $data['slug'] = ExpenseCategory::generateSlug($data['name']);
        }

        $kategori_pengeluaran->update($data);

        return redirect()->route('finance.expense-categories.index')->with('status', 'Kategori pengeluaran berhasil diperbarui.');
    }

    public function destroy(ExpenseCategory $kategori_pengeluaran): RedirectResponse
    {
        $kategori_pengeluaran->updated_by = Auth::id();
        $kategori_pengeluaran->save();
        $kategori_pengeluaran->delete();

        return redirect()->route('finance.expense-categories.index')->with('status', 'Kategori pengeluaran berhasil diarsipkan.');
    }
}
