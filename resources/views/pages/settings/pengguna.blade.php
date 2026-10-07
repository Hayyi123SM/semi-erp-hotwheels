<x-ui.page-header
    title="Pengguna & Role"
    subtitle="Kelola akun staf & owner. PIN opsional untuk transaksi internal."
    :crumbs="['Pengaturan', 'Pengguna & Role']"
/>

<div class="mb-6 grid gap-5 sm:grid-cols-3">
    <x-ui.stat-card label="Total Pengguna" :value="$totalUsers" delta="Seluruh akun" delta-tone="info" />
    <x-ui.stat-card label="Owner" :value="$ownerCount" delta="Akses penuh" delta-tone="warning" />
    <x-ui.stat-card label="Non-aktif" :value="$inactiveCount" delta="Tidak dapat login" delta-tone="error" />
</div>

<x-ui.section-card>
    <x-ui.data-table :table="$table">
        <x-slot:filters>
            <select name="role" class="select-base filter-select" aria-label="Filter role pengguna">
                <option value="">Semua role</option>
                @foreach (\App\Enums\Role::cases() as $option)
                    <option value="{{ $option->value }}" @selected(request()->query('role') === $option->value)>
                        {{ $option->value === \App\Enums\Role::Owner->value ? 'Owner' : 'Staff' }}
                    </option>
                @endforeach
            </select>
        </x-slot:filters>
    </x-ui.data-table>
</x-ui.section-card>
