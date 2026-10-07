@use('App\Support\Format')

@props(['row' => null, 'value' => null])

{{--
    Ringkasan dan perincian perubahan untuk satu baris audit.

    Ringkasnya selalu tampil; perinciannya dibuka dengan `<details>` bawaan
    browser, bukan Alpine. Alasannya soal barisnya: panel ini hidup di dalam
    sel tabel, dan tabel dirender ulang setiap kali pembaca mengetik di kotak
    pencarian atau mengganti filter. State Alpine yang menempel di baris akan
    ikut hilang di setiap penyegaran itu -- pembaca menekan "lihat perincian",
    lalu saringan yang sedang diketik hilang sendiri. `<details>` tidak menyimpan
    state apa pun, jadi tidak ada yang bisa ikut hilang.

    Nama field ditampilkan apa adanya, dalam huruf monospace, bukan
    diterjemahkan jadi "Harga Jual". Di laporan audit nama kolomnya adalah
    bagian dari bukti: orang yang terbukti harus bisa mengetiknya ke kueri
    dan mendapatkan baris yang sama.
--}}

@if ($row)
    @php
        $changes = $row->diffChanges();
    @endphp

    @if ($changes === [])
        <span class="text-label-sm text-text-subtle">Tidak ada perubahan field</span>
    @else
        <details class="group">
            <summary class="flex cursor-pointer list-none items-center gap-1.5 text-label-sm text-text-muted hover:text-text-strong">
                <span class="min-w-0 truncate">{{ \App\Support\Audit\AuditDiff::summary($changes) }}</span>
                <svg class="h-3.5 w-3.5 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M19 9l-7 7-7-7" />
                </svg>
            </summary>

            <dl class="mt-2 space-y-2">
                @foreach ($changes as $change)
                    @php
                        $before = $change->display($change->before);
                        $after = $change->display($change->after);
                    @endphp
                    <div class="rounded-md border border-border-subtle bg-canvas px-2.5 py-2">
                        <dt class="font-mono text-label-sm text-text-strong">{{ $change->field }}</dt>
                        <dd class="mt-1 grid grid-cols-2 gap-2">
                            <span class="min-w-0">
                                <span class="block text-label-sm text-text-subtle">Sebelum</span>
                                <span class="block break-words font-mono text-body-sm text-error-text">{{ $before }}</span>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-label-sm text-text-subtle">Sesudah</span>
                                <span class="block break-words font-mono text-body-sm text-success-text">{{ $after }}</span>
                            </span>
                        </dd>
                    </div>
                @endforeach
            </dl>
        </details>
    @endif
@else
    <span class="text-label-sm text-text-subtle">{{ Format::EMPTY }}</span>
@endif