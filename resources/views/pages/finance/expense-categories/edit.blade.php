<x-ui.page-header
    title="Edit Kategori Pengeluaran"
    subtitle="Perbarui data kategori pengeluaran."
    :crumbs="['Keuangan', 'Kategori Pengeluaran', 'Edit']"
>
</x-ui.page-header>

<div class="max-w-2xl">
    <x-ui.section-card title="Form Kategori">
        <form method="POST" action="{{ route('finance.expense-categories.update', $category) }}" class="space-y-5">
            @csrf
            @method('PUT')
            <x-ui.field label="Nama Kategori" name="name" required>
                <input type="text" id="name" name="name" class="input-base" value="{{ old('name', $category->name) }}" required>
                @error('name')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>
            <x-ui.field label="Deskripsi" name="description">
                <textarea id="description" name="description" rows="3" class="input-base">{{ old('description', $category->description) }}</textarea>
                @error('description')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
            </x-ui.field>
            <label class="flex items-center gap-2 text-body-sm text-text-strong">
                <input type="checkbox" name="is_active" value="1" {{ old('is_active', $category->is_active) ? 'checked' : '' }}>
                Aktif
            </label>
            <div class="flex items-center gap-3">
                <button class="btn-primary">Simpan Perubahan</button>
                <a href="{{ route('finance.expense-categories.index') }}" class="btn-secondary">Batal</a>
            </div>
        </form>
    </x-ui.section-card>
</div>
