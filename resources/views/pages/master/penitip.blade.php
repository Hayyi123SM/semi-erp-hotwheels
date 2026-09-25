<x-ui.page-header
    title="Data Penitip"
    subtitle="Kelola penitip (consignor) & skema bagi hasil untuk barang titipan."
    :crumbs="['Master Data', 'Data Penitip']"
>
    <x-slot:actions>
        <x-ui.modal title="Tambah Penitip" description="Data penitip aktif (TITIP · CNxx)">
            <x-slot:trigger>
                <button type="button" class="btn-primary">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 4v16m8-8H4"/></svg>
                    Tambah Penitip
                </button>
            </x-slot:trigger>
            <x-slot:panel>
                <form @submit.prevent="$store.toast.push('Penitip tersimpan (mock)', 'success'); open = false" class="space-y-5">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.field label="Nama Lengkap" name="cn_name" required>
                            <input id="cn_name" class="input-base" placeholder="mis. Budi Santoso">
                        </x-ui.field>
                        <x-ui.field label="No. WhatsApp (E.164)" name="cn_phone" required hint="Gunakan format internasional, contoh +62 812-3456-7890">
                            <input id="cn_phone" class="input-base font-mono" placeholder="+62 812-3456-7890">
                        </x-ui.field>
                    </div>

                    <div class="rounded-lg border border-border-subtle bg-canvas p-4">
                        <p class="mb-2 text-label-md text-text-muted">Rekening Penerima (hanya Owner &amp; Manager)</p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-ui.field label="Bank" name="cn_bank">
                                <input id="cn_bank" class="input-base" placeholder="mis. BCA">
                            </x-ui.field>
                            <x-ui.field label="Nomor Rekening" name="cn_account">
                                <input id="cn_account" class="input-base font-mono" placeholder="1234567890">
                            </x-ui.field>
                        </div>
                    </div>

                    <div>
                        <p class="mb-2 text-label-md text-text-muted">Skema Bagi Hasil Default</p>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <x-ui.segmented
                                    :options="[
                                        ['value' => 'percent', 'label' => '%'],
                                        ['value' => 'nett', 'label' => 'Nett'],
                                        ['value' => 'flat', 'label' => 'Flat'],
                                    ]"
                                    selected="percent" />
                            </div>
                            <x-ui.field label="Nilai" name="cn_fee">
                                <input id="cn_fee" class="input-base tabular-nums" placeholder="20">
                            </x-ui.field>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 border-t border-border-subtle pt-4">
                        <button type="button" class="btn-secondary" @click="open = false">Batal</button>
                        <button type="submit" class="btn-primary">Simpan Penitip</button>
                    </div>
                </form>
            </x-slot:panel>
        </x-ui.modal>
    </x-slot:actions>
</x-ui.page-header>

<div class="mb-6 grid gap-5 sm:grid-cols-3">
    <x-ui.stat-card label="Total Penitip" value="{{ count($consignors) }}" delta="3 aktif" delta-tone="info" />
    <x-ui.stat-card label="Saldo Titipan Jatuh Tempo" value="Rp2.512.000" delta="Review mingguan" delta-tone="warning" />
    <x-ui.stat-card label="Settlement DRAFT" value="1" delta="CN01 · siap review" delta-tone="warning" icon="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
</div>

<x-ui.section-card>
    <x-ui.toolbar search-placeholder="Cari penitip / kode / kontak...">
        <x-slot:filters>
            <div class="inline-flex items-center rounded-lg border border-border-subtle bg-surface-lowest">
                <label class="flex cursor-pointer items-center gap-2 px-3 py-2 text-label-md text-text-muted">
                    <input type="checkbox" checked class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/30">
                    Hanya aktif
                </label>
            </div>
        </x-slot:filters>
        <x-slot:actions>
            <span class="text-label-sm text-text-subtle">{{ count($consignors) }} baris · ditampilkan 24 Sep 2026</span>
        </x-slot:actions>
    </x-ui.toolbar>

    <table class="w-full text-left">
        <thead class="thead-dense">
            <tr>
                <th class="px-6 py-3 font-semibold">Kode</th>
                <th class="px-6 py-3 font-semibold">Nama</th>
                <th class="px-6 py-3 font-semibold">Kontak WhatsApp</th>
                <th class="px-6 py-3 font-semibold">Skema</th>
                <th class="px-6 py-3 text-right font-semibold">Saldo Jatuh Tempo</th>
                <th class="px-6 py-3 font-semibold">Status</th>
                <th class="px-6 py-3 text-right font-semibold">Aksi</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
            @foreach ($consignors as $consignor)
                <tr class="row-dense transition hover:bg-canvas">
                    <td class="px-6 py-3 font-mono text-sku text-text-strong">{{ $consignor['code'] }}</td>
                    <td class="px-6 py-3 text-body-md">
                        <p class="font-medium text-text-strong">{{ $consignor['name'] }}</p>
                        <p class="text-label-sm text-text-subtle">Bergabung {{ $consignor['since'] }}</p>
                    </td>
                    <td class="px-6 py-3 font-mono text-body-sm text-text-muted">{{ $consignor['phone'] }}</td>
                    <td class="px-6 py-3">
                        @if ($consignor['scheme'] === 'percent')
                            <x-ui.badge-status type="info">{{ $consignor['fee'] }}% dari harga jual</x-ui.badge-status>
                        @elseif ($consignor['scheme'] === 'nett')
                            <x-ui.badge-status type="info">Nett <b>Rp{{ number_format($consignor['fee'], 0, ',', '.') }}</b>/unit</x-ui.badge-status>
                        @else
                            <x-ui.badge-status type="info">Flat <b>Rp{{ number_format($consignor['fee'], 0, ',', '.') }}</b>/unit</x-ui.badge-status>
                        @endif
                    </td>
                    <td class="px-6 py-3 text-right text-body-md font-semibold text-text-strong tabular-nums">
                        {{ \App\Support\MockData::rupiah($consignor['balance']) }}
                    </td>
                    <td class="px-6 py-3">
                        @if ($consignor['active'])
                            <x-ui.badge-status type="success" dot>Aktif</x-ui.badge-status>
                        @else
                            <x-ui.badge-status type="error">Non-aktif</x-ui.badge-status>
                        @endif
                    </td>
                    <td class="px-6 py-3">
                        <div class="flex justify-end gap-1">
                            <button type="button" class="btn-ghost h-9 px-3" @click="$store.toast.push('Detail penitip dibuka', 'info')">Lihat</button>
                            <button type="button" class="btn-ghost h-9 px-3 text-primary" @click="$store.toast.push('Settlement dibuka untuk {{ $consignor['name'] }}', 'info')">Bagi Hasil</button>
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-ui.section-card>

<x-ui.banner tone="info" class="mt-6">
    <span class="font-semibold">Notifikasi WhatsApp (R-AN)</span>
    Notifikasi otomatis ke penitip diaktifkan untuk skema &gt; Rp0. Template dapat disesuaikan di Pengaturan → Template WhatsApp.
</x-ui.banner>