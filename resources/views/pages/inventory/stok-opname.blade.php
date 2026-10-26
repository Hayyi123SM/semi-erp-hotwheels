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

@php
    // State yang di-seed ke komponen halaman. Empat daftar ini datang dari
    // server karena semuanya sudah ada di memori sesi; peramban hanya dipakai
    // untuk mengubah dua hal: qty terhitung dan status baris, keduanya lewat
    // balasan tidak memuat ulang. Tidak ada angka sistem di daftar ini, dan
    // `counts`/`statuses` yang di-*seed* di sini justru yang bergerak saat
    // pindai -- bukan nilai yang ditulis ulang dari HTML.
    $opnameRows = $open === null ? [] : $open->rows->map(fn ($row) => ['id' => $row->id, 'sku' => $row->lot?->sku ?? ''])->values()->all();
    $opnameCounts = $open === null ? [] : $open->rows->mapWithKeys(fn ($row) => [$row->id => $row->counted_qty])->all();
    $opnameStatuses = $open === null ? [] : $open->rows->mapWithKeys(fn ($row) => [
        $row->id => [
            'label' => $row->status->label(),
            'type' => Format::statusType($row->status->value),
        ],
    ])->all();
    $opnameUrls = $open === null ? [] : $open->rows->mapWithKeys(fn ($row) => [$row->id => route('inventory.stok-opname.tambah', [$open, $row])])->all();
@endphp

<div class="space-y-6"
     x-data="stokOpname({
         rows: @js($opnameRows),
         counts: @js($opnameCounts),
         statuses: @js($opnameStatuses),
         urls: @js($opnameUrls),
         csrf: @js(csrf_token()),
         reviewTargets: @js($reviewTargets),
         failedReviewId: @js(old('review_line')),
         skuUrl: @js(route('inventory.stok-opname.cari-sku')),
     })"
     {{-- Klik Review hanya membawa id baris; payload-nya diambil dari peta yang
          dibangun server. Detail yang kosong tidak lagi bisa menimpa state, dan
          baris yang gagal divalidasi bisa dibuka ulang dari id yang sama. --}}
     @opname:review.window="openReview($event.detail)">

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
             yang melihat: seluruh modul ini Owner-only, jadi orang yang membuka
             halaman selalu Owner yang bisa menghitung, mengajukan, membatalkan,
             dan memutuskan selisih. --}}
        <div class="card p-6">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="flex items-start gap-4">
                    <div class="relative h-16 w-16 shrink-0">
                        <svg class="h-16 w-16 -rotate-90" viewBox="0 0 64 64" aria-hidden="true">
                            <circle cx="32" cy="32" r="28" class="fill-none stroke-canvas stroke-8"/>
                            <circle cx="32" cy="32" r="28" class="fill-none stroke-primary stroke-8"
                                    stroke-dasharray="175.9"
                                    stroke-dashoffset="{{ number_format(175.9 * (1 - $percent / 100), 2, '.', '') }}"
                                    :stroke-dashoffset="ringOffset"
                                    stroke-linecap="round"/>
                        </svg>
                        <span class="absolute inset-0 flex items-center justify-center text-body-md font-bold text-text-strong tabular-nums" x-text="progressPercent + '%'">{{ $percent }}%</span>
                    </div>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-headline-sm text-text-strong">{{ $open->opname_no }}</h2>
                            <x-ui.badge-status :type="Format::statusType($open->status->value)">
                                {{ Format::statusLabel($open->status->value) }}
                            </x-ui.badge-status>
                        </div>
                        <p class="mt-1 text-label-sm text-text-muted">
                            <span x-text="progressCounted">{{ $counted }}</span> dari {{ $total }} lot terhitung · {{ $open->scope->label() }}@if ($open->scope === OpnameScope::Rack && $open->rack)
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
                            {{-- Tombol mati sampai semua baris terhitung: aturan submit adalah aturan
                                     sesi, bukan per baris. `countingDone` dihitung dari state pindai
                                     (semua baris sudah punya nilai), supaya tombol ikut hidup di tempat
                                     ia mati saat baris terakhir terisi lewat pindai tanpa reload. --}}
                            <button type="submit" class="btn-primary" :disabled="!countingDone">
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
                            Sesi terkunci untuk penghitungan; alasan tiap selisih dibuka saat review.
                            Stok yang bergerak setelah sesi diajukan tidak lagi menggagalkan keputusan --
                            selisih dihitung ulang terhadap keadaan terkini saat Anda memutuskan. Buka
                            ulang hanya bila angka hitungnya sendiri perlu diperbaiki.
                        </p>
                        {{-- Buka ulang adalah jalur koreksi hitungan sebelum ada
                             keputusan, bukan lagi pelarian dari baris basi: gerakan
                             stok sudah diserap otomatis saat review. Service menutup
                             pintu ini begitu satu baris diputuskan. --}}
                        <form method="POST"
                              action="{{ route('inventory.stok-opname.buka-ulang', $open) }}"
                              onsubmit="return confirm('Buka ulang sesi ini? Angka yang sudah dihitung tetap tersimpan; sesi kembali ke penghitungan agar koreksinya bisa dimasukkan, lalu diajukan lagi.');">
                            @csrf
                            <button type="submit" class="btn-secondary">Buka Ulang Sesi</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <x-ui.section-card :title="$blind ? 'Hitung Fisik' : 'Selisih Menunggu Keputusan'">
            <x-slot:actions>
                @if ($blind)
                    {{-- Saat menghitung, kotak ini adalah bar pindai: SKU yang
                         tidak dikenal menampilkan galat, lalu lah ternyata masih
                         mengetik sebuah SKU yang lama dipindai masuk sesi itu --
                         `x-scan` menjaga fokus tetap di sini. --}}
                    <div class="relative w-full sm:w-64">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4.5 w-4.5 -translate-y-1/2 text-text-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                        </svg>
                        <input type="search"
                               x-model.debounce.150ms="q"
                               x-scan="addBySku($event)"
                               placeholder="Pindai atau cari SKU (Enter menambah 1)..."
                               aria-label="Pindai atau cari SKU pada sesi ini"
                               data-allow-focus
                               class="input-base h-9 pl-10 text-body-sm">
                    </div>
                @else
                    {{-- Saat review, cari tetap tersedia tapi tidak lagi berfokus
                         ulang (`x-scan` diambil): fokus harus berpindah ke tombol
                         Review, bukan ditarik kembali ke kotak cari. --}}
                    <div class="relative w-full sm:w-64">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4.5 w-4.5 -translate-y-1/2 text-text-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                        </svg>
                        <input type="search" x-model.debounce.150ms="q"
                               placeholder="Cari SKU..."
                               aria-label="Cari SKU pada sesi ini"
                               data-allow-focus
                               class="input-base h-9 pl-10 text-body-sm">
                    </div>
                @endif
            </x-slot:actions>
            @if ($blind)
                <p class="mb-3 text-body-sm text-error-text" x-show="scanError" x-text="scanError" x-cloak role="alert"></p>
            @endif
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
                                                <div class="inline-flex items-center rounded-lg border border-border-strong bg-surface-lowest" data-allow-focus>
                                                    <button type="button" data-allow-focus
                                                            @click="counts[{{ $row->id }}] = Math.max(0, (counts[{{ $row->id }}] ?? 0) - 1)"
                                                            class="flex h-9 w-9 items-center justify-center rounded-l-lg text-text-muted transition hover:bg-canvas hover:text-text-strong"
                                                            aria-label="Kurangi satu">
                                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/></svg>
                                                    </button>
                                                    <input type="number" name="counted_qty" inputmode="numeric"
                                                           min="0" max="99999" data-allow-focus
                                                           :value="counts[{{ $row->id }}] ?? 0"
                                                           value="{{ $row->counted_qty ?? 0 }}"
                                                           @input="counts[{{ $row->id }}] = Math.max(0, Math.min(99999, Number($event.target.value) || 0))"
                                                           class="h-9 w-12 border-x border-border-subtle bg-transparent text-center text-body-md font-semibold text-text-strong outline-none focus:bg-canvas"
                                                           aria-label="Qty terhitung">
                                                    <button type="button" data-allow-focus
                                                            @click="counts[{{ $row->id }}] = Math.min(99999, (counts[{{ $row->id }}] ?? 0) + 1)"
                                                            class="flex h-9 w-9 items-center justify-center rounded-r-lg text-text-muted transition hover:bg-canvas hover:text-text-strong"
                                                            aria-label="Tambah satu">
                                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                                                    </button>
                                                </div>
                                                <button type="submit" class="btn-secondary h-9 px-3" data-allow-focus>Simpan</button>
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
                                        @if ($blind)
                                            {{-- Selama menghitung, badge ikut bergerak saat pindai selesai
                                                 (status baris bisa berubah di balasan `/tambah`). Sel manis
                                                 memakai `rowStatus*` yang membaca `statuses` hasil pindai,
                                                 bukan nilai yang ditulis sekali saat halaman dimuat. --}}
                                            <span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-label-md"
                                                  :class="rowStatusClass(@js($row->id))">
                                                <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    <path :d="rowStatusIcon(@js($row->id))"/>
                                                </svg>
                                                <span x-text="rowStatusLabel(@js($row->id))">{{ $row->status->label() }}</span>
                                            </span>
                                        @else
                                            <x-ui.badge-status :type="Format::statusType($row->status->value)">
                                                {{ $row->status->label() }}
                                            </x-ui.badge-status>
                                        @endif
                                    </td>

                                    @unless ($blind)
                                        <td class="px-6 py-3 text-right">
                                            {{-- Keputusan selisih adalah pekerjaan Owner, dan seluruh modul ini
                                                 memang hanya sampai ke Owner (grup `['auth', 'owner']` di
                                                 `routes/web.php`). Baris yang sudah terhitung selalu bisa
                                                 direview oleh orang yang membuka halaman. --}}
                                            @if ($row->status === \App\Enums\OpnameLineStatus::Counted)
                                                <button type="button" class="btn-secondary h-9 px-3"
                                                        @click="$dispatch('opname:review', {{ $row->getKey() }})">
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
        {{-- CTA mulai ada satu saja, di header halaman; kartu ini murni
             penjelasan supaya tidak ada dua tombol yang melakukan hal sama di
             satu layar. --}}
        <x-ui.section-card title="Belum Ada Sesi Berjalan">
            <x-ui.empty-state
                title="Tidak ada opname yang sedang terbuka"
                description="Mulai sesi baru lewat tombol di kanan atas untuk menghitung seluruh gudang, satu rak, atau satu SKU. Selama sesi berjalan, angka sistem tidak dikirim ke layar penghitung -- ia muncul bersama selisih hanya setelah sesi diajukan. Hanya satu sesi yang boleh terbuka dalam satu waktu."
            />
        </x-ui.section-card>
    @endif

    <x-ui.section-card title="Riwayat Sesi" :pad="false">
        <x-ui.data-table :table="$historyTable">
            <x-slot:filters>
                <select name="status" class="select-base filter-select" aria-label="Filter status sesi">
                    <option value="">Semua Status</option>
                    @foreach ($historyStatusOptions as $value => $label)
                        <option value="{{ $value }}" @selected(request()->query('status') === (string) $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </x-slot:filters>
        </x-ui.data-table>
    </x-ui.section-card>

    {{-- Dialog mulai sesi. Cakupan memakai `x-model` biasa: opsi lain sengaja
         disembunyikan dengan `x-show` bukan `x-if` supaya inputnya tetap ada di
         DOM dan nilai kosongnya ikut terkirim -- server yang memutuskan apakah
         rak atau SKU wajib, sesuai cakupan yang dipilih. --}}
    <x-modal name="mulai-opname" focusable>
        <form method="POST" action="{{ route('inventory.stok-opname.store') }}"
              @if ($openShiftCount > 0)
                  onsubmit="return confirm('Shift kasir masih terbuka. Penjualan akan terus berjalan selama opname dan gerakan stoknya ikut dihitung saat keputusan. Lanjutkan? Tutup shift kasir lebih dulu bila ingin hitungan benar-benar bersih.');"
              @endif>
            @csrf
            <div class="space-y-5 p-6">
                <div>
                    <h2 class="text-headline-sm text-text-strong">Mulai Sesi Opname</h2>
                    <p class="mt-1 text-body-sm text-text-muted">
                        Angka sistem dicatat sebagai titik awal lalu tidak dikirim ke layar penghitung.
                        Hitung fisik satu per satu, lalu ajukan seluruh sesi untuk direview.
                    </p>
                </div>

                @if ($openShiftCount > 0)
                    {{-- Sesi opname tidak menghentikan penjualan. Peringatan ini
                         membuat konsekuensinya disadari di awal, bukan ditemukan
                         saat notifikasi "stok bergerak" muncul di tengah sesi. --}}
                    <x-ui.banner tone="warning">
                        <strong>Shift kasir masih terbuka.</strong>
                        Penjualan tetap berjalan selama opname; barang yang laku setelah dihitung
                        tidak menggagalkan sesi -- selisihnya disesuaikan otomatis saat keputusan.
                        Untuk hitungan benar-benar bersih, tutup shift kasir lebih dulu.
                    </x-ui.banner>
                @endif

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
                    <x-ui.field label="SKU" name="sku-search" required>
                        <p class="mb-2 text-label-sm text-text-subtle">Cari berdasarkan SKU atau nama produk, lalu pilih satu hasil.</p>
                        <input type="hidden" name="sku" id="sku" :value="selectedSku?.sku ?? ''" :disabled="scope !== 'SKU'">

                        <template x-if="selectedSku">
                            <div class="flex items-start justify-between gap-3 rounded-lg border border-primary/40 bg-primary/5 p-3">
                                <div class="min-w-0">
                                    <p class="font-mono text-label-md font-semibold text-text-strong" x-text="selectedSku.sku"></p>
                                    <p class="mt-0.5 text-body-sm font-medium text-text-strong" x-text="selectedSku.name"></p>
                                    <p class="mt-1 text-label-sm text-text-muted">
                                        <span x-show="selectedSku.series" x-text="selectedSku.series"></span>
                                        <span x-show="selectedSku.series && (selectedSku.color || selectedSku.year)"> · </span>
                                        <span x-show="selectedSku.color" x-text="selectedSku.color"></span>
                                        <span x-show="selectedSku.year" x-text="(selectedSku.color ? ' · ' : '') + selectedSku.year"></span>
                                        <span x-show="selectedSku.rack" x-text="(selectedSku.series || selectedSku.color || selectedSku.year ? ' · ' : '') + 'Rak ' + selectedSku.rack"></span>
                                        <span x-show="!selectedSku.rack" class="text-warning-text" x-text="(selectedSku.series || selectedSku.color || selectedSku.year ? ' · ' : '') + 'Belum ditata'"></span>
                                    </p>
                                </div>
                                <button type="button" class="btn-secondary h-9 shrink-0 px-3" @click="clearSku()">Ganti</button>
                            </div>
                        </template>

                        <div x-show="!selectedSku">
                            <div class="relative">
                                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-text-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <circle cx="11" cy="11" r="7"/>
                                    <path d="M21 21l-4.35-4.35"/>
                                </svg>
                                <input
                                    id="sku-search"
                                    type="text"
                                    x-ref="skuSearch"
                                    x-model="skuTerm"
                                    @input="onSkuInput()"
                                    @keydown.enter.prevent="submitSkuSearch()"
                                    @keydown.arrow-down.prevent="moveSku(1)"
                                    @keydown.arrow-up.prevent="moveSku(-1)"
                                    class="input-base w-full pl-9"
                                    placeholder="mis. Skyline atau CN01-HW-001"
                                    autocomplete="off"
                                    maxlength="255"
                                    role="combobox"
                                    aria-autocomplete="list"
                                    aria-controls="opname-sku-results"
                                    :aria-expanded="skuHasResults"
                                >
                            </div>

                            <p class="mt-2 text-label-sm text-text-subtle" x-show="skuTerm.trim().length > 0 && !skuMinLengthReached" x-cloak>
                                Ketik minimal 2 huruf.
                            </p>

                            <p class="mt-2 text-label-sm text-text-muted" x-show="skuLoading" x-cloak aria-live="polite">
                                Mencari…
                            </p>

                            <p class="mt-2 rounded-lg border border-error-border bg-error-bg px-3 py-2 text-body-sm text-error-text"
                               x-show="skuError" x-text="skuError" x-cloak></p>

                            <p class="mt-2 rounded-lg border border-border-subtle bg-canvas px-3 py-2 text-body-sm text-text-muted"
                               x-show="!skuLoading && !skuError && skuSearched && !skuHasResults" x-cloak>
                                Tidak ada SKU opname yang cocok dengan
                                <span class="font-mono font-semibold text-text-strong" x-text="skuTerm.trim()"></span>.
                            </p>

                            <div id="opname-sku-results"
                                 class="mt-2 max-h-64 overflow-y-auto rounded-lg border border-border-subtle"
                                 x-show="skuHasResults" x-cloak
                                 role="listbox">
                                <template x-for="(item, index) in skuItems" :key="item.sku">
                                    <button type="button"
                                            role="option"
                                            :aria-selected="index === skuActiveIndex"
                                            class="flex w-full items-start justify-between gap-3 border-b border-border-subtle p-3 text-left transition last:border-b-0"
                                            :class="index === skuActiveIndex ? 'bg-primary/5' : 'hover:bg-canvas'"
                                            @click="chooseSku(item)">
                                        <span class="min-w-0">
                                            <span class="block font-mono text-label-md font-semibold text-text-strong" x-text="item.sku"></span>
                                            <span class="mt-0.5 block text-body-sm font-medium text-text-strong" x-text="item.name"></span>
                                            <span class="mt-1 block text-label-sm text-text-muted">
                                                <span x-show="item.series" x-text="item.series"></span>
                                                <span x-show="item.series && (item.color || item.year)"> · </span>
                                                <span x-show="item.color" x-text="item.color"></span>
                                                <span x-show="item.year" x-text="(item.color ? ' · ' : '') + item.year"></span>
                                            </span>
                                        </span>
                                        <span class="shrink-0 text-right text-label-sm text-text-muted">
                                            <span class="block" x-show="item.rack" x-text="'Rak ' + item.rack"></span>
                                            <span class="block text-warning-text" x-show="!item.rack">Belum ditata</span>
                                        </span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </x-ui.field>
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" class="btn-secondary" @click="$dispatch('close')">Batal</button>
                    <button type="submit" class="btn-primary" :disabled="!canStartOpname">Mulai Sesi</button>
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
                {{-- Dibawa serta saat submit agar baris yang gagal divalidasi tahu
                     dirinya sendiri ketika halaman dimuat ulang. --}}
                <input type="hidden" name="review_line" :value="review?.id">
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

                    {{-- Baris ini hanya tampil untuk Owner (seluruh modul
                         Inventory berada di grup `['auth', 'owner']`), jadi
                         keputusan di sini tidak perlu otorisasi tambahan lagi.
                         Alasan tetap wajib diisi untuk persetujuan. --}}
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
