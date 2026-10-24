<x-ui.page-header
    title="Tambah Kategori Pengeluaran"
    subtitle="Buat kategori baru untuk pencatatan pengeluaran."
    :crumbs="['Keuangan', 'Kategori Pengeluaran', 'Tambah']"
>
</x-ui.page-header>

<div class="max-w-2xl">
    <x-ui.section-card title="Form Kategori">
        <form method="POST" action="{{ route('finance.expense-categories.store') }}" class="space-y-5">
            @csrf
            <x-ui.field label="Nama Kategori" name="name" required>
                <input type="text" id="name" name="name" class="input-base" value="{{ old('name') }}" required>
                @error('name')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>
            <x-ui.field label="Deskripsi" name="description">
                <textarea id="description" name="description" rows="3" class="input-base">{{ old('description') }}</textarea>
                @error('description')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>
            <label class="flex items-center gap-2 text-body-sm text-text-strong">
                <input type="checkbox" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }}>
                Aktif
            </label>
            <div class="flex items-center gap-3">
                <button class="btn-primary">Simpan</button>
                <a href="{{ route('finance.expense-categories.index') }}" class="btn-secondary">Batal</a>
            </div>
        </form>
    </x-ui.section-card>
</div>
