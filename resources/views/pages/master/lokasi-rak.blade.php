@use('App\Support\Format')

<x-ui.page-header
    title="Lokasi Rak"
    subtitle="Zona penyimpanan fisik. Rak yang berisi stok tidak dapat dinonaktifkan."
    :crumbs="['Master Data', 'Lokasi Rak']"
>
    <x-slot:actions>
        {{-- Dipisah dari tabel: label rak dicetak per zona, dan daftar centang
             di halaman sendiri tidak boleh ikut terfilter oleh pencarian tabel. --}}
        <a href="{{ route('master.lokasi-rak.label-form') }}" class="btn-secondary">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
            Cetak Label Rak
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="mb-6 grid gap-5 sm:grid-cols-3">
    <x-ui.stat-card label="Total Rak" :value="$totalRacks" delta="{{ Format::number($activeRacks) }} aktif" delta-tone="info" />
    <x-ui.stat-card label="Unit Tersimpan" :value="$totalQty" delta="Dari lot aktif di rak" delta-tone="success" />
    <x-ui.stat-card label="Rak Berisi" :value="$usedRacks" delta="Terpakai" delta-tone="info" />
</div>

<x-ui.section-card>
    <x-ui.data-table :table="$table">
        <x-slot:filters>
            <select name="type" class="select-base filter-select" aria-label="Filter tipe rak">
                <option value="">Semua tipe</option>
                @foreach (\App\Enums\RackType::cases() as $option)
                    <option value="{{ $option->value }}" @selected(request()->query('type') === $option->value)>
                        {{ \App\Support\Format::enum($option->value) }}
                    </option>
                @endforeach
            </select>
            <label class="filter-chip">
                <input type="checkbox" name="active" value="1" class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/30" @checked(request()->boolean('active'))>
                Hanya rak aktif
            </label>
        </x-slot:filters>
    </x-ui.data-table>
</x-ui.section-card>
