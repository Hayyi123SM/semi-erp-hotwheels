@use('App\Services\Import\ImportResult')
@use('App\Support\Format')
@use('App\Support\ImportSteps')

@php
    $crumbGroup = \App\Support\ImportSchemas::crumb($module);
    $moduleRoute = \App\Support\ImportSchemas::moduleRoute($module);
    $createRoute = \App\Support\ImportSchemas::createRoute($module);

    $failed = $result->failureCount();
    $ready = $result->successCount();

    // Baris yang disorot di bawah: hasil penggabungan, kelompok yang tidak
    // disatu karena isinya beda, dan baris yang menunjuk duplikat katalog.
    // Berkas biasa daftarnya kosong dan tabelnya tak tampil.
    $notableRows = $result->notableRows();
    $mergeCount = $result->warningCount(ImportResult::KIND_MERGE);
    $duplicateCount = $result->warningCount(ImportResult::KIND_DUPLICATE);
    $unmergeableRows = $result->warnedRowCount(ImportResult::KIND_MERGE);
    $duplicateRows = $result->warnedRowCount(ImportResult::KIND_DUPLICATE);
    $mergedEntries = count(array_filter(
        $notableRows,
        static fn (array $entry): bool => $entry['messages'] === [],
    ));
@endphp

<x-ui.page-header
    :title="$schema['title']"
    :subtitle="'Periksa hasil sebelum disimpan. ' . $schema['subtitle']"
    :crumbs="[$crumbGroup, 'Impor Excel', 'Validasi']"
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

@if ($mergeCount > 0)
    <x-ui.banner tone="warning" class="mb-6">
        <b>{{ Format::number($unmergeableRows) }} baris tidak bisa digabung</b> karena isinya berbeda
        dari kelompoknya. Datanya tetap tersimpan terpisah -- periksa kolom keterangan di bawah.
    </x-ui.banner>
@elseif ($mergedEntries > 0)
    <x-ui.banner tone="info" class="mb-6">
        Beberapa baris disatukan menjadi <b>{{ Format::number($mergedEntries) }} kelompok total qty</b>
        karena isinya sama. Nomor baris di bawah mengacu ke berkas asli Anda.
    </x-ui.banner>
@endif

@if ($duplicateCount > 0)
    <x-ui.banner tone="info" class="mb-6">
        <b>{{ Format::number($duplicateRows) }} baris menunjuk ke lebih dari satu produk katalog.</b>
        Hanya satu id yang dipakai sebagai wakil; alasannya ada di kolom keterangan di bawah.
    </x-ui.banner>
@endif

@if ($notableRows !== [])
    <x-ui.section-card title="Baris Digabung dan Peringatan">
        <p class="mb-3 text-body-sm text-text-muted">
            Nomor baris mengikuti berkas asli. Bila satu baris menyebut beberapa nomor, berarti baris-baris
            itu disatukan menjadi satu penyimpanan dengan total qty. Baris yang isinya berbeda tetap
            tersendiri, lengkap dengan alasannya.
        </p>

        <div class="overflow-x-auto rounded-xl border border-border-subtle">
            <table class="w-full min-w-[640px] text-left">
                <thead class="thead-dense">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Baris Asal</th>
                        <th class="px-4 py-3 font-semibold">Produk</th>
                        <th class="px-4 py-3 font-semibold text-right">Qty</th>
                        <th class="px-4 py-3 font-semibold">Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-subtle">
                    @foreach ($notableRows as $entry)
                        <tr class="row-dense">
                            <td class="px-4 py-3 font-mono text-sm text-text-strong">{{ $entry['source'] }}</td>
                            <td class="px-4 py-3 text-label-sm text-text-strong">{{ Format::text($entry['label']) }}</td>
                            <td class="px-4 py-3 text-right font-mono text-sm text-text-strong">{{ Format::number($entry['qty']) }}</td>
                            <td class="px-4 py-3">
                                @if ($entry['messages'] === [])
                                    <span class="text-label-sm text-text-muted">Disatukan jadi total qty</span>
                                @else
                                    <ul @class([
                                        'list-inside list-disc space-y-0.5 text-label-sm',
                                        'text-info-text' => in_array(ImportResult::KIND_DUPLICATE, $entry['kinds'], true),
                                        'text-warning-text' => ! in_array(ImportResult::KIND_DUPLICATE, $entry['kinds'], true),
                                    ])>
                                        @foreach ($entry['messages'] as $message)
                                            <li>{{ $message }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.section-card>
@endif

@if ($failed > 0)
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