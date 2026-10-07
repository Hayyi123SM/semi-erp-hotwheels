@use('App\Support\Format')
@use('App\Enums\CardCondition')
@use('App\Enums\LotStatus')

<x-ui.page-header
    title="Live Stock"
    subtitle="Stok riil per pemilik: mana yang milik toko, mana yang titipan penitip."
    :crumbs="['Inventory', 'Live Stock']"
>
    <x-slot:actions>
        {{-- `data-full-navigation` supaya pengunduhan tidak ditangkap sebagai
             penyegaran tabel di tempat. Kolom HPP ikut atau tidak menyesuaikan
             siapa yang mengunduh, sama seperti di tabel. --}}
        <a href="{{ route('inventory.live-stock.ekspor', request()->query()) }}" class="btn-secondary" data-full-navigation>
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
            Ekspor CSV
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6"
     x-data="{ transfer: null }"
     @live-stock:transfer.window="transfer = $event.detail; $dispatch('open-modal', 'pindah-rak')">

    {{-- Galat validasi dari form pindah rak di bawah: modalnya sudah tertutup
         saat kembali ke halaman ini, jadi pesannya harus terlihat di sini --
         bukan hilang bersama dialog yang gagal. --}}
    @if ($errors->any())
        <x-ui.banner tone="error">{{ $errors->first() }}</x-ui.banner>
    @endif

    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card
            label="Total Unit"
            :value="$summary['units']"
            delta="{{ $summary['lots'].' SKU · '.$summary['consignors'].' penitip' }}"
            delta-tone="info"
            icon="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"
        />
        <x-ui.stat-card
            label="Unit Pribadi"
            :value="$summary['ownUnits']"
            {{-- HPP adalah angka milik toko: angka di sebelahnya yang
                 menerjemahkan unit itu menjadi uang. Tanpa Owner, unitnya boleh
                 terlihat -- berapa banyak barang di rak tidak dirahasiakan --
                 tetapi nilainya tidak. --}}
            :delta="$isOwner ? 'HPP '.Format::rupiah($summary['ownValue']) : null"
            delta-tone="success"
            icon="M5 13l4 4L19 7"
        />
        <x-ui.stat-card
            label="Unit Titipan"
            :value="$summary['consignUnits']"
            delta="{{ $summary['consignors'].' penitip' }}"
            delta-tone="warning"
            icon="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"
        />
        {{-- Empat kartu selalu empat, isi kartu terakhir yang berganti: HPP
             pribadi + nilai titipan bila pembacanya Owner, dan yang berguna
             bagi siapa pun bila tidak. --}}
        @if ($isOwner)
            <x-ui.stat-card
                label="Nilai Stok"
                :value="Format::rupiah($summary['ownValue'] + $summary['consignValue'])"
                delta="HPP pribadi + nilai jual titipan"
                delta-tone="info"
                icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
            />
        @else
            <x-ui.stat-card
                label="Stok Menipis"
                :value="$summary['lowStock']"
                delta="Qty ≤ {{ $lowStockQty }} unit"
                delta-tone="warning"
                icon="M12 9v2m0 4h.01M10.29 3.86L1.82 18.94A2 2 0 003.79 21h16.42a2 2 0 001.97-2.06L14 10.67A2 2 0 0012 9z"
            />
        @endif
    </div>

    <x-ui.section-card>
        <x-ui.data-table :table="$table">
            <x-slot:filters>
                <select name="pemilik" class="select-base filter-select" aria-label="Filter pemilik">
                    <option value="">Semua pemilik</option>
                    <option value="PRIBADI" @selected(request()->query('pemilik') === 'PRIBADI')>Pribadi</option>
                    <option value="TITIPAN" @selected(request()->query('pemilik') === 'TITIPAN')>Titipan</option>
                </select>

                <select name="penitip" class="select-base filter-select" aria-label="Filter penitip">
                    <option value="">Semua penitip</option>
                    @foreach ($consignors as $consignor)
                        <option value="{{ $consignor->id }}" @selected(request()->query('penitip') == $consignor->id)>
                            {{ $consignor->consignor_code }} · {{ $consignor->name }}
                        </option>
                    @endforeach
                </select>

                <select name="seri" class="select-base filter-select" aria-label="Filter seri">
                    <option value="">Semua seri</option>
                    @foreach ($series as $item)
                        <option value="{{ $item->id }}" @selected(request()->query('seri') == $item->id)>{{ $item->name }}</option>
                    @endforeach
                </select>

                <select name="kondisi" class="select-base filter-select" aria-label="Filter kondisi kartu">
                    <option value="">Semua kondisi</option>
                    @foreach (CardCondition::cases() as $option)
                        <option value="{{ $option->value }}" @selected(request()->query('kondisi') === $option->value)>{{ $option->label() }}</option>
                    @endforeach
                </select>

                <select name="rak" class="select-base filter-select" aria-label="Filter rak">
                    <option value="">Semua rak</option>
                    @foreach ($racks as $rack)
                        <option value="{{ $rack->id }}" @selected(request()->query('rak') == $rack->id)>{{ $rack->code }}</option>
                    @endforeach
                </select>

                <select name="status" class="select-base filter-select" aria-label="Filter status">
                    <option value="">Semua status</option>
                    @foreach (LotStatus::cases() as $option)
                        <option value="{{ $option->value }}" @selected(request()->query('status') === $option->value)>{{ \App\Support\Format::statusLabel($option->value) }}</option>
                    @endforeach
                </select>

                <select name="aging" class="select-base filter-select" aria-label="Filter lama menyimpan">
                    <option value="">Semua lama</option>
                    @foreach ([30, 90, 180, 365] as $days)
                        <option value="{{ $days }}" @selected((int) request()->query('aging') === $days)>Masuk ≥ {{ $days }} hari</option>
                    @endforeach
                </select>

                <label class="filter-chip">
                    <input type="checkbox" name="menipis" value="1"
                           class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/30"
                           @checked(request()->boolean('menipis'))>
                    Stok menipis (≤ {{ $lowStockQty }})
                </label>
            </x-slot:filters>
        </x-ui.data-table>
    </x-ui.section-card>

    {{-- Dialog pindah rak. URL aksinya sudah dihitung server untuk baris yang
         diklik, jadi template tidak pernah tahu bentuk route-nya. --}}
    <x-modal name="pindah-rak" focusable>
        <template x-if="transfer">
            <form method="POST" :action="transfer.url">
                @csrf
                <input type="hidden" name="_method" value="PATCH">

                <div class="space-y-5 p-6">
                    <div>
                        <h2 class="text-headline-sm text-text-strong">Pindah Rak</h2>
                        <p class="mt-1 text-body-sm text-text-muted">
                            <span class="font-mono text-text-strong" x-text="transfer.sku"></span>
                            <template x-if="transfer.from">
                                <span> dari rak <span class="font-mono" x-text="transfer.from"></span></span>
                            </template>
                        </p>
                    </div>

                    <x-ui.field label="Rak tujuan" name="rack_id" required>
                        <select id="rack_id" name="rack_id" class="select-base" required>
                            <option value="">Pilih rak…</option>
                            @foreach ($racks as $rack)
                                <option value="{{ $rack->id }}">{{ $rack->code }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>

                    <x-ui.field label="Alasan (opsional)" name="reason" hint="Dicatat pada gerakan stok dan audit.">
                        <input type="text" id="reason" name="reason" maxlength="200" class="input-base" placeholder="Mis. penataan ulang zona B">
                    </x-ui.field>

                    @if ($errors->any())
                        <x-ui.banner tone="error">{{ $errors->first() }}</x-ui.banner>
                    @endif

                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn-secondary" @click="$dispatch('close')">Batal</button>
                        <button type="submit" class="btn-primary">Pindahkan</button>
                    </div>
                </div>
            </form>
        </template>
    </x-modal>
</div>
