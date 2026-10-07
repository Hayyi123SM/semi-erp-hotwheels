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

    $dataCount = max(0, count($rows) - $headerLine - 1);
    $previewCount = min(10, count($columns));
@endphp

<x-ui.page-header
    :title="$schema['title']"
    :subtitle="'Memetakan kolom berkas ke field sistem. ' . $schema['subtitle']"
    :crumbs="['Master Data', 'Impor Excel', $schema['title']]"
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
    :current="ImportSteps::number('mapping')"
    :steps="ImportSteps::for(route('master.import.mapping', [$module, $token]), route($moduleRoute))"
/>

    {{-- ============ PEMETAAN ============ --}}
    <div class="mb-5 flex flex-col gap-3 rounded-lg border border-border-subtle bg-canvas px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3 text-body-sm text-text-muted">
            <svg class="h-5 w-5 shrink-0 text-text-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <span><b>{{ $filename }}</b> · {{ Format::number(count($rows)) }} baris terbaca</span>
        </div>
        <a href="{{ route($createRoute) }}" class="btn-ghost h-9 px-3">Ganti File</a>
    </div>

    <x-ui.section-card title="Tentukan Baris Header" class="mb-6">
        <div class="grid gap-5 lg:grid-cols-3">
            <div>
                {{-- Bentuk GET, bukan `window.location.href` di `@change`.

                     Selector ini mengubah halaman -- daftar kolom yang tersedia
                     berubah total begitu baris header bergeser -- dan itu
                     memang harus lewat permintaan baru. Hanya saja `@change`
                     butuh JavaScript: tanpa itu, dropdown-nya diam saja, dan
                     orang mengira pilihannya sudah berlaku.

                     Dipakai `<form method="GET">` dengan tombol submit, bukan
                     `<select>` yang mengirim sendiri: `<select>` yang
                     otomatis mengirim harus JavaScript juga. Yang submitting
                     adalah tombol, jadi tanpa JavaScript orang tetap bisa
                     mengganti baris header -- satu klik lebih banyak, bukan
                     satu langkah yang mustahil. --}}
                <form method="GET" action="{{ route('master.import.mapping', [$module, $token]) }}">
                    <x-ui.field label="Baris berisi judul kolom" name="header" hint="Pilih lalu tekan Terapkan untuk melihat pratinjaunya.">
                        <select id="header" name="header" class="input-base">
                            @foreach (array_keys($rows) as $index)
                                <option value="{{ $index }}" @selected($index === $headerLine)>{{ 'Baris '.($index + 1) }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>

                    <button type="submit" class="btn-secondary mt-2">Terapkan Baris Header</button>
                </form>

                @error('header_row')
                    <p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>
                @enderror
            </div>

            <div class="lg:col-span-2">
                <div class="overflow-x-auto rounded-xl border border-border-subtle">
                    <table class="w-full text-left">
                        <thead class="thead-dense">
                            <tr>
                                @foreach (array_slice($header, 0, $previewCount) as $index => $cell)
                                    <th class="px-3 py-2 text-label-sm">{{ $columns[$index] }}.</th>
                                @endforeach
                            </tr>
                            <tr>
                                @foreach (array_slice($header, 0, $previewCount) as $cell)
                                    <th class="px-3 py-2 text-label-sm font-semibold text-text-strong">{{ Str::limit(trim((string) $cell), 28) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-subtle">
                            @foreach (array_slice($firstRows, 0, 4) as $row)
                                <tr class="row-dense">
                                    @foreach (array_slice($row, 0, $previewCount) as $cell)
                                        <td class="px-3 py-2 text-label-sm text-text-muted">{{ Str::limit(trim((string) $cell), 28) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </x-ui.section-card>

    <form method="POST" action="{{ route('master.import.preview', [$module, $token]) }}" class="space-y-6">
        @csrf
        <input type="hidden" name="header_row" value="{{ $headerLine }}">

        <x-ui.section-card title="Petakan Kolom">
            <p class="mb-4 text-body-sm text-text-muted">
                Pilih kolom berkas untuk setiap field. Kolom yang tidak dipetakan akan <b>dilewati</b>. Nilai default dipakai bila sel kosong.
            </p>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-left">
                    <thead class="thead-dense">
                        <tr>
                            <th class="w-2/5 px-4 py-3 font-semibold">Field Sistem</th>
                            <th class="px-4 py-3 font-semibold">Kolom Berkas</th>
                            <th class="px-4 py-3 font-semibold">Nilai Default (opsional)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-subtle">
                        @foreach ($schema['items'] as $item)
                            <tr class="row-dense">
                                <td class="px-4 py-3">
                                    <p class="text-body-md text-text-strong">
                                        {{ $item['label'] }}
                                        @if (!empty($item['required']))
                                            <span class="text-error-text">*</span>
                                        @endif
                                    </p>
                                    @if (($item['combineOrder'] ?? null) !== null)
                                        <p class="text-label-sm text-text-subtle">Digabung dengan kolom nama lainnya.</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    {{-- Saran tebakan otomatis hanya jadi nilai terpilih AWAL. `old()` tetap di depan supaya pilihan manual yang sudah dibuat orang -- termasuk saat server mengembalikan halaman ini karena ada header_row atau map yang tidak valid -- tidak ditimpa tebakan. --}}
                                    @php
                                        $selectedColumn = old('map.'.$item['key'], $suggested[$item['key']] ?? null);
                                    @endphp
                                    <select name="map[{{ $item['key'] }}]" class="input-base">
                                        <option value="">— Lewati kolom ini —</option>
                                        @foreach ($columns as $index => $column)
                                            <option value="{{ $column }}" @selected($selectedColumn === $column)>
                                                {{ $column }}. {{ Str::limit(trim((string) ($header[$index] ?? null)), 32) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @if (($suggested[$item['key']] ?? null) !== null && old('map.'.$item['key'], null) === null)
                                        <p class="mt-1 text-label-sm text-text-subtle">Terdeteksi otomatis dari judul kolom berkas.</p>
                                    @endif
                                    @error('map.'.$item['key'])
                                        <p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>
                                    @enderror
                                </td>
                                <td class="px-4 py-3">
                                    {{--
                                        One input serving every column type in the schema,
                                        so the mask is chosen from the type rather than
                                        applied to all of them. An amount and a percentage
                                        are the only two that are read as numbers here, and
                                        they are read differently: a percentage takes a
                                        comma for its decimals and carries no grouping.
                                    --}}
                                    @php
                                        $default = is_bool($item['default'] ?? null)
                                            ? ($item['default'] ? 'YA' : 'TIDAK')
                                            : ($item['default'] ?? '');
                                    @endphp
                                    @if ($item['type'] === 'money')
                                        <x-ui.money-input name="defaults[{{ $item['key'] }}]" :id="'default-'.$item['key']" :key="'defaults.'.$item['key']" :value="$default" />
                                    @elseif ($item['type'] === 'percentage')
                                        <x-ui.money-input name="defaults[{{ $item['key'] }}]" :id="'default-'.$item['key']" :key="'defaults.'.$item['key']" :value="$default" mode="rate" />
                                    @else
                                        <input name="defaults[{{ $item['key'] }}]" value="{{ old('defaults.'.$item['key'], $default) }}" class="input-base">
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.section-card>

        <div class="flex flex-wrap items-center justify-end gap-2">
            <a href="{{ route($moduleRoute) }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Validasi {{ Format::number($dataCount) }} Baris</button>
        </div>
    </form>
