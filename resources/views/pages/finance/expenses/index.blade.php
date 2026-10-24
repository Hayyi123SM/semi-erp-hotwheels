@use('App\Support\Format')

<x-ui.page-header
    title="Pengeluaran"
    subtitle="Pencatatan pengeluaran operasional toko (catatan terpisah untuk Owner)."
    :crumbs="['Keuangan', 'Pengeluaran']"
>
    <x-slot:actions>
        <a href="{{ route('finance.expense-categories.index') }}" class="btn-ghost">Kategori</a>
        <a href="{{ route('finance.expenses.import') }}" class="btn-secondary">Import CSV</a>
        <a href="{{ route('finance.expenses.create') }}" class="btn-primary">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            Tambah Pengeluaran
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <x-ui.section-card title="Filter">
        <form method="GET" action="{{ route('finance.expenses.index') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
            <input type="date" name="from" value="{{ $from }}" class="input-base">
            <input type="date" name="to" value="{{ $to }}" class="input-base">
            <select name="category" class="input-base">
                <option value="">Semua Kategori</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->id }}" @selected($categoryId == $cat->id)>{{ $cat->name }}</option>
                @endforeach
            </select>
            <input type="text" name="q" value="{{ $q }}" class="input-base lg:col-span-2" placeholder="Cari keterangan/vendor/referensi">
            <div class="flex items-center gap-2">
                <button class="btn-primary h-10">Terapkan</button>
                <a href="{{ route('finance.expenses.index') }}" class="btn-ghost h-10">Reset</a>
            </div>
        </form>
    </x-ui.section-card>

    <x-ui.section-card title="Daftar Pengeluaran" :pad="false">
        <div class="flex items-center justify-between px-6 py-3">
            <p class="text-body-sm text-text-muted">Total hasil filter: <span class="font-semibold text-text-strong">{{ Format::rupiah($total) }}</span></p>
        </div>
        <div class="table-scroll">
            <table class="w-full min-w-[980px] text-left">
                <thead class="thead-dense">
                    <tr>
                        <th class="px-6 py-3 font-semibold">Tanggal</th>
                        <th class="px-6 py-3 font-semibold">Kategori</th>
                        <th class="px-6 py-3 font-semibold">Keterangan</th>
                        <th class="px-6 py-3 font-semibold">Vendor</th>
                        <th class="px-6 py-3 font-semibold">Metode</th>
                        <th class="px-6 py-3 text-right font-semibold">Jumlah</th>
                        <th class="px-6 py-3 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-subtle">
                    @forelse ($expenses as $expense)
                        <tr class="transition hover:bg-canvas">
                            <td class="px-6 py-3 text-body-sm text-text-muted">{{ Format::date($expense->expense_date) }}</td>
                            <td class="px-6 py-3 text-body-sm text-text-strong">{{ $expense->category?->name ?? '—' }}</td>
                            <td class="px-6 py-3">
                                <p class="text-body-md text-text-strong">{{ Str::limit($expense->description, 80) }}</p>
                                @if ($expense->is_imported)
                                    <p class="text-label-sm text-text-subtle">Diimport</p>
                                @endif
                            </td>
                            <td class="px-6 py-3 text-body-sm text-text-muted">{{ $expense->vendor_name ?: '—' }}</td>
                            <td class="px-6 py-3 text-body-sm text-text-muted">{{ $expense->payment_method ?: '—' }}</td>
                            <td class="px-6 py-3 text-right tabular-nums text-body-md font-semibold text-text-strong">{{ Format::rupiah($expense->amount) }}</td>
                            <td class="px-6 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('finance.expenses.show', $expense) }}" class="btn-ghost h-8 px-2.5">Lihat</a>
                                    <a href="{{ route('finance.expenses.edit', $expense) }}" class="btn-ghost h-8 px-2.5">Edit</a>
                                    <form method="POST" action="{{ route('finance.expenses.destroy', $expense) }}" onsubmit="return confirm('Arsipkan pengeluaran ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn-ghost h-8 px-2.5 text-error-text">Arsipkan</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-10 text-center text-body-sm text-text-muted">Belum ada pengeluaran.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($expenses->hasPages())
            <div class="px-6 py-4">{{ $expenses->links() }}</div>
        @endif
    </x-ui.section-card>
</div>
