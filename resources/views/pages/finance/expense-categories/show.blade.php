@use('App\Support\Format')

<x-ui.page-header
    title="Detail Kategori Pengeluaran"
    subtitle="{{ $category->name }}"
    :crumbs="['Keuangan', 'Kategori Pengeluaran', 'Detail']"
>
    <x-slot:actions>
        <a href="{{ route('finance.expense-categories.edit', $category) }}" class="btn-secondary">Edit</a>
        <a href="{{ route('finance.expense-categories.index') }}" class="btn-ghost">Kembali</a>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <x-ui.section-card title="Informasi">
        <dl class="grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-label-md text-text-muted">Nama</dt>
                <dd class="text-body-md text-text-strong">{{ $category->name }}</dd>
            </div>
            <div>
                <dt class="text-label-md text-text-muted">Status</dt>
                <dd class="text-body-md text-text-strong">{{ $category->is_active ? 'Aktif' : 'Nonaktif' }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-label-md text-text-muted">Deskripsi</dt>
                <dd class="text-body-md text-text-strong">{{ $category->description ?: '—' }}</dd>
            </div>
        </dl>
    </x-ui.section-card>

    <x-ui.section-card title="Pengeluaran Terbaru" :pad="false">
        <div class="table-scroll">
            <table class="w-full min-w-[720px] text-left">
                <thead class="thead-dense">
                    <tr>
                        <th class="px-6 py-3 font-semibold">Tanggal</th>
                        <th class="px-6 py-3 font-semibold">Keterangan</th>
                        <th class="px-6 py-3 font-semibold">Vendor</th>
                        <th class="px-6 py-3 text-right font-semibold">Jumlah</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-subtle">
                    @forelse ($category->expenses as $expense)
                        <tr>
                            <td class="px-6 py-3 text-body-sm text-text-muted">{{ Format::date($expense->expense_date) }}</td>
                            <td class="px-6 py-3 text-body-md text-text-strong">{{ $expense->description }}</td>
                            <td class="px-6 py-3 text-body-sm text-text-muted">{{ $expense->vendor_name ?: '—' }}</td>
                            <td class="px-6 py-3 text-right tabular-nums text-body-md text-text-strong">{{ Format::rupiah($expense->amount) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-8 text-center text-body-sm text-text-muted">Belum ada pengeluaran di kategori ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.section-card>
</div>
