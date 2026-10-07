@use('App\Support\Format')

@php
    /**
     * Tiga keadaan yang dilihat operator, bukan satu daftar status.
     *
     * Notifikasi yang belum boleh dikirim (opt-in belum dicentang) dan yang
     * sudah diserahkan adalah dua hal yang butuh tindakan berbeda, jadi
     * ditata terpisah. Menyatukan keduanya ke "belum dikirim" membuat
     *-notifikasi yang tidak akan pernah terkirim terlihat seperti antrean
     * yang menunggu.
     */
    $waStatus = $notification->status;
    $waBlocked = $waStatus->isFailed();
    $waReady = $handoffLink !== null && $waStatus->isOpen();
@endphp

<x-ui.page-header
    title="Consignment {{ $consignment->doc_no }}"
    subtitle="{{ $consignment->consignor?->name }} · {{ $consignment->consignment_date->format('d M Y') }}"
    :crumbs="['Inbound', 'Consignment In', 'Riwayat', $consignment->doc_no]"
>
    <x-slot:actions>
        <a href="{{ route('inbound.consignment-in.riwayat') }}" class="btn-secondary">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5m7-7l-7 7 7 7"/></svg>
            Kembali ke Riwayat
        </a>
        <a href="{{ route('inbound.cetak-label') }}" class="btn-primary">Cetak Label</a>

        {{--
            Cetak bukti terima ada di header, bukan di dalam kartu e-receipt.

            Dua bentuk bukti terima ini berbeda cara kirim, bukan dua tampilan
            dari satu hal: yang ke WhatsApp dikirim ke nomor penitip, yang ke
            kertas ditandatangani di depan toko. Menaruhnya di bawah kartu
            WhatsApp membuat orang mengira struk hanya tersedia kalau nomor
            WhatsApp-nya valid, padahal penitip tanpa WhatsApp tetap berhak atas
            kertas bertanda tangan.
        --}}
        @if ($consignment->status === \App\Enums\ConsignmentStatus::Completed)
            <a href="{{ route('inbound.consignment-in.bukti-terima', $consignment) }}"
               class="btn-secondary">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6z"/></svg>
                Cetak Bukti Terima
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    @if ($consignment->status === \App\Enums\ConsignmentStatus::Completed)
        {{--
            Kartu kertas, di atas kartu WhatsApp.

            Yang dicatat di sini adalah penyerahan kertasnya, bukan cetakannya.
            Staff menekan "sudah diserahkan" setelah penitip menandatangani, dan
            saat itu struknya sudah keluar dari printer -- menghitung cetakan
            dari sini akan mencatat pencetakan yang tidak pernah terjadi
            (Staff menekan Cetak lalu cancel dialog printnya) dan juga
            sebaliknya: cetakan ulang yang tidak dicatat sama sekali.
        --}}
        <x-ui.section-card title="Bukti Terima Cetak (kertas)">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    @if ($receiptHandovers > 0)
                        <x-ui.badge-status type="success">SUDAH DISERAHKAN</x-ui.badge-status>
                        <p class="mt-2 text-body-sm text-text-muted">
                            Kertas bukti terima untuk {{ $consignment->doc_no }} sudah dicatat
                            diserahkan {{ $receiptHandovers }}x
                            ({{ $receiptHandovers === 1 ? 'pertama kali' : 'terakhir '.$lastHandoverAt?->format('d M Y H:i') }}).
                        </p>
                    @else
                        <x-ui.badge-status type="neutral">BELUM DICETAK</x-ui.badge-status>
                        <p class="mt-2 text-body-sm text-text-muted">
                            Cetak kertas bukti terima, lalu tekan "Sudah Disyerahkan" setelah
                            penitip menandatanganinya. Cetakan ulang tetap boleh dan tidak dibatasi.
                        </p>
                    @endif
                </div>

                <div class="flex shrink-0 flex-col gap-2">
                    <a href="{{ route('inbound.consignment-in.bukti-terima', $consignment) }}"
                       class="btn-primary">
                        {{ $receiptHandovers > 0 ? 'Cetak Ulang Bukti Terima' : 'Cetak Bukti Terima' }}
                    </a>
                    <form method="POST"
                          action="{{ route('inbound.consignment-in.bukti-terima.serahkan', $consignment) }}">
                        @csrf
                        <button type="submit" class="btn-secondary">Sudah Disyerahkan</button>
                    </form>
                </div>
            </div>
        </x-ui.section-card>
    @endif

    <x-ui.section-card title="Bukti Terima WhatsApp (e-receipt)">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="mb-1 flex flex-wrap items-center gap-2">
                    <x-ui.badge-status :type="$waStatus->type()">{{ $waStatus->label() }}</x-ui.badge-status>
                    @if ($notification->attempts > 0)
                        <span class="text-label-sm text-text-subtle">
                            {{ $notification->attempts }} percobaan
                        </span>
                    @endif
                </div>

                @if ($waBlocked)
                    <p class="text-body-sm text-text-muted">{{ $notification->error_message }}</p>
                    <p class="mt-1 text-label-sm text-text-subtle">
                        Belum ada jeda otomatis untuk alasan ini: dicoba lagi
                        tidak akan berhasil sampai ada yang berubah di data penitip.
                    </p>
                @elseif ($waStatus->isSettled())
                    <p class="text-body-sm text-text-muted">
                        Tautan diserahkan ke Staff pada {{ $notification->sent_at?->format('d M Y H:i') }}.
                        Dokumen tidak bergantung pada pesan ini: statusnya tetap
                        {{ \App\Support\Format::statusLabel($consignment->status) }}.
                    </p>
                @else
                    <p class="text-body-sm text-text-muted">
                        Siap dikirim ke {{ $consignment->consignor?->wa_number_display ?? '-' }}.
                        Buka tautannya untuk mengirim, lalu tandai sudah disiapkan
                        supaya jejaknya tercatat.
                    </p>
                @endif
            </div>

            <div class="flex shrink-0 flex-col gap-2">
                @if ($waBlocked)
                    <form method="POST" action="{{ route('inbound.consignment-in.e-receipt', $consignment) }}">
                        @csrf
                        <button type="submit" class="btn-secondary">Coba Lagi</button>
                    </form>
                @elseif ($waReady)
                    <a href="{{ $handoffLink }}" target="_blank" rel="noopener" class="btn-primary">
                        Buka WhatsApp
                    </a>
                    <form method="POST" action="{{ route('inbound.consignment-in.e-receipt', $consignment) }}">
                        @csrf
                        <button type="submit" class="btn-secondary">Tandai Sudah Disiapkan</button>
                    </form>
                @elseif ($waStatus->isSettled())
                    <a href="{{ $handoffLink ?? route('inbound.consignment-in.riwayat') }}"
                       @if ($handoffLink) target="_blank" rel="noopener" @endif
                       class="btn-secondary">Buka Lagi</a>
                @endif
            </div>
        </div>
    </x-ui.section-card>

    @if (($consignment->qty_claimed ?? 0) !== ($consignment->qty_received ?? 0))
        <x-ui.banner tone="warning">
            <span class="font-semibold">Varian</span>
            Klaim {{ Format::number($consignment->qty_claimed ?? 0) }} unit vs terima {{ Format::number($consignment->qty_received ?? 0) }} unit.
            {{ $consignment->variance_note ? 'Catatan: '.$consignment->variance_note : 'Belum ada catatan varian.' }}
        </x-ui.banner>
    @endif

    <div class="card card-pad">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <p class="text-label-md text-text-muted">Penitip</p>
                <p class="text-body-md font-medium text-text-strong">{{ $consignment->consignor?->name ?? '-' }}</p>
                <p class="font-mono text-label-sm text-text-subtle">{{ $consignment->consignor?->consignor_code ?? '' }}</p>
            </div>
            <div>
                <p class="text-label-md text-text-muted">Tanggal Terima</p>
                <p class="text-body-md font-medium text-text-strong">{{ $consignment->consignment_date->format('d M Y') }}</p>
            </div>
            <div>
                <p class="text-label-md text-text-muted">Unit (Klaim / Terima)</p>
                <p class="text-body-md font-medium text-text-strong tabular-nums">{{ Format::number($consignment->qty_claimed ?? 0) }} / {{ Format::number($consignment->qty_received ?? 0) }}</p>
            </div>
            <div>
                <p class="text-label-md text-text-muted">Status</p>
                <p class="mt-1"><x-ui.badge-status :type="Format::statusType($consignment->status)">{{ Format::statusLabel($consignment->status) }}</x-ui.badge-status></p>
            </div>
            <div>
                <p class="text-label-md text-text-muted">Dibuat oleh</p>
                <p class="text-body-md font-medium text-text-strong">{{ $consignment->creator?->name ?? '-' }}</p>
                @if ($consignment->committed_at)
                    <p class="text-label-sm text-text-subtle">Commit {{ $consignment->committed_at->format('d M Y H:i') }}</p>
                @endif
            </div>
        </div>
        @if ($consignment->source || $consignment->notes)
            <div class="mt-4 border-t border-border-subtle pt-4 text-body-sm text-text-muted">
                @if ($consignment->source)
                    <p><b class="text-text-strong">Referensi:</b> {{ $consignment->source }}</p>
                @endif
                @if ($consignment->notes)
                    <p><b class="text-text-strong">Catatan:</b> {{ $consignment->notes }}</p>
                @endif
            </div>
        @endif
    </div>

    <x-ui.section-card pad="false">
        <div class="px-6 py-4">
            <h2 class="text-headline-sm text-text-strong">Barang Diterima ({{ $lots->count() }} SKU)</h2>
        </div>
        <div class="table-scroll">
            <table class="w-full min-w-[860px] text-left">
                <thead class="thead-dense">
                    <tr>
                        <th class="px-3 py-3 font-semibold">SKU</th>
                        <th class="px-3 py-3 font-semibold">Produk</th>
                        <th class="px-3 py-3 font-semibold">Kondisi</th>
                        <th class="px-3 py-3 text-center font-semibold">Qty</th>
                        <th class="px-3 py-3 text-right font-semibold">Harga Jual</th>
                        <th class="px-3 py-3 font-semibold">Skema</th>
                        <th class="px-3 py-3 font-semibold">Rak</th>
                        <th class="px-3 py-3 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-subtle">
                    @forelse ($lots as $lot)
                        <tr class="transition hover:bg-canvas">
                            <td class="px-3 py-2 font-mono text-sku text-text-strong">{{ $lot->sku }}</td>
                            <td class="px-3 py-2">
                                <p class="text-body-sm text-text-strong">{{ $lot->product->name }}</p>
                                <p class="text-label-sm text-text-subtle">{{ $lot->product->series?->name ?? 'Tanpa Seri' }}</p>
                            </td>
                            <td class="px-3 py-2 text-label-sm text-text-muted">
                                {{ $lot->card_condition?->value ?? '-' }}
                                @if (trim((string) ($lot->blister_condition?->value ?? '')) !== 'N/A')
                                    · {{ $lot->blister_condition?->value ?? '-' }}
                                @endif
                            </td>
                            <td class="px-3 py-2 text-center tabular-nums">{{ Format::number($lot->qty_on_hand) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ $lot->list_price ? Format::rupiah($lot->list_price) : '-' }}</td>
                            <td class="px-3 py-2 text-label-sm text-text-muted">
                                @php
                                    $scheme = match (true) {
                                        $lot->scheme_type?->value === \App\Enums\SchemeType::Percentage->value => rtrim(rtrim((string) $lot->scheme_rate, '0'), '.').' %',
                                        $lot->scheme_amount !== null => ($lot->scheme_type?->value ?? '-').' · '.Format::rupiah($lot->scheme_amount).'/unit',
                                        default => '-',
                                    };
                                @endphp
                                {{ $scheme }}
                            </td>
                            <td class="px-3 py-2 font-mono text-label-sm text-text-muted">{{ $lot->rack?->code ?? '-' }}</td>
                            <td class="px-3 py-2">
                                <x-ui.badge-status :type="Format::statusType($lot->status)">{{ Format::statusLabel($lot->status) }}</x-ui.badge-status>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <x-ui.empty-state title="Belum ada baris" description="Dokumen ini belum memiliki baris barang." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.section-card>
</div>