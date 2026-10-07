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
    :subtitle="'Periksa hasil sebelum disimpan. ' . $schema['subtitle']"
    :crumbs="['Master Data', 'Impor Excel', 'Validasi']"
>
    <x-slot:actions>
        <a href="{{ route($moduleRoute) }}" class="btn-secondary">Kembali ke Daftar</a>
        <form method="POST" action="{{ route('master.import.cancel', [$module, $token]) }}" class="m-0">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-ghost text-error-text">Batalkan</button>
        </form>
    </x-slot:actions>
</x-ui.page-header>

<x-ui.stepper
    :current="ImportSteps::number('validate')"
    :steps="ImportSteps::for(route('master.import.mapping', [$module, $token]), route($moduleRoute))"
/>

{{-- Halaman ini belum menyentuh database. Yang tampil di bawah adalah hasil
     pemeriksaan, bukan hasil penyimpanan -- dan itu sebabnya jumlahnya boleh
     berbeda dari halaman berikutnya: baris yang lolos di sini masih bisa
     gagal waktu disimpan karena bentrok dengan data yang sudah ada. --}}
<x-ui.banner tone="info" class="mb-6">
    <b>Belum ada data yang disimpan.</b> Berkas <b>{{ $filename }}</b> sudah dibaca dan diperiksa;
    periksa dulu baris yang akan masuk sebelum menyetujuinya.
</x-ui.banner>

<div class="mb-6 grid gap-5 sm:grid-cols-3">
    <x-ui.stat-card label="Total Baris" :value="$result->total()" delta="Diperiksa" delta-tone="info" />
    <x-ui.stat-card label="Siap Disimpan" :value="$ready" delta="Lolos pemeriksaan" :delta-tone="$ready ? 'success' : 'error'" />
    <x-ui.stat-card label="Gagal" :value="$failed" :delta="$failed ? 'Perlu diperbaiki' : 'Tidak ada masalah'" :delta-tone="$failed ? 'error' : 'success'" />
</div>

@if ($failed > 0)
    <x-ui.banner tone="warning" class="mb-6">
        <b>{{ Format::number($failed) }} baris tidak lolos</b> dan akan dilewati kalau Anda menyetujui impor ini.
        Datanya tidak ikut tersimpan. Perbaiki di berkas, atau setel ulang pemetaan kolom untuk mencobanya lagi.
    </x-ui.banner>
@endif

@if ($ready === 0)
    <x-ui.section-card title="Tidak Ada yang Bisa Diimpor">
        <p class="mb-4 text-body-sm text-text-muted">
            Semua {{ Format::number($result->total()) }} baris ditolak. Tidak ada yang bisa disimpan dari berkas ini.
        </p>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-border-subtle pt-4">
            <a href="{{ route('master.import.mapping', [$module, $token]) }}" class="btn-secondary">Ubah Pemetaan Kolom</a>
            <a href="{{ route($createRoute) }}" class="btn-primary">Impor File Lain</a>
        </div>
    </x-ui.section-card>
@else
    <x-ui.section-card title="Baris yang Gagal Diperiksa">
        <p class="mb-3 text-body-sm text-text-muted">
            Nomor baris mengikuti berkas asli, jadi mudah dicocokkan dengan Excel.
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

    <form method="POST" action="{{ route('master.import.commit', [$module, $token]) }}">
        @csrf

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-border-subtle pt-4">
            <div class="text-body-sm text-text-muted">
                @if ($failed > 0)
                    Dengan menyetujui ini, <b>{{ Format::number($ready) }} baris</b> disimpan dan
                    {{ Format::number($failed) }} baris dilewati.
                @else
                    <b>{{ Format::number($ready) }} baris</b> siap disimpan.
                @endif
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2">
                <a href="{{ route('master.import.mapping', [$module, $token]) }}" class="btn-secondary">Kembali ke Pemetaan</a>
                <button type="submit" class="btn-primary">
                    Setujui &amp; Simpan {{ Format::number($ready) }} Baris
                </button>
            </div>
        </div>
    </form>
@endif