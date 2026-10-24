<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseImportRequest;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ExpenseImportController extends Controller
{
    public function index(): View
    {
        $categories = ExpenseCategory::active()->orderBy('name')->get();

        return $this->page('pages.finance.expenses.import', compact('categories'));
    }

    public function preview(ExpenseImportRequest $request): View|RedirectResponse
    {
        $file = $request->file('file');
        $path = $file->getRealPath();

        $handle = fopen($path, 'r');
        if (! $handle) {
            return back()->with('error', 'Tidak bisa membaca file CSV.');
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return back()->with('error', 'File CSV kosong atau tidak valid.');
        }

        $header = array_map(fn ($h) => Str::lower(trim((string) $h)), $header);
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) === 1 && trim((string) $data[0]) === '') {
                continue;
            }

            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = isset($data[$i]) ? trim((string) $data[$i]) : null;
            }

            $rows[] = $row;
            if (count($rows) >= 100) {
                break;
            }
        }

        fclose($handle);

        if (empty($rows)) {
            return back()->with('error', 'Tidak ada baris data yang bisa diimport.');
        }

        $categories = ExpenseCategory::active()->orderBy('name')->get()->pluck('name', 'id')->all();

        return $this->page('pages.finance.expenses.import-preview', [
            'rows' => $rows,
            'header' => $header,
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.expense_date' => ['required', 'date'],
            'rows.*.amount' => ['required', 'integer', 'min:1'],
            'rows.*.description' => ['required', 'string'],
            'rows.*.expense_category_id' => ['nullable', 'integer', 'exists:expense_categories,id'],
            'rows.*.vendor_name' => ['nullable', 'string'],
            'rows.*.payment_method' => ['nullable', 'in:TUNAI,TRANSFER,QRIS,EDC'],
            'rows.*.reference_no' => ['nullable', 'string'],
            'rows.*.notes' => ['nullable', 'string'],
        ]);

        $batch = (string) Str::uuid();
        $userId = Auth::id();

        DB::transaction(function () use ($request, $batch, $userId) {
            foreach ($request->input('rows', []) as $row) {
                Expense::create([
                    'expense_date' => $row['expense_date'],
                    'expense_category_id' => $row['expense_category_id'] ?: null,
                    'amount' => (int) $row['amount'],
                    'description' => $row['description'],
                    'vendor_name' => $row['vendor_name'] ?: null,
                    'payment_method' => $row['payment_method'] ?: null,
                    'reference_no' => $row['reference_no'] ?: null,
                    'notes' => $row['notes'] ?: null,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'is_imported' => true,
                    'import_batch' => $batch,
                ]);
            }
        });

        $count = count($request->input('rows', []));

        return redirect()->route('finance.expenses.index')->with('status', $count.' pengeluaran berhasil diimport.');
    }
}
