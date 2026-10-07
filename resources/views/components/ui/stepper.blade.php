@props([
    'steps' => [],
    'current' => 1,
])

{{--
    Penanda posisi dalam alur beberapa halaman.

    Dirender server, bukan dengan Alpine, karena halaman-halamannya memang
    dimuat ulang satu per satu: orang memasang berkas, memetakan kolom, lalu
    menyetujui penyimpanan -- tiga permintaan HTTP berbeda dengan tiga
    dokumen berbeda. Stepper yang digerakkan JavaScript akan menampilkan
    posisi yang salah persis saat dokumen yang baru sedang dimuat, dan tanpa
    JavaScript ia hilang sama sekali.

    Langkah yang belum bisa dicapai sengaja tidak punya tautan. Menjadikan
    "Validasi" sebagai tautan dari halaman pemetaan akan mengira orang bahwa
    validasinya sudah pernah dijalankan -- dan itulah yang membuat commit
    menolak.

    Label langkah bisa berupa array `['label' => ..., 'href' => ...]`, sama
    seperti remah roti di `x-ui.page-header`. Kalau sebuah langkah punya
    `href`, berarti halaman itu sudah boleh dicapai dan langkahnya jadi
    tautan; kalau tidak, langkahnya hanya teks.
--}}
@php
    $total = max(1, count($steps));
    $current = max(1, min((int) $current, $total));
@endphp

<nav aria-label="Tahap alur" class="mb-6">
    <ol class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-0">
        @foreach ($steps as $index => $step)
            @php
                $number = $index + 1;
                $node = is_array($step) ? $step : ['label' => $step];
                $label = (string) ($node['label'] ?? '');
                $href = $node['href'] ?? null;

                $done = $number < $current;
                $now = $number === $current;
            @endphp

            <li class="flex items-center gap-3 sm:gap-0">
                {{-- Tiga keadaan harus dibedakan, tanpa tumpang tindih:
                     `$done` adalah langkah sebelum `$current`, jadi tidak mungkin
                    sekaligus benar dengan `$now`. Lingkaran yang sedang berjalan tetap
                     diberi aria-current supaya pembaca layar mengumumkannya. --}}
                @if ($done)
                    <span aria-hidden="true" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
                    </span>
                @elseif ($now)
                    <span aria-current="step" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-on-primary text-label-md font-bold">{{ $number }}</span>
                @else
                    <span aria-hidden="true" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-canvas text-label-md font-bold text-text-subtle">{{ $number }}</span>
                @endif

                <span @class([
                    'text-body-sm font-medium min-w-0',
                    'text-text-strong' => $now,
                    'text-text-muted' => $done,
                    'text-text-subtle' => ! $now && ! $done,
                ])>
                    @if ($href)
                        <a href="{{ $href }}" class="rounded transition hover:text-text-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">{{ $label }}</a>
                    @else
                        {{ $label }}
                    @endif
                </span>

                @unless ($loop->last)
                    <span aria-hidden="true" class="hidden h-px flex-1 bg-border-subtle sm:mx-4 sm:block"></span>
                @endunless
            </li>
        @endforeach
    </ol>
</nav>