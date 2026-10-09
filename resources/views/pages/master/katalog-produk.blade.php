@use('App\Support\Format')

@php
    $isOwner = $canManage;
@endphp

<x-ui.page-header
    title="Katalog Produk"
    subtitle="Definisi produk, seri, kondisi & harga jual. SKU dipecah per lot di area Inventory."
    :crumbs="['Master Data', 'Katalog Produk']"
>
    <x-slot:actions>
        @if ($isOwner)
            {{-- Opened through the shared helper, which walks the template below
                 with Alpine as it opens and tears it down as it closes. --}}
            <button type="button" class="btn-secondary flex items-center gap-2"
                    @click="notify.templateModal('seri-panel', {
                        title: 'Kelola Seri',
                        description: 'Tambahkan, ubah, atau hapus seri produk (mis. Hot Wheels, Matchbox, Mini GT).',
                        size: 'lg',
                    })">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 5h12M3 12h12M3 19h12M15 5l4 4-4 4M19 9h-8" />
                </svg>
                Kelola Seri
            </button>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<div class="mb-6 grid gap-5 sm:grid-cols-3">
    <x-ui.stat-card label="Total Produk" :value="$totalProducts" delta="Definisi katalog" delta-tone="info" />
    <x-ui.stat-card label="Lot Aktif" :value="$totalLots" delta="{{ Format::number($totalUnits) }} unit di tangan" delta-tone="success" />
    <x-ui.stat-card label="Perlu Review" :value="$needsReviewCount" delta="Tunggu persetujuan Owner" delta-tone="warning" />
</div>

<x-ui.section-card>
    <x-ui.data-table :table="$table">
        <x-slot:filters>
            <label class="filter-chip">
                <input type="checkbox" name="needs_review" value="1" class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/30" @checked(request()->boolean('needs_review'))>
                Hanya perlu review
            </label>
        </x-slot:filters>
        <x-slot:summary>{{ Format::number($series->count()) }} seri terdaftar</x-slot:summary>
    </x-ui.data-table>
</x-ui.section-card>

@if ($isOwner)
    <template id="seri-panel">
        <div x-data="seriManager()">
            <form @submit.prevent="add()" class="mb-5 grid gap-4 rounded-xl border border-border-subtle bg-canvas p-4 sm:grid-cols-4">
                <div class="sm:col-span-2">
                    <x-ui.field label="Nama Seri" name="series_name">
                        <input x-model="name" class="input-base" placeholder="mis. Hot Wheels" required>
                    </x-ui.field>
                </div>
                <div>
                    <x-ui.field label="Kode (opsional)" name="series_code">
                        <input x-model="code" class="input-base" placeholder="mis. HW">
                    </x-ui.field>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="btn-primary w-full" x-bind:disabled="loading">Tambahkan</button>
                </div>
                <p x-show="error" x-text="error" class="text-label-sm text-error-text sm:col-span-4"></p>
            </form>

            <ul class="divide-y divide-border-subtle">
                <template x-for="item in series" :key="item.id">
                    <li class="flex items-center justify-between py-3">
                        <div>
                            <p class="text-body-md font-medium text-text-strong" x-text="item.name"></p>
                            <p class="text-label-sm text-text-subtle">
                                <span x-text="item.code || 'tanpa kode'"></span>
                                · <span x-text="item.products_count + ' produk'"></span>
                            </p>
                        </div>
                        <button type="button" class="btn-ghost h-8 px-3 text-error-text" @click="remove(item.id)">Hapus</button>
                    </li>
                </template>
            </ul>
            <p x-show="!loading && series.length === 0" class="py-4 text-center text-label-sm text-text-subtle">
                Belum ada seri. Tambahkan seri pertama di atas.
            </p>
        </div>
    </template>
@endif