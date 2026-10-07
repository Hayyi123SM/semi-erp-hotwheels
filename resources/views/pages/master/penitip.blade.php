@use('App\Support\Format')

<x-ui.page-header
    title="Data Penitip"
    subtitle="Kelola penitip (consignor), skema bagi hasil & saldo titipan."
    :crumbs="['Master Data', 'Data Penitip']"
/>

{{-- Kartu saldo hanya untuk Owner. Kolom "Saldo Jatuh Tempo" di tabel sudah
     disembunyikan dari Staff, tapi agregatnya bocor lewat kartu ini: mengetik
     angka yang sama di tempat yang berbeda bukan menyembunyikan, hanya memindahkan.
     Grid ikut menyusut supaya Staff tidak mendapat tiga kolom dengan satu
     ruang kosong di ujungnya. --}}
<div class="mb-6 grid gap-5 {{ $canManage ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }}">
    <x-ui.stat-card label="Total Penitip" :value="$totalConsignors" delta="{{ Format::number($activeCount) }} aktif" delta-tone="info" />
    @if ($canManage)
        <x-ui.stat-card label="Saldo Titipan Jatuh Tempo" :value="Format::rupiah($dueTotal)" delta="Rekap mingguan" delta-tone="warning" />
    @endif
    <x-ui.stat-card label="Skema Dominan" value="PERCENTAGE" delta="Sesuaikan di halaman edit" delta-tone="info" />
</div>

<x-ui.section-card>
    <x-ui.data-table :table="$table">
        <x-slot:filters>
            <select name="status" class="select-base filter-select" aria-label="Filter status penitip">
                <option value="">Semua status</option>
                @foreach (\App\Enums\ConsignorStatus::cases() as $option)
                    <option value="{{ $option->value }}" @selected(request()->query('status') === $option->value)>
                        {{ \App\Support\Format::statusLabel($option->value) }}
                    </option>
                @endforeach
            </select>
        </x-slot:filters>
    </x-ui.data-table>
</x-ui.section-card>

<x-ui.banner tone="info" class="mt-6">
    <span class="font-semibold">Notifikasi WhatsApp (R-AN)</span>
    Notifikasi otomatis ke penitip aktif untuk skema &gt; Rp0. Template dapat disesuaikan di Pengaturan &rarr; Template WhatsApp.
</x-ui.banner>
