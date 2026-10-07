@use('App\Support\Format')
@use('App\Enums\RtvStatus')

<x-ui.page-header
    title="Retur Penitip (RTV)"
    subtitle="Kembalikan barang titipan yang tidak laku ke penitip: pilih SKU, pindai fisiknya di rak staging, lalu tunggu persetujuan Owner."
    :crumbs="['Inventory', 'Retur Penitip (RTV)']"
>
</x-ui.page-header>

{{-- Langkah tidak pernah berpindah karena tombol diklik: nilai `step` berasal
     dari server dan hanya berubah lewat muat ulang setelah langkah sebelumnya
     benar-benar diterima. Tombol "lanjut" di peramban bisa dilewati dengan
     membuka developer tools; aturan di `RtvService` tidak. --}}
<div class="space-y-6" x-data="{ step: {{ $step }}, qty: {} }">
    @if ($errors->any())
        <x-ui.banner tone="error">{{ $errors->first() }}</x-ui.banner>
    @endif

    <ol class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-0">
        @foreach (['Pilih SKU &amp; Penitip', 'Verifikasi Fisik', 'Approval &amp; Eksekusi'] as $label)
            <li class="flex items-center gap-3 sm:gap-0">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-label-md font-bold"
                      :class="step >= {{ $loop->iteration }} ? 'bg-primary text-on-primary' : 'bg-canvas text-text-subtle'">{{ $loop->iteration }}</span>
                <span class="text-body-sm font-medium" :class="step >= {{ $loop->iteration }} ? 'text-text-strong' : 'text-text-subtle'">{!! $label !!}</span>
                @unless ($loop->last)
                    <span class="hidden h-px flex-1 bg-border-subtle sm:mx-4 sm:block"></span>
                @endunless
            </li>
        @endforeach
    </ol>

    @if ($open === null)
        {{-- ===== Langkah 1: pilih penitip, lalu tentukan qty per SKU (FR-IC-30) --}}
        <div x-show="step === 1">
            <form method="GET" action="{{ route('inventory.retur-rtv') }}">
                <x-ui.section-card title="Langkah 1 · Pilih Penitip">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.field label="Penitip" name="penitip" required hint="SKU yang muncul adalah stok titipan milik penitip ini.">
                            <select id="penitip" name="penitip" class="input-base" onchange="this.form.submit()">
                                <option value="">Pilih penitip…</option>
                                @foreach ($consignors as $consignor)
                                    <option value="{{ $consignor->id }}" @selected($penitip === $consignor->id)>
                                        {{ $consignor->consignor_code }} · {{ $consignor->name }}
                                    </option>
                                @endforeach
                            </select>
                        </x-ui.field>

                        <x-ui.field label="Minimal Aging" name="aging" hint="SKU ber-usia ≥ ini dan belum pernah laku ditandai layak dikembalikan.">
                            <select id="aging" name="aging" class="input-base" onchange="this.form.submit()">
                                @foreach ($agingChoices as $days)
                                    <option value="{{ $days }}" @selected($aging === $days)>{{ $days }} hari</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                    </div>
                </x-ui.section-card>
            </form>

            @if ($candidates === null)
                <x-ui.section-card title="Langkah 1 · Tentukan Qty Kembali">
                    <x-ui.empty-state
                        title="Pilih penitip lebih dulu"
                        description="Daftar SKU beserta stoknya muncul setelah penitip dipilih pada kartu di atas."
                    />
                </x-ui.section-card>
            @else
                <form method="POST" action="{{ route('inventory.retur-rtv.store') }}">
                    @csrf
                    <x-ui.section-card title="Langkah 1 · Tentukan Qty Kembali">
                        @if ($candidates->isEmpty())
                            <x-ui.empty-state
                                title="Tidak ada stok siap dikembalikan"
                                description="Penitip ini tidak punya unit tersedia. Periksa Live Stock bila angkanya berbeda."
                            />
                        @else
                            <div class="table-scroll">
                                <table class="w-full min-w-max text-left">
                                    <thead class="thead-dense">
                                        <tr>
                                            <th class="px-6 py-3 font-semibold">SKU / Produk</th>
                                            <th class="px-6 py-3 font-semibold">Rak</th>
                                            <th class="px-6 py-3 text-center font-semibold">Aging</th>
                                            <th class="px-6 py-3 text-center font-semibold">Tersedia</th>
                                            <th class="px-6 py-3 text-center font-semibold">Qty Kembali</th>
                                            <th class="px-6 py-3 text-right font-semibold">Harga</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border-subtle">
                                        @foreach ($candidates as $lot)
                                            @php
                                                $age = (int) $lot->created_at->diffInDays(now());
                                                $blockedReason = $blocked->get($lot->id);
                                                $recommend = $age >= $aging && $lot->last_sold_at === null;
                                            @endphp
                                            <tr class="row-dense transition hover:bg-canvas">
                                                <td class="px-6 py-3">
                                                    <p class="text-body-md font-medium text-text-strong">{{ $lot->product?->name ?? $lot->sku }}</p>
                                                    <p class="font-mono text-label-sm text-text-subtle">{{ $lot->sku }}</p>
                                                </td>
                                                <td class="px-6 py-3 font-mono text-body-sm text-text-muted">{{ $lot->rack?->code ?? Format::EMPTY }}</td>
                                                <td class="px-6 py-3 text-center">
                                                    <x-ui.badge-status :type="$age >= $aging ? 'warning' : 'neutral'">{{ $age }} hari</x-ui.badge-status>
                                                    @if ($recommend)
                                                        <span class="mt-1 block text-label-sm text-warning-text">Belum pernah laku</span>
                                                    @endif
                                                </td>
                                                <td class="px-6 py-3 text-center text-body-md font-semibold tabular-nums">{{ $lot->qty_on_hand }}</td>
                                                <td class="px-6 py-3 text-center">
                                                    @if ($blockedReason !== null)
                                                        {{-- FR-IC-35: barisnya tetap ditampilkan supaya orang tahu ada
                                                             alasan di balik kolom yang kosong, tapi tidak ada input yang
                                                             bisa dikirim untuk lot ini. --}}
                                                        <span class="inline-block max-w-[14rem] text-label-sm text-text-subtle">{{ $blockedReason }}</span>
                                                    @else
                                                        <input type="number" name="qty[{{ $lot->id }}]"
                                                               min="1" max="{{ $lot->qty_on_hand }}" step="1" value=""
                                                               x-model.number="qty[{{ $lot->id }}]"
                                                               aria-label="Qty kembali untuk {{ $lot->sku }}"
                                                               class="input-base mx-auto block h-9 w-24 text-center">
                                                    @endif
                                                </td>
                                                <td class="px-6 py-3 text-right text-body-md font-semibold tabular-nums">{{ Format::rupiah($lot->list_price) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                                <x-ui.field label="Alasan retur (opsional)" name="reason" hint="Ditulis ke dokumen dan ke gerakan stok saat eksekusi.">
                                    <input id="reason" name="reason" type="text" maxlength="255"
                                           class="input-base sm:w-80" placeholder="Mis. tidak laku, penitip tidak lanjut">
                                </x-ui.field>

                                <button type="submit" class="btn-primary"
                                        :disabled="!Object.values(qty).some(value => Number(value) > 0)">
                                    Buat Sesi RTV
                                </button>
                            </div>
                        @endif
                    </x-ui.section-card>
                </form>
            @endif
        </div>
    @else
        {{-- ===== Langkah 2: pindah ke rak staging, lalu pindai tiap unit (FR-IC-32) --}}
        <div x-show="step === 2" x-cloak>
            <x-ui.section-card :title="'Langkah 2 · Verifikasi Fisik · '.$open->rtv_no">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-body-sm text-text-muted">
                    <span>Penitip: <span class="font-medium text-text-strong">{{ $open->consignor?->name ?? Format::EMPTY }}</span></span>
                    <span>{{ $open->lines->count() }} SKU · {{ $open->units() }} unit</span>
                    <span class="inline-flex items-center gap-2">
                        Status:
                        <x-ui.badge-status :type="Format::statusType($open->status->value)">{{ $open->status->label() }}</x-ui.badge-status>
                    </span>
                </div>

                @if ($open->status === RtvStatus::Draft)
                    @if ($stagingRack === null)
                        <div class="mt-5">
                            <x-ui.banner tone="error">Rak bertipe RTV_STAGING tidak tersedia atau nonaktif. Aktifkan rak staging di menu Lokasi Rak sebelum memindahkan barang.</x-ui.banner>
                        </div>
                    @else
                        <div class="mt-5">
                            <x-ui.banner tone="info">
                                Barang dipindah ke rak <b>{{ $stagingRack->code }}</b> lebih dulu, lalu tiap unit dipindai dari rak asal. Pemindaian baru bisa dimulai setelah pemindahan.
                            </x-ui.banner>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('inventory.retur-rtv.staging', $open) }}" class="mt-5 flex justify-end">
                        @csrf
                        <button type="submit" class="btn-primary">Pindah ke Rak Staging</button>
                    </form>
                @else
                    @php
                        $verifiedUnits = $open->verifiedUnits();
                        $totalUnits = $open->units();
                    @endphp

                    <form method="POST" action="{{ route('inventory.retur-rtv.scan', $open) }}" class="mt-5">
                        @csrf
                        <label for="scan-sku" class="mb-1.5 block text-label-md text-text-muted">Scan barcode unit</label>
                        <div class="flex flex-col gap-3 sm:flex-row">
                            <input id="scan-sku" name="sku" type="text" class="input-base font-mono"
                                   placeholder="CN01-HW-001…" autocomplete="off" autocapitalize="characters"
                                   autofocus required>
                            <button type="submit" class="btn-primary shrink-0">Pindai</button>
                        </div>
                    </form>

                    <div class="mt-5">
                        <x-ui.banner tone="info">
                            Terverifikasi {{ $verifiedUnits }} dari {{ $totalUnits }} unit. Setiap scan menaikkan hitungan satu SKU; qty RTV tidak bisa dilewati.
                        </x-ui.banner>
                    </div>

                    <ul class="mt-5 divide-y divide-border-subtle rounded-lg border border-border-subtle bg-surface-lowest">
                        @foreach ($open->lines as $line)
                            <li class="flex items-center justify-between gap-4 px-4 py-3">
                                <div>
                                    <p class="text-body-md font-medium text-text-strong">{{ $line->lot?->product?->name ?? $line->lot?->sku ?? ('Lot #'.$line->lot_id) }}</p>
                                    <p class="font-mono text-label-sm text-text-subtle">{{ $line->lot?->sku ?? Format::EMPTY }} · {{ $line->lot?->rack?->code ?? Format::EMPTY }}</p>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    <span class="text-body-sm tabular-nums text-text-muted">{{ $line->verified_qty }} / {{ $line->qty }} dipindai</span>
                                    @if ($line->isFullyVerified())
                                        <x-ui.badge-status type="success">Selesai</x-ui.badge-status>
                                    @else
                                        <x-ui.badge-status type="warning">Proses</x-ui.badge-status>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.section-card>
        </div>

        {{-- ===== Langkah 3: persetujuan Owner sekaligus eksekusi (FR-IC-33) --}}
        <div x-show="step === 3" x-cloak>
            <form method="POST" action="{{ route('inventory.retur-rtv.approve', $open) }}">
                @csrf
                <x-ui.section-card title="Langkah 3 · Approval &amp; Eksekusi">
                    <div class="grid gap-6 lg:grid-cols-2">
                        <div class="space-y-4">
                            <div class="rounded-lg border border-border-subtle bg-canvas p-4 text-body-sm">
                                <p class="text-label-md text-text-muted">Ringkasan</p>
                                <p class="mt-2 font-medium text-text-strong">{{ $open->rtv_no }} · {{ $open->consignor?->name ?? Format::EMPTY }}</p>
                                <p>{{ $open->lines->count() }} SKU · {{ $open->units() }} unit · semua terverifikasi</p>
                                <p class="text-text-muted">Rak staging: {{ $stagingRack?->code ?? Format::EMPTY }}</p>
                                @if ($open->reason !== null && $open->reason !== '')
                                    <p class="mt-2 text-text-muted">Alasan: {{ $open->reason }}</p>
                                @endif
                            </div>

                            <x-ui.banner tone="warning">
                                Eksekusi mengurangi qty lot, menulis gerakan RTV, dan menandai lot yang habis sebagai Dikembalikan. Tindakan ini tidak bisa dibatalkan di aplikasi.
                            </x-ui.banner>
                        </div>

                        <x-ui.pin-overlay
                            action="inventory.rtv-approve"
                            context="Persetujuan Owner diperlukan sebelum unit keluar stok lewat RTV."
                            confirm-text="Saya setujui"
                        />
                    </div>

                    <div class="mt-5 flex justify-end">
                        <button type="submit" class="btn-primary">Setujui &amp; Eksekusi RTV</button>
                    </div>
                </x-ui.section-card>
            </form>
        </div>

        {{-- Pembatalan berdiri sendiri di luar kedua panel: sesi yang sedang
             berjalan harus bisa ditinggal dari layar mana pun langkahnya, dan
             tombolnya tidak boleh berada di dalam form langkah lain. --}}
        <div class="flex justify-end">
            <form method="POST" action="{{ route('inventory.retur-rtv.batal', $open) }}"
                  onsubmit="return confirm('Batalkan sesi {{ $open->rtv_no }}? Baris yang sudah dipindai tetap tercatat, tetapi sesi tidak akan dieksekusi.')">
                @csrf
                <button type="submit" class="btn-secondary">Batalkan Sesi RTV</button>
            </form>
        </div>
    @endif

    {{-- ===== Riwayat: dokumen yang sudah selesai --}}
    <x-ui.section-card title="Riwayat RTV">
        @if ($history->isEmpty())
            <x-ui.empty-state
                title="Belum ada RTV"
                description="Dokumen yang sudah dieksekusi atau dibatalkan akan muncul di sini."
            />
        @else
            <div class="table-scroll">
                <table class="w-full min-w-max text-left">
                    <thead class="thead-dense">
                        <tr>
                            <th class="px-6 py-3 font-semibold">Nomor</th>
                            <th class="px-6 py-3 font-semibold">Penitip</th>
                            <th class="px-6 py-3 text-center font-semibold">SKU</th>
                            <th class="px-6 py-3 text-right font-semibold">Unit</th>
                            <th class="px-6 py-3 font-semibold">Status</th>
                            <th class="px-6 py-3 font-semibold">Dibuat oleh</th>
                            <th class="px-6 py-3 font-semibold">Dieksekusi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-subtle">
                        @foreach ($history as $note)
                            <tr class="row-dense transition hover:bg-canvas">
                                <td class="px-6 py-3 font-mono text-body-sm text-text-strong">{{ $note->rtv_no }}</td>
                                <td class="px-6 py-3 text-body-sm">{{ $note->consignor?->name ?? Format::EMPTY }}</td>
                                <td class="px-6 py-3 text-center text-body-sm tabular-nums">{{ $note->lines_count }}</td>
                                <td class="px-6 py-3 text-right text-body-sm font-semibold tabular-nums">{{ $note->lines_sum_qty ?? 0 }}</td>
                                <td class="px-6 py-3">
                                    <x-ui.badge-status :type="Format::statusType($note->status->value)">{{ $note->status->label() }}</x-ui.badge-status>
                                </td>
                                <td class="px-6 py-3 text-body-sm text-text-muted">{{ $note->creator?->name ?? Format::EMPTY }}</td>
                                <td class="px-6 py-3 text-body-sm text-text-muted">{{ $note->executed_at !== null ? Format::datetime($note->executed_at) : Format::EMPTY }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.section-card>
</div>
