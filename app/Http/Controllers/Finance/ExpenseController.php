<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseRequest;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function index(Request $request): View
    {
        $from = $request->input('from');
        $to = $request->input('to');
        $categoryId = $request->input('category');
        $q = trim((string) $request->input('q'));

        $query = Expense::query()
            ->with(['category', 'creator'])
            ->forPeriod($from, $to)
            ->when($categoryId, fn ($q) => $q->where('expense_category_id', $categoryId))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('description', 'like', '%'.$q.'%')
                        ->orWhere('vendor_name', 'like', '%'.$q.'%')
                        ->orWhere('reference_no', 'like', '%'.$q.'%');
                });
            })
            ->latest('expense_date')
            ->latest('id');

        $expenses = (clone $query)->paginate(20)->withQueryString();
        $total = (clone $query)->sum('amount');
        $categories = ExpenseCategory::query()->orderBy('name')->get();

        return $this->page('pages.finance.expenses.index', compact('expenses', 'total', 'categories', 'from', 'to', 'categoryId', 'q'));
    }

    public function create(): View
    {
        $categories = ExpenseCategory::active()->orderBy('name')->get();

        return $this->page('pages.finance.expenses.create', compact('categories'));
    }

    public function store(ExpenseRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();
        $data['is_imported'] = false;

        Expense::create($data);

        return redirect()->route('finance.expenses.index')->with('status', 'Pengeluaran berhasil ditambahkan.');
    }

    public function show(Expense $pengeluaran): View
    {
        $pengeluaran->load(['category', 'creator', 'updater']);

        return $this->page('pages.finance.expenses.show', ['expense' => $pengeluaran]);
    }

    public function edit(Expense $pengeluaran): View
    {
        $categories = ExpenseCategory::withTrashed()->orderBy('name')->get();

        return $this->page('pages.finance.expenses.edit', ['expense' => $pengeluaran, 'categories' => $categories]);
    }

    public function update(ExpenseRequest $request, Expense $pengeluaran): RedirectResponse
    {
        $data = $request->validated();
        $data['updated_by'] = Auth::id();

        $pengeluaran->update($data);

        return redirect()->route('finance.expenses.show', $pengeluaran)->with('status', 'Pengeluaran berhasil diperbarui.');
    }

    public function destroy(Expense $pengeluaran): RedirectResponse
    {
        $pengeluaran->updated_by = Auth::id();
        $pengeluaran->save();
        $pengeluaran->delete();

        return redirect()->route('finance.expenses.index')->with('status', 'Pengeluaran berhasil diarsipkan.');
    }
}
