@use('App\Support\Format')

@php
    /**
     * Satu nota, dipilih menjadi empat blok yang urutannya sama dengan struk
     * kertasnya: kepala, barang, uang, catatan. Kasir membuka halaman ini untuk
     * satu alasan -- membandingkan angka di layar dengan angka yang barusan
     * keluar dari printer -- jadi urutannya mengikuti kertas, bukan urutan kolom
     * di tabel riwayat.
     *
     * Halaman ini tidak punya tombol sama sekali selain "Kembali". Membatalkan
     * dan mencetak ulang menulis nota, dan keduanya layak dibicarakan terpisah.
     */
    $received = (int) $sale->payments->sum('amount');
    $device = $sale->device_id ?? Format::EMPTY;
    $soldAt = $sale->sold_at;
@endphp

<x-ui.page-header
    title="Nota {{ $sale->receipt_no }}"
    subtitle="{{ $sale->cashier?->name ?? 'Sistem' }} · {{ $soldAt !== null ? Format::datetime($soldAt) : 'Belum tercatat di server' }}"
    :crumbs="[
        ['label' => 'POS / Kasir', 'href' => route('pos.kasir')],
        ['label' => 'Riwayat Transaksi', 'href' => route('pos.riwayat')],
        $sale->receipt_no,
    ]"
>
    <x-slot:actions>
        <a href="{{ route('pos.riwayat') }}" class="btn-secondary">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5m7-7l-7 7 7 7"/></svg>
            Kembali ke Riwayat
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    @if ($sale->status === \App\Enums\SaleStatus::Voided)
        {{--
            Nota yang dibatalkan tetap terbuka, bukan disembunyikan.

            Angka di halaman ini adalah angka yang pernah dilayani pelanggan, dan
            menghapusnya akan membuat riwayat tidak bisa menjelaskan mengapa uang
            di laci berbeda dari jumlah penjualannya. Yang berubah hanya maknanya,
            dan makna itu harus terbaca sebelum angkanya.
        --}}
        <x-ui.banner tone="error">
            <span class="font-semibold">Nota dibatalkan</span>
            {{ $sale->void_reason ?? 'Tanpa alasan tertulis' }}
            @if ($sale->voided_at)
                &middot; {{ Format::datetime($sale->voided_at) }}
            @endif
            <span class="mt-1 block text-label-sm opacity-80">
                Angka di bawah adalah catatan penjualan yang pernah terjadi, bukan penjualan yang masih berlaku.
            </span>
        </x-ui.banner>
    @elseif ($sale->flagSummary())
        <x-ui.banner tone="warning">
            <span class="font-semibold">Perlu ditinjau</span>
            {{ $sale->flagSummary() }}.
            <span class="mt-1 block text-label-sm opacity-80">
                Uang dan barang sudah tercatat; yang belum pasti hanya apakah angka di nota ini masih berlaku.
            </span>
        </x-ui.banner>
    @endif

    <div class="card card-pad">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-label-md text-text-muted">Nomor Nota</p>
                {{-- `select-all`: nomor inilah yang disalin ke chat atau ke laporan,
                     dan satu klik untuk memilih semuanya menghemat gerakan yang
                     berulang ratusan kali sehari. Tidak dibungkus tautan -- yang
                     dibutuhkan di sini teksnya, bukan pintunya. --}}
                <p class="select-all font-mono text-body-md font-medium text-text-strong">{{ $sale->receipt_no }}</p>
            </div>
            <div>
                <p class="text-label-md text-text-muted">Status</p>
                <p class="mt-0.5">
                    <x-ui.badge-status :type="$sale->status->type()">{{ $sale->status->label() }}</x-ui.badge-status>
                </p>
                @if ($sale->flagSummary())
                    <p class="mt-1 text-label-sm text-text-subtle">{{ $sale->flagSummary() }}</p>
                @endif
            </div>
            <div>
                <p class="text-label-md text-text-muted">Waktu</p>
                <p class="text-body-md font-medium text-text-strong">
                    {{ $soldAt !== null ? Format::datetime($soldAt) : 'Belum tercatat' }}
                </p>
                @if ($sale->sold_at_client && $soldAt === null)
                    <p class="text-label-sm text-text-subtle">Dikirim perangkat {{ Format::datetime($sale->sold_at_client) }}</p>
                @endif
            </div>
            <div>
                <p class="text-label-md text-text-muted">Kasir</p>
                <p class="text-body-md font-medium text-text-strong">{{ $sale->cashier?->name ?? 'Sistem' }}</p>
            </div>
            <div>
                <p class="text-label-md text-text-muted">Shift</p>
                <p class="text-body-md font-medium text-text-strong">#{{ $sale->shift_id ?? '-' }}</p>
                @if ($sale->shift?->opened_at)
                    <p class="text-label-sm text-text-subtle">Dibuka {{ Format::datetime($sale->shift->opened_at) }}</p>
                @endif
            </div>
            <div>
                <p class="text-label-md text-text-muted">Device</p>
                <p class="select-all font-mono text-body-md font-medium text-text-strong">{{ $device }}</p>
            </div>
            <div>
                <p class="text-label-md text-text-muted">Metode</p>
                <p class="text-body-md font-medium text-text-strong">{{ $sale->methodSummary() }}</p>
            </div>
            <div>
                <p class="text-label-md text-text-muted">Sinkron</p>
                <p class="text-body-md font-medium text-text-strong">
                    {{ $sale->synced_at !== null ? 'Sudah' : 'Belum' }}
                </p>
                @if ($sale->synced_at)
                    <p class="text-label-sm text-text-subtle">{{ Format::datetime($sale->synced_at) }}</p>
                @endif
            </div>
        </div>
    </div>

    <x-ui.section-card pad="false">
        <div class="px-6 py-4">
            <h2 class="text-headline-sm text-text-strong">Barang ({{ $sale->items->count() }} baris)</h2>
        </div>
        <div class="table-scroll">
            <table class="w-full min-w-[980px] text-left">
                <thead class="thead-dense">
                    <tr>
                        <th class="px-3 py-3 font-semibold">SKU</th>
                        <th class="px-3 py-3 font-semibold">Produk</th>
                        <th class="px-3 py-3 text-center font-semibold">Qty</th>
                        <th class="px-3 py-3 text-right font-semibold">Harga</th>
                        <th class="px-3 py-3 text-right font-semibold">Total</th>
                        <th class="px-3 py-3 font-semibold">Skema</th>
                        <th class="px-3 py-3 text-right font-semibold">Fee Toko</th>
                        <th class="px-3 py-3 text-right font-semibold">Hak Penitip</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-subtle">
                    @forelse ($sale->items as $item)
                        @php
                            // `sell_price` sudah bersih dari diskon (`P = L - diskon`),
                            // jadi total barisnya cukup dikalikan qty. Diskonnya
                            // sendiri ditampilkan sebagai catatan, bukan dijumlahkan
                            // lagi -- menguranginya dua kali membuat total nota tidak
                            // pernah cocok dengan pembayarannya.
                            $lineTotal = $item->sell_price * $item->qty;

                            $scheme = match ($item->scheme_type?->value) {
                                \App\Enums\SchemeType::Percentage->value => Format::rate($item->scheme_rate).' %',
                                \App\Enums\SchemeType::Nett->value => 'Nett '.Format::rupiah($item->scheme_amount),
                                \App\Enums\SchemeType::Flat->value => 'Flat '.Format::rupiah($item->scheme_amount),
                                default => 'Milik Toko',
                            };
                        @endphp
                        <tr class="transition hover:bg-canvas">
                            <td class="px-3 py-2 font-mono text-sku text-text-strong">
                                {{ $item->sku }}
                                @if ($item->owner_code)
                                    <span class="mt-0.5 block font-mono text-label-sm text-text-subtle">{{ $item->owner_code }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-body-sm text-text-strong">
                                {{ $item->lot?->product?->name ?? $item->sku }}
                                @if ($item->lot?->product?->series)
                                    <span class="mt-0.5 block text-label-sm text-text-subtle">{{ $item->lot->product->series->name }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-center tabular-nums">{{ $item->qty }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ Format::rupiah($item->sell_price) }}
                                @if ($item->discount > 0)
                                    <span class="mt-0.5 block text-label-sm text-text-subtle">
                                        <s>{{ Format::rupiah($item->list_price) }}</s>
                                        &middot; diskon {{ Format::rupiah($item->discount) }}/unit
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums">{{ Format::rupiah($lineTotal) }}</td>
                            <td class="px-3 py-2 text-label-sm text-text-muted">{{ $scheme }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ Format::rupiah($item->fee_toko) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{-- Hak penitip null untuk barang milik toko sendiri, bukan nol.
                                     Menampilkan "Rp 0" di sini akan membuat barang toko
                                     terlihat seperti barang titipan yang bagi hasilnya nol,
                                     dan dua itu berbeda pada settlement berikutnya. --}}
                                {{ $item->hak_penitip === null ? Format::EMPTY : Format::rupiah($item->hak_penitip) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <x-ui.empty-state
                                    title="Tidak ada baris"
                                    description="Nota ini tersimpan tanpa barang. Periksa log perangkat sebelum angkanya dipakai."
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.section-card>

    <x-ui.section-card title="Pembayaran">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 flex-1">
                <div class="table-scroll">
                    <table class="w-full min-w-[420px] text-left">
                        <thead class="thead-dense">
                            <tr>
                                <th class="px-3 py-3 font-semibold">Metode</th>
                                <th class="px-3 py-3 text-right font-semibold">Nominal Diterima</th>
                                <th class="px-3 py-3 font-semibold">Referensi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-subtle">
                            @forelse ($sale->payments as $payment)
                                <tr class="transition hover:bg-canvas">
                                    <td class="px-3 py-2 text-body-sm text-text-strong">{{ $payment->method->label() }}</td>
                                    <td class="px-3 py-2 text-right font-medium tabular-nums">{{ Format::rupiah($payment->amount) }}</td>
                                    <td class="px-3 py-2 font-mono text-label-sm text-text-muted">
                                        {{ $payment->reference ?? Format::EMPTY }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3">
                                        <x-ui.empty-state
                                            title="Tidak ada pembayaran"
                                            description="Nota ini tidak punya baris pembayaran; totalnya tidak bisa dicocokkan dengan uang yang diterima."
                                        />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{--
                Ringkasan uang, di sebelah kanan seperti struk.

                Yang dicocokkan kasir di sini bukan cuma totalnya, tapi juga bahwa
                Σ pembayaran sama dengan total belanja -- dua angka yang seharusnya
                selalu sama, dan kalau tidak sama, halaman ini yang pertama
                menunjukkannya.
            --}}
            <div class="w-full shrink-0 lg:w-80">
                <div class="rounded-lg border border-border-subtle bg-canvas p-4">
                    <dl class="space-y-2">
                        <div class="flex items-center justify-between gap-4 text-body-sm">
                            <dt class="text-text-muted">Subtotal</dt>
                            <dd class="tabular-nums text-text-strong">{{ Format::rupiah($sale->subtotal) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 text-body-sm">
                            <dt class="text-text-muted">Diskon</dt>
                            <dd class="tabular-nums text-text-strong">{{ Format::rupiah($sale->discount_total) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 border-t border-border-subtle pt-2 text-body-md">
                            <dt class="font-semibold text-text-strong">Total</dt>
                            <dd class="font-semibold tabular-nums text-text-strong">{{ Format::rupiah($sale->total) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4 text-body-sm">
                            <dt class="text-text-muted">Diterima toko</dt>
                            <dd class="tabular-nums text-text-strong">{{ Format::rupiah($received) }}</dd>
                        </div>
                    </dl>

                    @if ($received !== $sale->total)
                        <p class="mt-3 rounded border border-warning-border bg-warning-bg px-3 py-2 text-label-sm text-warning-text">
                            Pembayaran {{ Format::rupiah($received) }} tidak sama dengan total
                            {{ Format::rupiah($sale->total) }}. Selisih
                            {{ Format::rupiah(abs($sale->total - $received)) }}.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </x-ui.section-card>
</div>
