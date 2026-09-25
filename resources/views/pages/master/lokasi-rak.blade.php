<x-ui.page-header
    title="Lokasi Rak"
    subtitle="Zonasi penyimpanan: DISPLAY · STORAGE · QUARANTINE · RTV_STAGING. Kapasitas divisualkan kronologis."
    :crumbs="['Master Data', 'Lokasi Rak']"
>
    <x-slot:actions>
        <x-ui.modal title="Tambah Rak" description="Format kode: ZONA-RAK-LEVEL (mis. A-01-03)">
            <x-slot:trigger>
                <button type="button" class="btn-primary">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 4v16m8-8H4"/></svg>
                    Tambah Rak
                </button>
            </x-slot:trigger>
            <x-slot:panel>
                <form @submit.prevent="$store.toast.push('Rak tersimpan (mock)', 'success'); open = false" class="space-y-5">
                    <x-ui.field label="Kode Rak" name="rk_code" required hint="Gunakan huruf kapital & angka. Format: ZONA-RAK-LEVEL">
                        <input id="rk_code" class="input-base font-mono uppercase" placeholder="A-01-04">
                    </x-ui.field>
                    <x-ui.field label="Tipe Rak" name="rk_type">
                        <select id="rk_type" class="input-base">
                            <option>DISPLAY</option>
                            <option>STORAGE</option>
                            <option>QUARANTINE</option>
                            <option>RTV_STAGING</option>
                        </select>
                    </x-ui.field>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.field label="Kapasitas (unit)" name="rk_capacity">
                            <input id="rk_capacity" type="number" class="input-base tabular-nums" value="48">
                        </x-ui.field>
                        <x-ui.field label="Zona" name="rk_zone">
                            <input id="rk_zone" class="input-base font-mono uppercase" placeholder="A">
                        </x-ui.field>
                    </div>
                    <div class="flex items-center justify-end gap-2 border-t border-border-subtle pt-4">
                        <button type="button" class="btn-secondary" @click="open = false">Batal</button>
                        <button type="submit" class="btn-primary">Simpan Rak</button>
                    </div>
                </form>
            </x-slot:panel>
        </x-ui.modal>
    </x-slot:actions>
</x-ui.page-header>

<div class="mb-6 grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
    <x-ui.stat-card label="Total Rak Aktif" value="{{ collect($racks)->where('active', true)->count() }}" delta="1 dinonaktifkan" delta-tone="warning" />
    <x-ui.stat-card label="Kapasitas Terpakai" value="74%" delta="Rata-rata seluruh zona" delta-tone="info" />
    <x-ui.stat-card label="Rak Penuh (> 90%)" value="2" delta="Perlu penataan" delta-tone="error" />
    <x-ui.stat-card label="Rak KARANTINA" value="7 unit" delta="1 rak · Q-00-01" delta-tone="warning" icon="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" />
</div>

<div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
    @foreach ($racks as $rack)
        <div class="card p-5">
            <div class="flex items-start justify-between">
                <div>
                    <p class="font-mono text-headline-sm text-text-strong">{{ $rack['code'] }}</p>
                    @php
                        $typeTone = match ($rack['type']) {
                            'QUARANTINE' => 'bg-karantina-bg text-karantina-text border-karantina-border',
                            'RTV_STAGING' => 'bg-titip-bg text-titip-text border-titip-border',
                            'STORAGE' => 'bg-info-bg text-info-text border-info-border',
                            default => 'bg-primary-soft text-primary border-primary-border',
                        };
                    @endphp
                    <span class="mt-2 inline-flex items-center rounded-md border px-2 py-0.5 text-label-sm {{ $typeTone }}">
                        {{ $rack['type'] }}
                    </span>
                </div>
                @if (!$rack['active'])
                    <x-ui.badge-status type="error">Non-aktif</x-ui.badge-status>
                @endif
            </div>

            <div class="mt-4">
                <div class="mb-1 flex items-center justify-between text-body-sm">
                    <span class="text-text-muted">Terisi</span>
                    <span class="font-semibold text-text-strong tabular-nums">{{ $rack['items'] }}/{{ $rack['capacity'] }} unit · {{ $rack['usage'] }}%</span>
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full bg-canvas">
                    <div class="h-2 rounded-full transition-all duration-500
                        {{ $rack['usage'] >= 90 ? 'bg-error-text' : ($rack['usage'] >= 75 ? 'bg-warning-text' : 'bg-primary') }}"
                         style="width: {{ $rack['usage'] }}%"></div>
                </div>
            </div>

            <div class="mt-4 flex items-center justify-between border-t border-border-subtle pt-3">
                <span class="text-label-sm text-text-subtle">Zona {{ $rack['zone'] }} · Level {{ substr($rack['code'], -1) }}</span>
                <div class="flex gap-1">
                    <button type="button" class="btn-ghost h-9 px-3" @click="$store.toast.push('Cetak label rak RK-{{ $rack['code'] }}', 'info')">Label</button>
                    <button type="button" class="btn-ghost h-9 px-3" @click="$store.toast.push('Edit rak dibuka (mock)', 'info')">Edit</button>
                </div>
            </div>
        </div>
    @endforeach
</div>

<x-ui.banner tone="info" class="mt-6">
    <span class="font-semibold">Penonaktifan terproteksi.</span>
    Rak berisi stok tidak dapat dinonaktifkan. pindahkan seluruh unit terlebih dahulu (prompt inline saat mencoba menonaktifkan rak C-03-08).
</x-ui.banner>