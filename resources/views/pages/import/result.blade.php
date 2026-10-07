@use('App\Support\Format')
@use('App\Support\ImportSteps')

@php
    $moduleRoute = [
        'penitip' => 'master.penitip',
        'katalog' => 'master.katalog-produk',
        'rak' => 'master.lokasi-rak',
        'pengguna' => 'setting.pengguna',
    ][$module];

    $createRoute = [
        'penitip' => 'master.penitip.create',
        'katalog' => 'master.katalog-produk.create',
        'rak' => 'master.lokasi-rak.create',
        'pengguna' => 'setting.pengguna.create',
    ][$module];

    $failed = $result->failureCount();
    $ready = $result->successCount();
@endphp

<x-ui.page-header
    :title="$schema['title']"
    :subtitle="'Impor selesai. ' . $schema['subtitle']"
    :crumbs="['Master Data', 'Impor Excel', 'Selesai']"
>
    <x-slot:actions>
        <a href="{{ route($moduleRoute) }}" class="btn-secondary">Lihat Daftar {{ $schema['title'] }}</a>
        <a href="{{ route($createRoute) }}" class="btn-primary">Impor File Lain</a>
    </x-slot:actions>
</x-ui.page-header>

{{-- Sesi impor sudah dibuang setelah commit, jadi tidak ada satu pun tautan
     kembali ke pemetaan atau validasinya: mengarahkan orang ke sana hanya
     berakhir di 404. --}}
<x-ui.stepper
    :current="ImportSteps::number('result')"
    :steps="ImportSteps::for(null, route($moduleRoute))"
/>

{{-- Angka di halaman ini berasal dari penyimpanan, bukan dari pratinjau.
     Hanya baris yang benar-benar berhasil disimpan yang dihitung "Berhasil";
     sisanya gagal di saat penyimpanan, bukan di saat pemeriksaan. --}}
@if ($summary['success'] > 0 && $failed === 0)
    <x-ui.banner tone="success" class="mb-6">
        <b>{{ Format::number($summary['success']) }} baris berhasil disimpan</b> dari {{ $filename }}.
    </x-ui.banner>
@elseif ($summary['success'] > 0)
    <x-ui.banner tone="warning" class="mb-6">
        <b>{{ Format::number($summary['success']) }} baris berhasil disimpan</b> dari {{ $filename }},
        {{ Format::number($failed) }} baris tidak. Baris yang gagal tidak tersimpan.
    </x-ui.banner>
@else
    <x-ui.banner tone="error" class="mb-6">
        Tidak ada baris yang berhasil disimpan dari {{ $filename }}.
    </x-ui.banner>
@endif

<div class="mb-6 grid gap-5 sm:grid-cols-3">
    <x-ui.stat-card label="Total Baris" :value="$result->total()" delta="Diperiksa" delta-tone="info" />
    <x-ui.stat-card label="Berhasil" :value="$summary['success']" delta="Tersimpan" :delta-tone="$summary['success'] ? 'success' : 'error'" />
    <x-ui.stat-card label="Gagal" :value="$failed" :delta="$failed ? 'Perlu perbaikan' : 'Tidak ada masalah'" :delta-tone="$failed ? 'error' : 'success'" />
</div>

@if ($failed > 0)
    <x-ui.section-card title="Baris yang Tidak Tersimpan">
        <p class="mb-3 text-body-sm text-text-muted">
            Yang gagal di sini adalah bentrok saat penyimpanan -- misalnya kode atau nama yang
            sudah dipakai. Perbaiki di berkas lalu impor ulang baris yang gagal saja.
        </p>

        <div class="overflow-x-auto rounded-xl border border-border-subtle">
            <table class="w-full min-w-[560px] text-left">
                <thead class="thead-dense">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Baris</th>
                        <th class="px-4 py-3 font-semibold">Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-subtle">
                    @foreach ($result->failures as $failure)
                        <tr class="row-dense">
                            <td class="px-4 py-3 font-mono text-sm text-text-strong">#{{ $failure['row'] }}</td>
                            <td class="px-4 py-3">
                                <ul class="list-inside list-disc space-y-0.5 text-label-sm text-error-text">
                                    @foreach ($failure['errors'] as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.section-card>
@endif