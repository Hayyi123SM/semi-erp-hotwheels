@use('App\Support\Format')
@use('App\Enums\AdjustmentReason')
@use('App\Enums\OpnameScope')
@use('App\Enums\OpnameStatus')

<x-ui.page-header
    title="Stok Opname"
    subtitle="Hitung fisik tanpa angka sistem: sesi berjalan, selisih diajukan, keputusan di tangan Owner."
    :crumbs="['Inventory', 'Stok Opname']"
>
    <x-slot:actions>
        @unless ($open)
            <button type="button" class="btn-primary" @click="$dispatch('open-modal', 'mulai-opname')">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v16m8-8H4"/></svg>
                Mulai Sesi Baru
            </button>
        @endunless
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6"
     x-data="{
         review: null,
         scope: 'ALL',
         q: '',
         // Pencarian SKU di sisi klien, bukan lewat query string: yang dicari
         // adalah baris sesi yang sedang terbuka, bukan sesi lain. Kerja
         // penghitung adalah memindai label lalu menemukan barisnya -- bukan
         // memuat ulang halaman yang sudah berisi hitungannya.
         matches(sku) {
             const q = this.q.trim().toUpperCase();
             return q === '' || sku.toUpperCase().includes(q);
         },
     }"
     @opname:review.window="review = $event.detail; $dispatch('open-modal', 'review-baris')">

    {{-- Galat validasi dari empat form di halaman ini. Semuanya mengirim lewat
         `back()`, jadi pesannya sampai di sini setelah dialog tertutup. --}}
    @if ($errors->any())
        <x-ui.banner tone="error">{{ $errors->first() }}</x-ui.banner>
    @endif

    @if ($open !== null)
        @php
            $counted = $open->rows->filter(fn ($row) => $row->counted_qty !== null)->count();
            $total = $open->rows->count();
            $percent = $total > 0 ? (int) round($counted * 100 / $total) : 0;
        @endphp

        {{-- Kartu sesi berjalan. Tombolnya mengikuti keadaan sesi, bukan siapa
             yang melihat: Staff boleh menghitung dan mengajukan, pembatalan
             juga -- yang tidak pernah mereka punya di sini adalah pintu
             persetujuan, karena pintu itu ada di dalam tiap baris selisih. --}}
        <div class="card p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="flex items-start gap-4">
                    <div class="relative h-16 w-16 shrink-0">
                        <svg class="h-16 w-16 -rotate-90" viewBox="0 0 64 64" aria-hidden="true">
                            <circle cx="32" cy="32" r="28" class="fill-none stroke-canvas stroke-8"/>
                            <circle cx="32" cy="32" r="28" class="fill-none stroke-primary stroke-8"
                                    stroke-dasharray="175.9"
                                    stroke-dashoffset="{{ number_format(175.9 * (1 - $percent / 100), 2, '.', '') }}"
                                    stroke-linecap="round"/>
                        </svg>
                        <span class="absolute inset-0 flex items-center justify-center text-body-md font-bold text-text-strong tabular-nums">{{ $percent }}%</span>
                    </div>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-headline-sm text-text-strong">{{ $open->opname_no }}</h2>
                            <x-ui.badge-status :type="Format::statusType($open->status->value)">
                                {{ Format::statusLabel($open->status->value) }}
                            </x-ui.badge-status>
                        </div>
                        <p class="mt-1 text-label-sm text-text-muted">
                            {{ $counted }} dari {{ $total }} lot terhitung · {{ $open->scope->label() }}@if ($open->scope === OpnameScope::Rack && $open->rack)
                                · rak {{ $open->rack->code }}@elseif ($open->scope === OpnameScope::Sku)
                                · {{ $open->scope_value }}@endif
                            · dimulai {{ Format::datetime($open->started_at) }} oleh {{ $open->creator?->name ?? '—' }}
                        </p>
                        <p class="mt-1 text-label-sm text-text-subtle">
                            @if ($blind)
                                Blind count: angka sistem tidak dikirim ke layar ini sampai sesi diajukan.
                            @else
                                Semua baris sudah terhitung. Selisih menunggu keputusan per baris di bawah.
                            @endif
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($blind)
                        <form method="POST" action="{{ route('inventory.stok-opname.ajukan', $open) }}">
                            @csrf
                            {{-- Tombol mati sampai semua baris terhitung: aturan
                                 submit adalah aturan sesi, bukan per baris, jadi
                                 ia dihitung sekali di server dan tampil mati
                                 di sini supaya tidak menggoda. --}}
                            <button type="submit" class="btn-primary" @disabled($counted < $total)>
                                Ajukan ke Owner
                            </button>
                        </form>
                        <form method="POST" action="{{ route('inventory.stok-opname.batal', $open) }}"
                              onsubmit="return confirm('Batalkan sesi ini? Hitungan yang sudah masuk ikut hilang.');">
                            @csrf
                            <button type="submit" class="btn-secondary">Batalkan Sesi</button>
                        </form>
                    @else
                        <p class="text-label-sm text-text-muted">
                            Sesi terkunci untuk penghitungan. Alasan tiap selisih dibuka saat review.
                        </p>
                    @endif
                </div>
            </div>
        </div>

        <x-ui.section-card :title="$blind ? 'Hitung Fisik' : 'Selisih Menunggu Keputusan'">
            <x-slot:actions>
                <div class="relative w-full sm:w-64">
                    <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4.5 w-4.5 -translate-y-1/2 text-text-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                    </svg>
                    <input type="search" x-model.debounce.150ms="q"
                           placeholder="Pindai atau cari SKU..."
                           aria-label="Cari SKU pada sesi ini"
                           data-allow-focus
                           class="input-base h-9 pl-10 text-body-sm">
                </div>
            </x-slot:actions>
            @if ($open->rows->isEmpty())
                <p class="text-body-sm text-text-muted">
                    Tidak ada lot dalam cakupan ini -- tidak ada stok yang cocok dengan rak atau SKU yang dipilih.
                </p>
            @else
                <div class="table-scroll">
                    <table class="w-full min-w-max text-left">
                        <thead class="thead-dense">
                            <tr>
                                <th class="px-6 py-3 font-semibold">SKU</th>
                                <th class="px-6 py-3 font-semibold">Produk</th>
                                <th class="px-6 py-3 font-semibold">Rak</th>
                                @unless ($blind)
                                    <th class="px-6 py-3 text-right font-semibold">Qty Sistem</th>
                                @endunless
                                <th class="px-6 py-3 text-center font-semibold">Qty Terhitung</th>
                                @unless ($blind)
                                    <th class="px-6 py-3 text-right font-semibold">Selisih</th>
                                @endunless
                                <th class="px-6 py-3 font-semibold">Status</th>
                                @unless ($blind)
                                    <th class="px-6 py-3 text-right font-semibold">Aksi</th>
                                @endunless
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-subtle">
                            @foreach ($open->rows as $row)
                                <tr class="row-dense transition hover:bg-canvas"
                                    x-show="matches(@js($row->lot?->sku ?? ''))">
                                    <td class="px-6 py-3 font-mono text-body-sm text-text-strong">{{ $row->lot?->sku ?? '—' }}</td>
                                    <td class="px-6 py-3 text-body-sm text-text-muted">
                                        {{ $row->lot?->product?->name ?? '—' }}
                                        @if ($row->lot?->product?->series)
                                            <span class="text-text-subtle">· {{ $row->lot->product->series->name }}</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 font-mono text-body-sm text-text-muted">{{ $row->lot?->rack?->code ?? '—' }}</td>

                                    @unless ($blind)
                                        <td class="px-6 py-3 text-right font-mono text-body-sm text-text-strong tabular-nums">
                                            {{ Format::number($row->system_qty) }}
                                        </td>
                                    @endunless

                                    <td class="px-6 py-3 text-body-sm">
                                        @if ($blind)
                                            {{-- Bentuk form mengikuti keadaan sesi: sementara menghitung, kolom
                                                 angka sistem memang tidak pernah dipilih di controller, jadi di
                                                 sini pun tidak ada yang bisa ditampilkan. --}}
                                            <form method="POST" action="{{ route('inventory.stok-opname.hitung', [$open, $row]) }}"
                                                  class="flex items-center justify-center gap-2">
                                                @csrf
                                                <x-ui.qty-stepper :min="0" :max="999" :value="$row->counted_qty ?? 0" field="counted_qty"/>
                                                <button type="submit" class="btn-secondary h-9 px-3">Simpan</button>
                                            </form>
                                        @else
                                            <div class="flex flex-col items-center gap-0.5">
                                                <span class="font-mono text-text-strong tabular-nums">{{ Format::number($row->counted_qty) }}</span>
                                                <span class="text-label-sm text-text-subtle">{{ $row->counter?->name ?? '—' }}</span>
                                            </div>
                                        @endif
                                    </td>

                                    @unless ($blind)
                                        <td class="px-6 py-3 text-right font-mono text-body-sm font-semibold tabular-nums
                                                   {{ ($row->diff_qty ?? 0) > 0 ? 'text-success-text' : (($row->diff_qty ?? 0) < 0 ? 'text-error-text' : 'text-text-muted') }}">
                                            {{ ($row->diff_qty ?? 0) > 0 ? '+' : '' }}{{ Format::number($row->diff_qty) }}
                                        </td>
                                    @endunless

                                    <td class="px-6 py-3">
                                        <x-ui.badge-status :type="Format::statusType($row->status->value)">
                                            {{ $row->status->label() }}
                                        </x-ui.badge-status>
                                    </td>

                                    @unless ($blind)
                                        <td class="px-6 py-3 text-right">
                                            @if ($row->status === \App\Enums\OpnameLineStatus::Counted)
                                                <button type="button" class="btn-secondary h-9 px-3"
                                                        @click="review = {
                                                            url: @js(route('inventory.stok-opname.review', [$open, $row])),
                                                            sku: @js($row->lot?->sku ?? ''),
                                                            diff: {{ (int) $row->diff_qty }},
                                                            positive: {{ $row->diff_qty > 0 ? 'true' : 'false' }},
                                                        }; $dispatch('opname:review')">
                                                    Review
                                                </button>
                                            @else
                                                <span class="text-label-sm text-text-subtle">
                                                    @if ($row->status === \App\Enums\OpnameLineStatus::Rejected)
                                                        Ditolak {{ $row->approver?->name !== null ? 'oleh '.$row->approver->name : '' }}
                                                    @elseif ($row->status === \App\Enums\OpnameLineStatus::Approved)
                                                        Disetujui {{ $row->approver?->name !== null ? 'oleh '.$row->approver->name : '' }}
                                                    @else
                                                        Menunggu
                                                    @endif
                                                </span>
                                            @endif
                                        </td>
                                    @endunless
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.section-card>
    @else
        <x-ui.section-card title="Belum Ada Sesi Berjalan">
            <div class="flex flex-col items-start gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-body-sm text-text-muted">
                        Mulai satu sesi opname untuk seluruh gudang, satu rak, atau satu SKU. Saat sesi berjalan,
                        angka sistem tidak dikirim ke layar penghitung -- ia baru muncul bersama selisih ketika sesi
                        diajukan untuk direview.
                    </p>
                    <p class="mt-1 text-label-sm text-text-subtle">
                        Hanya satu sesi yang boleh terbuka dalam satu waktu.
                    </p>
                </div>
                <button type="button" class="btn-primary" @click="$dispatch('open-modal', 'mulai-opname')">Mulai Sesi Baru</button>
            </div>
        </x-ui.section-card>
    @endif

    <x-ui.section-card title="Riwayat Sesi">
        @if ($history->isEmpty())
            <p class="text-body-sm text-text-muted">Belum ada sesi opname yang pernah dijalankan.</p>
        @else
            <div class="table-scroll">
                <table class="w-full min-w-max text-left">
                    <thead class="thead-dense">
                        <tr>
                            <th class="px-6 py-3 font-semibold">No. Sesi</th>
                            <th class="px-6 py-3 font-semibold">Cakupan</th>
                            <th class="px-6 py-3 font-semibold">Dimulai Oleh</th>
                            <th class="px-6 py-3 font-semibold">Mulai</th>
                            <th class="px-6 py-3 font-semibold">Status</th>
                            <th class="px-6 py-3 text-right font-semibold">Baris</th>
                            <th class="px-6 py-3 text-right font-semibold">Selisih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-subtle">
                        @foreach ($history as $item)
                            @php
                                $cakupan = $item->scope->label();
                                if ($item->scope === OpnameScope::Rack && $item->rack !== null) {
                                    $cakupan .= ' · '.$item->rack->code;
                                } elseif ($item->scope === OpnameScope::Sku && $item->scope_value !== null) {
                                    $cakupan .= ' · '.$item->scope_value;
                                }
                            @endphp
                            <tr class="row-dense transition hover:bg-canvas">
                                <td class="px-6 py-3 font-mono text-body-sm text-text-strong">{{ $item->opname_no }}</td>
                                <td class="px-6 py-3 text-body-sm text-text-muted">{{ $cakupan }}</td>
                                <td class="px-6 py-3 text-body-sm text-text-muted">{{ $item->creator?->name ?? '—' }}</td>
                                <td class="px-6 py-3 text-body-sm text-text-muted">{{ Format::datetime($item->started_at) }}</td>
                                <td class="px-6 py-3">
                                    <x-ui.badge-status :type="Format::statusType($item->status->value)">
                                        {{ Format::statusLabel($item->status->value) }}
                                    </x-ui.badge-status>
                                </td>
                                <td class="px-6 py-3 text-right font-mono text-body-sm text-text-strong tabular-nums">{{ $item->lines_count }}</td>
                                <td class="px-6 py-3 text-right font-mono text-body-sm tabular-nums {{ $item->selisih_count > 0 ? 'text-warning-text' : 'text-text-muted' }}">
                                    {{ $item->selisih_count }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.section-card>

    {{-- Dialog mulai sesi. Cakupan memakai `x-model` biasa: opsi lain sengaja
         disembunyikan dengan `x-show` bukan `x-if` supaya inputnya tetap ada di
         DOM dan nilai kosongnya ikut terkirim -- server yang memutuskan apakah
         rak atau SKU wajib, sesuai cakupan yang dipilih. --}}
    <x-modal name="mulai-opname" focusable>
        <form method="POST" action="{{ route('inventory.stok-opname.store') }}">
            @csrf
            <div class="space-y-5 p-6">
                <div>
                    <h2 class="text-headline-sm text-text-strong">Mulai Sesi Opname</h2>
                    <p class="mt-1 text-body-sm text-text-muted">
                        Angka sistem dicatat sebagai titik awal lalu tidak dikirim ke layar penghitung.
                        Hitung fisik satu per satu, lalu ajukan seluruh sesi untuk direview.
                    </p>
                </div>

                <div>
                    <p class="mb-2 text-label-md text-text-muted">Cakupan <span class="text-error-text">*</span></p>
                    <div class="grid gap-2 sm:grid-cols-3">
                        @foreach ($scopes as $option)
                            <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-border-strong bg-surface-lowest p-3 text-body-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                <input type="radio" name="scope" value="{{ $option->value }}"
                                       x-model="scope" class="mt-0.5 h-4 w-4 border-border-strong text-primary focus:ring-primary/30">
                                <span class="text-text-strong">{{ $option->label() }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div x-show="scope === 'RACK'" x-cloak>
                    <x-ui.field label="Rak" name="rack_id" required hint="Lot kosong di rak terpilih ikut dihitung supaya selisihnya terlihat.">
                        <select id="rack_id" name="rack_id" class="select-base">
                            <option value="">Pilih rak…</option>
                            @foreach ($racks as $rack)
                                <option value="{{ $rack->id }}">{{ $rack->code }}@if ($rack->zone) · {{ $rack->zone }}@endif</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                </div>

                <div x-show="scope === 'SKU'" x-cloak>
                    <x-ui.field label="SKU" name="sku" hint="Ditulis persis seperti pada label, mis. CN01-HW-001.">
                        <input type="text" id="sku" name="sku" maxlength="30" class="input-base font-mono uppercase" placeholder="CN01-HW-001" autocomplete="off">
                    </x-ui.field>
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" class="btn-secondary" @click="$dispatch('close')">Batal</button>
                    <button type="submit" class="btn-primary">Mulai Sesi</button>
                </div>
            </div>
        </form>
    </x-modal>

    {{-- Dialog review satu baris selisih. Alasannya dipilih per arah: kekurangan
         hanya bisa berarti hilang/rusak/salah hitung, kelebihan hanya bisa
         berarti ditemukan/salah hitung -- aturan yang sama persis dengan yang
         ditegakkan service, jadi pilihan yang mustahil tidak pernah tersaji. --}}
    <x-modal name="review-baris" focusable>
        <template x-if="review">
            <form method="POST" :action="review.url">
                @csrf
                <div class="space-y-5 p-6">
                    <div>
                        <h2 class="text-headline-sm text-text-strong">Review Selisih</h2>
                        <p class="mt-1 text-body-sm text-text-muted">
                            <span class="font-mono text-text-strong" x-text="review.sku"></span>
                            · selisih
                            <span class="font-mono font-semibold"
                                  :class="review.positive ? 'text-success-text' : 'text-error-text'"
                                  x-text="(review.diff > 0 ? '+' : '') + review.diff"></span>
                            unit terhadap angka sistem
                        </p>
                    </div>

                    <div>
                        <p class="mb-2 text-label-md text-text-muted">Alasan Selisih <span class="text-error-text">*</span></p>
                        <div class="grid gap-2 sm:grid-cols-3">
                            {{-- Mengisi alasan tidak mengubah keputusan. Ia
                                 dipakai sebagai penanda di audit (apa yang
                                 dilaporkan sesi ini saat diajukan) dan terbawa
                                 ke keputusan berikutnya, sehingga staf tidak
                                 perlu menulis dua kali hal yang sama. --}}
                            <div class="contents" x-show="!review.positive">
                                @foreach ($minusReasons as $reason)
                                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-border-strong bg-surface-lowest px-3 py-2 text-body-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                        <input type="radio" name="reason" value="{{ $reason->value }}"
                                               class="h-4 w-4 border-border-strong text-primary focus:ring-primary/30">
                                        <span class="text-text-strong">{{ $reason->label() }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <div class="contents" x-show="review.positive">
                                @foreach ($plusReasons as $reason)
                                    <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-border-strong bg-surface-lowest px-3 py-2 text-body-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                                        <input type="radio" name="reason" value="{{ $reason->value }}"
                                               class="h-4 w-4 border-border-strong text-primary focus:ring-primary/30">
                                        <span class="text-text-strong">{{ $reason->label() }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <p class="mt-1.5 text-label-sm text-text-subtle">
                            Alasan diajukan pemohon saat ia menandai baris ini; keputusan Anda menutupnya.
                        </p>
                    </div>

                    <x-ui.pin-overlay
                        action="inventory.opname-approve"
                        context="Setujui atau tolak selisih opname dan tutup barisnya."
                        confirmText="Minta PIN"
                    />

                    @if ($errors->any())
                        <x-ui.banner tone="error">{{ $errors->first() }}</x-ui.banner>
                    @endif

                    <div class="flex justify-end gap-2">
                        <button type="button" class="btn-secondary" @click="$dispatch('close')">Batal</button>
                        <button type="submit" name="decision" value="REJECT" class="btn-secondary">Tolak</button>
                        <button type="submit" name="decision" value="APPROVE" class="btn-primary">Setujui &amp; Terapkan</button>
                    </div>
                </div>
            </form>
        </template>
    </x-modal>
</div>
