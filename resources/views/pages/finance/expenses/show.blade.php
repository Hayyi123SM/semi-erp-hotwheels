@use('App\Support\Format')

<x-ui.page-header
    title="Detail Pengeluaran"
    subtitle="Informasi lengkap pengeluaran."
    :crumbs="['Keuangan', 'Pengeluaran', 'Detail']"
>
    <x-slot:actions>
        <a href="{{ route('finance.expenses.edit', $expense) }}" class="btn-secondary">Edit</a>
        <a href="{{ route('finance.expenses.index') }}" class="btn-ghost">Kembali</a>
    </x-slot:actions>
</x-ui.page-header>

<div class="max-w-4xl">
    <x-ui.section-card title="Informasi Pengeluaran">
        <dl class="grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-label-md text-text-muted">Tanggal</dt>
                <dd class="text-body-md text-text-strong">{{ Format::date($expense->expense_date) }}</dd>
            </div>
            <div>
                <dt class="text-label-md text-text-muted">Kategori</dt>
                <dd class="text-body-md text-text-strong">{{ $expense->category?->name ?? 'Tanpa Kategori' }}</dd>
            </div>
            <div>
                <dt class="text-label-md text-text-muted">Jumlah</dt>
                <dd class="text-body-md font-semibold text-text-strong">{{ Format::rupiah($expense->amount) }}</dd>
            </div>
            <div>
                <dt class="text-label-md text-text-muted">Metode Pembayaran</dt>
                <dd class="text-body-md text-text-strong">{{ $expense->payment_method ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-label-md text-text-muted">Penerima/Vendor</dt>
                <dd class="text-body-md text-text-strong">{{ $expense->vendor_name ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-label-md text-text-muted">No. Referensi</dt>
                <dd class="text-body-md text-text-strong">{{ $expense->reference_no ?: '—' }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-label-md text-text-muted">Keterangan</dt>
                <dd class="text-body-md text-text-strong">{{ $expense->description }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-label-md text-text-muted">Catatan</dt>
                <dd class="text-body-md text-text-strong">{{ $expense->notes ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-label-md text-text-muted">Dicatat Oleh</dt>
                <dd class="text-body-md text-text-strong">{{ $expense->creator?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-label-md text-text-muted">Terakhir Diperbarui</dt>
                <dd class="text-body-md text-text-strong">{{ $expense->updater?->name ?? '—' }} · {{ $expense->updated_at?->format('d/m/Y H:i') }}</dd>
            </div>
            @if ($expense->is_imported)
                <div class="sm:col-span-2">
                    <dt class="text-label-md text-text-muted">Import</dt>
                    <dd class="text-body-md text-text-strong">Diimport (batch: {{ $expense->import_batch }})</dd>
                </div>
            @endif
        </dl>
    </x-ui.section-card>
</div>
