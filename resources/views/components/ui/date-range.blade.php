{{--
    Kontrol rentang tanggal untuk halaman laporan.

    Komponen ini merender kontrolnya saja, bukan <form>-nya: pemanggil
    menaruhnya di dalam `x-ui.filter-bar`, yang memegang form dan tombol
    Terapkan. Nilai akhirnya tetap query `from`/`to`, jadi controller dan
    service tidak perlu tahu komponen ini ada.

    Dua input native diberi label terlihat ("Dari"/"Sampai") supaya tidak lagi
    ambigu mana batas awal dan mana batas akhir. Dropdown "Cepat pilih"
    mengisi kedua input itu sekaligus dan tetap menampilkan pilihannya; begitu
    pembaca mengetik tanggal sendiri, labelnya dilepas karena rentang itu sudah
    bukan salah satu preset lagi.

    Kunci daftar di `$presets` harus sama dengan nama di `date-range.js`
    (`today`/`last7`/`last30`/`month`) -- itulah yang dipakai `applyPreset()`.
--}}
@props(['from' => null, 'to' => null])

@php
    // Setiap preset menjawab pertanyaan yang kalau tidak begitu harus dihitung
    // sendiri oleh pembaca ("tanggal berapa seminggu lalu?"). Daftarnya pendek
    // dan tetap, jadi cukup ditulis di sini alih-alih menjadi prop pemanggil.
    $presets = [
        'today' => 'Hari ini',
        'last7' => '7 hari terakhir',
        'last30' => '30 hari terakhir',
        'month' => 'Bulan ini',
    ];
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-wrap items-end gap-3']) }}
     x-data="dateRange({ from: @js($from ?? ''), to: @js($to ?? '') })">
    <label class="flex flex-col gap-1">
        <span class="text-label-sm text-text-subtle">Dari</span>
        <input type="date" name="from" value="{{ $from ?? '' }}" x-model="from" x-on:input="preset = ''"
               :max="to || null" class="input-base text-label-sm">
    </label>

    <label class="flex flex-col gap-1">
        <span class="text-label-sm text-text-subtle">Sampai</span>
        <input type="date" name="to" value="{{ $to ?? '' }}" x-model="to" x-on:input="preset = ''"
               :min="from || null" class="input-base text-label-sm">
    </label>

    <label class="flex flex-col gap-1">
        <span class="text-label-sm text-text-subtle">Cepat pilih</span>
        <select class="select-base filter-select" aria-label="Pilih rentang tanggal cepat"
                x-model="preset" x-on:change="applyPreset($event.target.value)">
            {{-- Placeholder nonaktif: ia boleh tampil saat belum ada pilihan,
                 tapi tidak boleh dipilih kembali dan membuat label kosong di
                 atas rentang yang sudah diisi. --}}
            <option value="" disabled>Pilih rentang…</option>
            @foreach ($presets as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </label>
</div>
