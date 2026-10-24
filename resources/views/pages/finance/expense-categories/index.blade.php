@use('App\Support\Format')

<x-ui.page-header
    title="Kategori Pengeluaran"
    subtitle="Kelola kategori pengeluaran operasional toko."
    :crumbs="['Keuangan', 'Kategori Pengeluaran']"
>
    <x-slot:actions>
        <a href="{{ route('finance.expense-categories.create') }}" class="btn-primary">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            Tambah Kategori
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <x-ui.section-card title="Daftar Kategori" :pad="false">
        <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4">
            <form method="GET" action="{{ route('finance.expense-categories.index') }}" class="flex items-center gap-2">
                <input type="text" name="q" value="{{ $search }}" class="input-base h-9 w-64" placeholder="Cari kategori...">
                <button class="btn-secondary h-9">Cari</button>
            </form>
            <a href="{{ route('finance.expenses.index') }}" class="btn-ghost h-9">Kembali ke Pengeluaran</a>
        </div>
        <div class="table-scroll">
            <table class="w-full min-w-[720px] text-left">
                <thead class="thead-dense">
                    <tr>
                        <th class="px-6 py-3 font-semibold">Nama</th>
                        <th class="px-6 py-3 font-semibold">Deskripsi</th>
                        <th class="px-6 py-3 text-center font-semibold">Status</th>
                        <th class="px-6 py-3 text-center font-semibold">Jumlah Pengeluaran</th>
                        <th class="px-6 py-3 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-subtle">
                    @forelse ($categories as $category)
                        <tr class="transition hover:bg-canvas">
                            <td class="px-6 py-3">
                                <p class="text-body-md font-medium text-text-strong">{{ $category->name }}</p>
                            </td>
                            <td class="px-6 py-3 text-body-sm text-text-muted">{{ $category->description ?: '—' }}</td>
                            <td class="px-6 py-3 text-center">
                                @if ($category->is_active)
                                    <x-ui.badge-status type="success">Aktif</x-ui.badge-status>
                                @else
                                    <x-ui.badge-status type="neutral">Nonaktif</x-ui.badge-status>
                                @endif
                            </td>
                            <td class="px-6 py-3 text-center tabular-nums text-body-sm text-text-muted">{{ $category->expenses_count }}</td>
                            <td class="px-6 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('finance.expense-categories.show', $category) }}" class="btn-ghost h-8 px-2.5">Lihat</a>
                                    <a href="{{ route('finance.expense-categories.edit', $category) }}" class="btn-ghost h-8 px-2.5">Edit</a>
                                    <form method="POST" action="{{ route('finance.expense-categories.destroy', $category) }}" onsubmit="return confirm('Arsipkan kategori ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn-ghost h-8 px-2.5 text-error-text">Arsipkan</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-10 text-center text-body-sm text-text-muted">Belum ada kategori pengeluaran.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($categories->hasPages())
            <div class="px-6 py-4">{{ $categories->links() }}</div>
        @endif
    </x-ui.section-card>
</div>
