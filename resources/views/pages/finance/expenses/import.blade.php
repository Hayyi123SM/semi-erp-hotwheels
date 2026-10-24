<x-ui.page-header
    title="Import Pengeluaran CSV"
    subtitle="Import massal pengeluaran operasional."
    :crumbs="['Keuangan', 'Pengeluaran', 'Import']"
>
</x-ui.page-header>

<div class="max-w-3xl space-y-6">
    <x-ui.section-card title="Petunjuk CSV">
        <div class="space-y-2 text-body-sm text-text-muted">
            <p>Gunakan header minimal: <strong>expense_date, amount, description</strong></p>
            <p>Header opsional: <strong>expense_category, vendor_name, payment_method, reference_no, notes</strong></p>
            <p>Format tanggal: <strong>YYYY-MM-DD</strong> | Jumlah: angka tanpa titik/koma | payment_method: TUNAI/TRANSFER/QRIS/EDC</p>
            <p>Baris kosong akan dilewati. Maksimal 100 baris per preview (aman untuk import bertahap).</p>
        </div>
    </x-ui.section-card>

    <x-ui.section-card title="Upload File">
        <form method="POST" action="{{ route('finance.expenses.import.preview') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <x-ui.field label="File CSV" name="file" required>
                <input type="file" id="file" name="file" accept=".csv,.txt" class="input-base" required>
                @error('file')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>
            <div class="flex items-center gap-3">
                <button class="btn-primary">Preview Import</button>
                <a href="{{ route('finance.expenses.index') }}" class="btn-secondary">Kembali</a>
            </div>
        </form>
    </x-ui.section-card>
</div>
