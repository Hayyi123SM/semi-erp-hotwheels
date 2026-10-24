<x-ui.page-header
    title="Edit Pengeluaran"
    subtitle="Perbarui data pengeluaran."
    :crumbs="['Keuangan', 'Pengeluaran', 'Edit']"
>
</x-ui.page-header>

<div class="max-w-4xl">
    <x-ui.section-card title="Form Pengeluaran">
        <form method="POST" action="{{ route('finance.expenses.update', $expense) }}" class="space-y-5">
            @csrf
            @method('PUT')
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field label="Tanggal" name="expense_date" required>
                    <input type="date" id="expense_date" name="expense_date" class="input-base" value="{{ old('expense_date', $expense->expense_date->toDateString()) }}" required>
                    @error('expense_date')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                </x-ui.field>
                <x-ui.field label="Kategori" name="expense_category_id">
                    <select id="expense_category_id" name="expense_category_id" class="input-base">
                        <option value="">Tanpa Kategori</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}" @selected(old('expense_category_id', $expense->expense_category_id) == $cat->id)>{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @error('expense_category_id')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                </x-ui.field>
                <x-ui.field label="Jumlah (Rp)" name="amount" required>
                    <input type="number" id="amount" name="amount" class="input-base" value="{{ old('amount', $expense->amount) }}" min="1" step="1" required>
                    @error('amount')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                </x-ui.field>
                <x-ui.field label="Metode Pembayaran" name="payment_method">
                    <select id="payment_method" name="payment_method" class="input-base">
                        <option value="">—</option>
                        <option value="TUNAI" @selected(old('payment_method', $expense->payment_method)=='TUNAI')>Tunai</option>
                        <option value="TRANSFER" @selected(old('payment_method', $expense->payment_method)=='TRANSFER')>Transfer</option>
                        <option value="QRIS" @selected(old('payment_method', $expense->payment_method)=='QRIS')>QRIS</option>
                        <option value="EDC" @selected(old('payment_method', $expense->payment_method)=='EDC')>EDC</option>
                    </select>
                    @error('payment_method')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                </x-ui.field>
                <x-ui.field label="Penerima/Vendor" name="vendor_name">
                    <input type="text" id="vendor_name" name="vendor_name" class="input-base" value="{{ old('vendor_name', $expense->vendor_name) }}">
                    @error('vendor_name')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                </x-ui.field>
                <x-ui.field label="No. Referensi" name="reference_no">
                    <input type="text" id="reference_no" name="reference_no" class="input-base" value="{{ old('reference_no', $expense->reference_no) }}">
                    @error('reference_no')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                </x-ui.field>
            </div>
            <x-ui.field label="Keterangan" name="description" required>
                <textarea id="description" name="description" rows="3" class="input-base" required>{{ old('description', $expense->description) }}</textarea>
                @error('description')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>
            <x-ui.field label="Catatan" name="notes">
                <textarea id="notes" name="notes" rows="3" class="input-base">{{ old('notes', $expense->notes) }}</textarea>
                @error('notes')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>
            <div class="flex items-center gap-3">
                <button class="btn-primary">Simpan Perubahan</button>
                <a href="{{ route('finance.expenses.show', $expense) }}" class="btn-secondary">Batal</a>
            </div>
        </form>
    </x-ui.section-card>
</div>
