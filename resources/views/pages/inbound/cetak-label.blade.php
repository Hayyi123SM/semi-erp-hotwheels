@use('App\Enums\LabelReason')
@use('App\Enums\LabelStatus')

@php
    /*
     * Peta id -> status untuk dipakai Alpine.
     *
     * Tombol aksi hanya menyala kalau SEMUA job yang dipilih punya status yang
     * boleh memakai tombol itu. Peta ini dikirim ke klien supaya bisa dicek
     * sebelum form dikirim, bukan ditolak validasi setelahnya. Sumber
     * kebenarannya tetap `$jobs` yang di-query server.
     */
    $statuses = $jobs->mapWithKeys(fn ($job) => [$job->id => $job->status->value])->all();

    // Select-all memakai daftar ini, bukan kunci peta status. Keduanya sama
    // sekarang, tapi pemisahan ini menjaga kalau nanti antrean dibatasi atau
    // difilter: "pilih semua" harus berarti semua yang ADA DI HALAMAN INI,
    // bukan semua yang pernah masuk ke query.
    $jobIds = $jobs->modelKeys();
@endphp

{{--
    `x-data` ada di pembungkus, bukan di form antrean, karena tiga form hidup di
    halaman ini: antrean, "tandai gagal", dan "cetak ulang". Semuanya butuh
    state Alpine yang sama (job yang dipilih), dan form di dalam form tidak
    dihitung browser sama sekali -- jadi semuanya saudara, bukan bersarang.
--}}
<div x-data="labelQueue(@js($statuses), @js($jobIds))" class="space-y-6">
    <x-ui.page-header
        title="Cetak Label"
        subtitle="Kirim ke printer, konfirmasi setelah label keluar, atau cetak ulang dengan alasan."
        :crumbs="['Inbound', 'Cetak Label']"
    >
        {{--
            Slot `actions` sengaja hanya berisi "Uji Cetak". Tidak ada tombol
            "Cetak Halaman" di sini, dan itu bukan kelalaian.

            `window.print()` mencetak isi tab yang sedang aktif, dan tab ini bukan
            dokumen cetak: halaman ini tidak memuat `label.css`, tidak punya
            elemen `.label-sheet`, dan tidak punya satu pun `class="no-print"`.
            Tekanannya akan mengeluarkan seluruh UI admin ke kertas -- sidebar,
            breadcrumb, tombol, tabel antrean, dan kedua form -- tanpa satu pun
            label.

            Alur cetaknya ada di tempat lain: pilih job, lalu "Tampilkan untuk
            Dicetak" yang mengirim pilihan yang sama ke `inbound.cetak-label.render`
            dengan `formtarget="_blank"`. Server membalas view
            `pages.inbound.label-print`, dan view itu yang memuat `label.css` serta
            sudah punya tombol "Cetak N label"-nya sendiri di panel kendali.
        --}}
        <x-slot:actions>
            {{--
                Uji cetak (FR-IB-25) untuk mengukur gauge printer dan jarak
                antar label. Membuka halaman baru supaya antrean yang sedang
                diisi operator tidak hilang, dan tidak mengirim apa pun ke
                server selain permintaan halaman.
            --}}
            <a class="btn-secondary" href="{{ route('inbound.cetak-label.test-print') }}"
               target="_blank" rel="noopener"
               title="Contoh label terburuk, untuk mengukur gauge printer dan jarak antar label">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3H5a2 2 0 00-2 2v4m18 0V5a2 2 0 00-2-2h-4M9 21H5a2 2 0 01-2-2v-4m18 0v4a2 2 0 01-2 2h-4"/></svg>
                Uji Cetak
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($errors->any())
        <x-ui.banner tone="error">{{ $errors->first() }}</x-ui.banner>
    @endif

    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-card label="Menunggu Dicetak" :value="(string) $summary['queued']" delta="Antrean printer" delta-tone="info" />
        <x-ui.stat-card label="Menunggu Konfirmasi" :value="(string) $summary['sent']" delta="Sudah keluar?" delta-tone="warning" />
        <x-ui.stat-card label="Gagal Dicetak" :value="(string) $summary['failed']" delta="Perlu dicoba ulang" delta-tone="error" />
        <x-ui.stat-card label="Total Job Tampil" :value="(string) $jobs->count()" delta="Maks. 200 terbaru" delta-tone="info" />
    </div>

    {{-- ---------------------------------------------------------- antrean --}}
    <form method="POST" action="{{ route('inbound.cetak-label.store') }}">
        @csrf

        <x-ui.section-card pad="false">
            <div class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-headline-sm text-text-strong">Antrean Label</h2>
                <p class="text-label-sm text-text-subtle">Percetakan lewat dialog browser (Ctrl+P).</p>
            </div>

            <div class="table-scroll">
                <table class="w-full min-w-[920px] text-left">
                    <thead class="thead-dense">
                        <tr>
                            <th class="w-10 px-3 py-3">
                                {{-- Tiga keadaan: tidak ada, sebagian, semua.
                                     `indeterminate` tidak punya direktif Alpine,
                                     jadi dipasang lewat `$refs` di `label-queue.js`. --}}
                                <input type="checkbox"
                                       class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/30"
                                       x-ref="selectAll"
                                       :checked="allSelected()"
                                       :aria-checked="someSelected() ? 'mixed' : allSelected()"
                                       @change="toggleAll()"
                                       :disabled="jobIds.length === 0"
                                       aria-label="Pilih semua job yang tampil">
                            </th>
                            <th class="px-3 py-3 font-semibold">SKU</th>
                            <th class="px-3 py-3 font-semibold">Produk</th>
                            <th class="px-3 py-3 text-center font-semibold">Cetak</th>
                            <th class="px-3 py-3 font-semibold">Alasan</th>
                            <th class="px-3 py-3 font-semibold">Dokumen</th>
                            <th class="px-3 py-3 font-semibold">Status</th>
                            <th class="px-3 py-3 font-semibold">Waktu</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-subtle">
                        @forelse ($jobs as $job)
                            @php
                                $product = $job->lot->product;

                                $productAttrs = collect([
                                    $product->color,
                                    $product->packaging_type?->label(),
                                ])->filter()->implode(' · ');

                                // Harga dan kondisi dari LOT, bukan produk: lot-lah
                                // snapshot yang benar-benar dicetak di label, dan
                                // bisa berbeda antar lot produk yang sama.
                                $condition = collect([
                                    $job->lot->card_condition?->label(),
                                    $job->lot->blister_condition?->label(),
                                ])->filter()->implode(' / ');
                            @endphp
                            <tr class="transition hover:bg-canvas">
                                <td class="px-3 py-2">
                                    <input type="checkbox" name="ids[]" value="{{ $job->id }}"
                                           class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/30"
                                           x-model.number="selected"
                                           {{-- SKU saja tidak cukup untuk membedakan: satu lot
                                                bisa punya beberapa job, misalnya setelah cetak
                                                ulang. --}}
                                           aria-label="Pilih job {{ $job->lot->sku }} #{{ $job->id }}">
                                </td>
                                <td class="px-3 py-2 font-mono text-sku text-text-strong">{{ $job->lot->sku }}</td>
                                <td class="px-3 py-2">
                                    <p class="text-body-sm text-text-strong">
                                        {{ $product->name }}
                                        @if ($product->needs_review)
                                            <x-ui.badge-status type="warning">Perlu Review</x-ui.badge-status>
                                        @endif
                                    </p>
                                    <p class="text-label-sm text-text-subtle">
                                        {{ $product->series?->name ?? 'Tanpa Seri' }}
                                        @if ($product->year)
                                            &middot; {{ $product->year }}
                                        @endif
                                    </p>
                                    @if ($productAttrs !== '')
                                        <p class="text-label-sm text-text-subtle">{{ $productAttrs }}</p>
                                    @endif
                                    <p class="text-label-sm">
                                        @if ($condition !== '')
                                            <span class="text-text-subtle">{{ $condition }} &middot;</span>
                                        @endif
                                        <span class="text-text-strong tabular-nums">{{ \App\Support\Format::rupiah($job->lot->list_price) }}</span>
                                    </p>
                                </td>
                                <td class="px-3 py-2 text-center tabular-nums">{{ $job->copies }}&times;</td>
                                <td class="px-3 py-2 text-label-sm text-text-muted">{{ $job->reason->label() }}</td>
                                <td class="px-3 py-2 font-mono text-label-sm text-text-muted">
                                    {{ $job->lot->consignment?->doc_no ?? 'OW00 &middot; Pribadi' }}
                                </td>
                                <td class="px-3 py-2">
                                    <x-ui.badge-status :type="$job->status->type()">{{ $job->status->label() }}</x-ui.badge-status>
                                    @if ($job->status === LabelStatus::Failed && $job->error_message)
                                        <p class="mt-1 max-w-[16rem] text-label-sm text-error-text">{{ $job->error_message }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-label-sm tabular-nums text-text-muted">
                                    @if ($job->status === LabelStatus::Confirmed)
                                        {{ $job->confirmed_at?->format('d M H:i') }}
                                    @elseif ($job->status === LabelStatus::Failed)
                                        {{ $job->failed_at?->format('d M H:i') }}
                                    @elseif ($job->status === LabelStatus::Sent)
                                        {{ $job->printed_at?->format('d M H:i') }}
                                    @else
                                        {{ $job->created_at->format('d M H:i') }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8">
                                    <x-ui.empty-state
                                        title="Tidak ada label antrean"
                                        description="Commit konsinyasi atau stok pribadi akan otomatis membuat antrean label di sini."
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{--
                Satu form, banyak aksi: `formaction` mengirim pilihan yang sama
                ke URL berbeda tanpa menggandakan input `ids[]`. Tiap tombol
                punya syarat status sendiri, dan yang tidak berlaku
                disembunyikan supaya operator tidak menekan tombol yang pasti
                ditolak.
            --}}
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border-subtle bg-canvas px-6 py-3">
                <p class="text-body-sm text-text-muted">
                    <span x-text="selectedCount()"></span> job terpilih
                    <span
                        class="text-text-subtle"
                        x-show="selectedCount() > 0 && !canPrint() && !canConfirm() && !canRetry()"
                        x-cloak
                    >&mdash; campur status, pilih satu jenis aksi dulu</span>
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" class="btn-secondary"
                            formaction="{{ route('inbound.cetak-label.render') }}"
                            formtarget="_blank"
                            :disabled="!canPreview()">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6M6 18h12m0-9a3 3 0 113 3v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a3 3 0 113-3h12z"/></svg>
                        Tampilkan untuk Dicetak
                    </button>

                    <button type="submit" class="btn-secondary" x-show="canRetry()" x-cloak
                            formaction="{{ route('inbound.cetak-label.retry') }}">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4v5h.6M20 20v-5h-.6M19.4 15a7.6 7.6 0 01-13 3.2M4.6 9A7.6 7.6 0 0118 6.8"/></svg>
                        Kembalikan ke Antrean
                    </button>

                    <button type="button" class="btn-secondary text-error-text" x-show="canConfirm()" x-cloak
                            @click="openFail()">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 18L18 6M6 6l12 12"/></svg>
                        Tandai Gagal
                    </button>

                    <button type="submit" class="btn-secondary" x-show="canPrint()" x-cloak>
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6M6 18h12m0-9a3 3 0 113 3v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a3 3 0 113-3h12z"/></svg>
                        Sudah Dicetak
                    </button>

                    <button type="submit" class="btn-primary" x-show="canConfirm()" x-cloak
                            formaction="{{ route('inbound.cetak-label.confirm') }}">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
                        Konfirmasi Tercetak
                    </button>
                </div>
            </div>
        </x-ui.section-card>
    </form>

    {{-- ------------------------------------------------- form "gagal" --}}
    <form method="POST" action="{{ route('inbound.cetak-label.fail') }}"
          x-ref="failForm" class="hidden" x-show="showFail">
        @csrf
        <template x-for="id in selected" :key="`fail-${id}`">
            <input type="hidden" name="ids[]" :value="id">
        </template>
        <input type="hidden" name="message" :value="failMessage">
    </form>

    <div x-show="showFail" x-cloak
         @keydown.escape.window="closeFail()"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
         role="dialog" aria-modal="true" aria-labelledby="fail-dialog-title">
        <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl" @click.outside="closeFail()">
            <h3 id="fail-dialog-title" class="text-headline-sm text-text-strong">Tandai gagal dicetak</h3>
            <p class="mt-1 text-body-sm text-text-muted">
                <span x-text="selectedCount()"></span> job ditandai gagal dan bisa dicoba lagi.
                Job tidak dihapus, dan jumlah label yang sudah tercetak tidak bertambah.
            </p>

            <label for="fail-message" class="mt-4 block text-label-sm font-semibold text-text-strong">Apa yang salah?</label>
            <textarea id="fail-message" x-model="failMessage" rows="3"
                      class="mt-1 w-full rounded border-border-strong text-body-sm"
                      placeholder="Contoh: kertas habis di tengah cetak"></textarea>
            <p class="mt-1 text-label-sm text-error-text" x-show="failError" x-text="failError" x-cloak></p>

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="closeFail()">Batal</button>
                <button type="button" class="btn-primary" @click="submitFail()">Tandai Gagal</button>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------ cetak ulang (D3) --}}
    <x-ui.section-card>
        <div class="flex flex-col gap-1">
            <h2 class="text-headline-sm text-text-strong">Cetak Ulang</h2>
            <p class="text-body-sm text-text-muted">
                Cari lot berdasarkan SKU, nama produk, nama penitip, atau nomor dokumen.
                Alasan wajib diisi setiap kali mencetak ulang.
            </p>
        </div>

        <form method="GET" action="{{ route('inbound.cetak-label') }}"
              class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="flex-1">
                {{-- `x-ui.field` merender `<label for="q">` dari atribut `name`. Tanpa
                     `id` yang cocok, klik tulisan "Cari lot" tidak memfokuskan input
                     dan pembaca layar memperlakukan keduanya sebagai kontrol terpisah.
                     Karena labelnya sudah terhubung, `aria-label` tidak diperlukan:
                     mengulangi label yang sama hanya membingungkan yang membaca.

                     Ikon magnifier memakai pola `live-stock`, dan `pl-10` menyisakan
                     ruang supaya teks tidak menimpa ikon.

                     Tekan Enter sudah mengirim form secara native -- tanpa Alpine,
                     jadi pencarian tetap jalan kalau JS belum termuat.

                     Baris ini memakai `sm:items-end`, jadi yang disamakan adalah
                     DASAR tiap kolom, bukan tengahnya. Itu benar selama kolom
                     kiri setinggi kolom tombol, yaitu `label` + `input` saja.
                     Karena itu hint TIDAK boleh tetap di dalam `x-ui.field`:
                     `x-ui.field` merender hint di bawah slot, dan satu baris
                     hint itu menambah 18px ke tinggi kolom. Akibatnya dasar
                     kolom kiri turun 18px di bawah tombol, dan keduanya tidak
                     lagi sebanding -- meskipun keduanya sama-sama 44px.

                     Pola yang sama berlaku di baris Alasan/Jumlah label di
                     bawah, dan di `master/katalog-produk` yang sudah benar
                     karena field-nya tanpa hint. --}}
                <x-ui.field label="Cari lot" name="q">
                    <div class="relative">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4.5 w-4.5 -translate-y-1/2 text-text-subtle"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                        </svg>
                        <input type="search" id="q" name="q" value="{{ $query }}" autocomplete="off"
                               class="input-base pl-10"
                               placeholder="CN01-HW-001-U03, Mazda RX-7, Budi, CN-2026-0001">
                    </div>
                </x-ui.field>
            </div>
            <button type="submit" class="btn-secondary">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
                Cari
            </button>
        </form>

        <p class="mt-2 text-label-sm text-text-subtle">Minimal 2 karakter.</p>

        {{-- Tiga kondisi, bukan dua. Tanpa cabang `$query === ''` yang
             eksplisit, form pencarian tampil dengan hasil kosong di bawahnya dan
             operator tidak bisa tahu itu "belum ada yang dicari" atau "dicari tapi
             tidak ketemu" -- dua kondisi yang butuh tindakan berbeda. --}}
        @if ($query === '')
            <x-ui.empty-state class="mt-4 py-10"
                              title="Belum ada pencarian"
                              description="Masukkan SKU, sebagian nama produk, nama penitip, atau nomor dokumen konsinyasi untuk mencari lot yang akan dicetak ulang." />
        @elseif ($matches->isEmpty())
            <x-ui.empty-state class="mt-4 py-10"
                              title="Lot tidak ditemukan"
                              description="Tidak ada lot yang cocok dengan &quot;{{ $query }}&quot;. Coba SKU persis, sebagian nama produk, atau nomor dokumen konsinyasi." />
        @else
            <form method="POST" action="{{ route('inbound.cetak-label.reprint') }}"
                  x-data="reprintForm({
                      endpoint: '{{ route('inbound.cetak-label.reprint') }}',
                      redirectTo: '{{ route('inbound.cetak-label') }}',
                      context: 'inventory.label-overprint',
                  }, @js($matches->modelKeys()))"
                  @submit="window.pin ? submit($event) : null"
                  class="mt-4 space-y-4">
                @csrf

                {{-- Disimpan di DOM supaya form tetap bisa dikirim tanpa Alpine.
                     `reprint-form.js` tetap menulis token dari state-nya sendiri,
                     karena `x-model` baru sampai ke sini setelah flush. --}}
                <input type="hidden" name="pin_token" x-model="token" value="">

                <div class="table-scroll rounded border border-border-subtle">
                    <table class="w-full min-w-[760px] text-left">
                        <thead class="thead-dense">
                            <tr>
                                <th class="w-10 px-3 py-3">
                                    {{-- Tiga keadaan, sama seperti header antrean.
                                         `indeterminate` disetel dari
                                         `reprint-form.js` lewat `$refs`. --}}
                                    <input type="checkbox"
                                           class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/30"
                                           x-ref="selectAll"
                                           :checked="allSelected()"
                                           :aria-checked="someSelected() ? 'mixed' : allSelected()"
                                           @change="toggleAll()"
                                           :disabled="lotIds.length === 0"
                                           aria-label="Pilih semua lot yang tampil">
                                </th>
                                <th class="px-3 py-3 font-semibold">SKU</th>
                                <th class="px-3 py-3 font-semibold">Produk</th>
                                <th class="px-3 py-3 font-semibold">Dokumen</th>
                                <th class="px-3 py-3 text-right font-semibold">Label</th>
                                <th class="px-3 py-3 text-right font-semibold">Sisa</th>
                                <th class="px-3 py-3 text-right font-semibold">Cetak Ulang</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-subtle">
                            @foreach ($matches as $match)
                                @php
                                    // Sisa yang benar-benar bisa dipakai, bukan
                                    // `qty_received - labels_printed`: cetakan yang
                                    // masih di printer juga akan menempel, dan
                                    // kalau tidak dihitung di sini, operator akan
                                    // melihat lot yang sudah penuh masih kelihatan
                                    // lega.
                                    $inFlight = (int) $match->in_flight_labels;
                                    $headroom = $match->qty_received - $match->labels_printed - $inFlight;
                                    $dailyLeft = \App\Services\Inventory\ReprintLimit::DAILY_STAFF_LIMIT - $match->reprints_today;

                                    // Kondisi dan harga dari lot, sama seperti di
                                    // antrean: itulah yang dicetak di label.
                                    $condition = collect([
                                        $match->card_condition?->label(),
                                        $match->blister_condition?->label(),
                                    ])->filter()->implode(' / ');

                                    $detail = collect([
                                        $match->product->color,
                                        $condition,
                                    ])->filter()->implode(' · ');
                                @endphp
                                <tr class="transition hover:bg-canvas">
                                    <td class="px-3 py-2">
                                        <input type="checkbox" name="lot_ids[]" value="{{ $match->id }}"
                                               class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/30"
                                               x-model.number="selected"
                                               aria-label="Pilih lot {{ $match->sku }}">
                                    </td>
                                    <td class="px-3 py-2 font-mono text-sku text-text-strong">{{ $match->sku }}</td>
                                    <td class="px-3 py-2">
                                        <p class="text-body-sm text-text-strong">
                                            {{ $match->product->name }}
                                            @if ($match->pending_labels_count > 0)
                                                <x-ui.badge-status type="info">{{ $match->pending_labels_count }} menunggu</x-ui.badge-status>
                                            @endif
                                        </p>
                                        <p class="text-label-sm text-text-subtle">
                                            @if ($detail !== '')
                                                {{ $detail }} &middot;
                                            @endif
                                            <span class="text-text-strong tabular-nums">{{ \App\Support\Format::rupiah($match->list_price) }}</span>
                                        </p>
                                    </td>
                                    <td class="px-3 py-2 font-mono text-label-sm text-text-muted">
                                        {{ $match->consignment?->doc_no ?? 'OW00 &middot; Pribadi' }}
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums text-label-sm text-text-muted">
                                        {{ $match->labels_printed }} / {{ $match->qty_received }}
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums text-label-sm">
                                        @if ($headroom > 0)
                                            <span class="text-text-muted">sisa {{ $headroom }}</span>
                                        @else
                                            {{-- Penuh. Operator boleh tetap mencetak,
                                                 tapi hanya dengan PIN Owner, jadi
                                                 badge ini harus jujur: bukan
                                                 "tidak boleh", tapi "butuh izin". --}}
                                            <x-ui.badge-status type="warning">penuh &middot; butuh PIN</x-ui.badge-status>
                                        @endif
                                        @if ($inFlight > 0)
                                            <p class="text-label-sm text-text-muted">{{ $inFlight }} masih di printer</p>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums text-label-sm text-text-muted">
                                        {{ $match->reprint_count }}&times;
                                        @if ($dailyLeft <= 0)
                                            <x-ui.badge-status type="warning">jatah hari ini habis</x-ui.badge-status>
                                        @else
                                            <p class="text-label-sm text-text-muted">{{ $dailyLeft }}&times; lagi hari ini</p>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Grid, bukan `flex`. Pada flex, lebar tiap kolom ikut
                     mengembang mengikuti isinya, sehingga hint yang panjang di
                     kolom "Jumlah label" melebarkan kolom itu dan select
                     "Alasan" terlihat sempit: dua kontrol dengan lebar berbeda
                     dalam satu baris. Di grid, lebar tiap kolom ditetapkan
                     terpisah dari isinya, jadi keduanya konsisten.

                     Sumbu yang disamakan adalah DASAR (`sm:items-end`), bukan
                     tengah. Kolom field tingginya `label` + `input` = 66px,
                     sementara tombol 44px. `items-center` akan menaikkan tombol
                     11px ke atas input, karena yang disejajarkan adalah pusat
                     keduanya, bukan dua bagian yang sama-sama setinggi.

                     Hint kedua dipindah ke paragraf di bawah baris ini. Bukan
                     hanya supaya tidak mempengerakkan kolom, tapi karena hint
                     itu menjelaskan kedua field sekaligus -- jadi satu blok
                     yang dibaca berurutan lebih jelas daripada dua potongan
                     yang masing-masing setengah jalan. --}}
                <div class="sm:grid sm:grid-cols-[minmax(0,18rem)_7.5rem_auto] sm:items-end sm:gap-3">
                    <x-ui.field label="Alasan" name="reason" required>
                        <select id="reason" name="reason" required class="select-base">
                            <option value="">Pilih alasan&hellip;</option>
                            @foreach (LabelReason::cases() as $reason)
                                @continue(! $reason->isReprint())
                                <option value="{{ $reason->value }}"
                                    @selected(old('reason') === $reason->value)>{{ $reason->label() }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>

                    <x-ui.field label="Jumlah label" name="copies">
                        <input id="copies" type="number" name="copies" value="{{ old('copies', 1) }}"
                               min="1" max="200" required class="input-base tabular-nums">
                    </x-ui.field>

                    <button type="submit" class="btn-primary" :disabled="busy || ! canSubmit()">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4v5h.6M20 20v-5h-.6M19.4 15a7.6 7.6 0 01-13 3.2M4.6 9A7.6 7.6 0 0118 6.8"/></svg>
                        {{-- `x-text` menulis teks sebagai konten, bukan HTML, jadi
                             `&hellip;` di sini akan tampil apa adanya. --}}
                        <span x-text="busy ? 'Mengirim…' : 'Buat Job Cetak Ulang'">Buat Job Cetak Ulang</span>
                    </button>
                </div>

                <p class="text-label-sm text-text-subtle">
                    Alasan wajib dan tercatat di audit log bersama nama petugas. Jumlah label berlaku
                    per lot yang dipilih &mdash; melebihi sisa lot atau jatah 3&times; per hari perlu PIN Owner.
                    <span x-show="selectedCount() > 0" x-cloak>
                        Saat ini <span class="font-medium text-text-strong" x-text="selectedCount()"></span>
                        lot dipilih.
                    </span>
                </p>

                {{-- Penolakan yang belum sempat tampil sebagai pesan session --
                     sudah pindah ke sini, karena form tidak pernah dimuat ulang
                     supaya lot yang dipilih tidak hilang dari checkbox. --}}
                <p x-show="error !== ''" x-text="error" x-cloak
                   class="rounded border border-danger/30 bg-danger/5 px-3 py-2 text-body-sm text-danger"></p>
            </form>
        @endif
    </x-ui.section-card>
</div>
