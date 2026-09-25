<x-ui.page-header
    title="Retur Penitip (RTV)"
    subtitle="Kembalikan barang titipan non-performing ke penitip. SKU ber-aging &gt; 60 hari layak dikembalikan."
    :crumbs="['Inventory', 'Retur Penitip (RTV)']"
>
</x-ui.page-header>

<div class="space-y-6" x-data="{ step: 1 }">
    <!-- Stepper header -->
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

    <!-- Step 1: Select -->
    <div x-show="step === 1">
        <x-ui.section-card title="Langkah 1 · Pilih Penitip">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.field label="Penitip" name="rtv_consignor" required>
                    <select id="rtv_consignor" class="input-base">
                        @foreach ($consignors as $consignor)
                            <option value="{{ $consignor['code'] }}">{{ $consignor['name'] }} ({{ $consignor['code'] }})</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field label="Minimal Aging" name="rtv_aging" hint="Unit ber-usia ≥ ini muncul di tabel">
                    <select id="rtv_aging" class="input-base">
                        <option>60 hari</option>
                        <option>90 hari</option>
                        <option>30 hari</option>
                    </select>
                </x-ui.field>
            </div>

            <table class="mt-6 w-full text-left">
                <thead class="thead-dense">
                    <tr>
                        <th class="px-6 py-3 font-semibold"></th>
                        <th class="px-6 py-3 font-semibold">SKU / Produk</th>
                        <th class="px-6 py-3 font-semibold">Rak</th>
                        <th class="px-6 py-3 text-center font-semibold">Aging</th>
                        <th class="px-6 py-3 text-center font-semibold">Qty</th>
                        <th class="px-6 py-3 text-right font-semibold">Harga</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-subtle" x-data="{ picked: ['CN01-HW-001'] }">
                    @foreach ([
                        ['sku' => 'CN01-HW-001', 'model' => 'Nissan Skyline GT-R R34', 'rack' => 'A-01-03', 'age' => 82, 'qty' => 2, 'price' => 220000],
                        ['sku' => 'CN01-HW-002', 'model' => 'Toyota Supra MK4', 'rack' => 'B-01-05', 'age' => 67, 'qty' => 3, 'price' => 185000],
                        ['sku' => 'CN02-HW-002', 'model' => 'Ford Mustang GT', 'rack' => 'B-01-05', 'age' => 64, 'qty' => 1, 'price' => 120000],
                    ] as $row)
                        <tr class="row-dense transition hover:bg-canvas">
                            <td class="px-6 py-3">
                                <input type="checkbox" value="{{ $row['sku'] }}" x-model="picked" class="h-5 w-5 rounded border-border-strong text-primary focus:ring-primary/30">
                            </td>
                            <td class="px-6 py-3">
                                <p class="text-body-md font-medium text-text-strong">{{ $row['model'] }}</p>
                                <p class="font-mono text-label-sm text-text-subtle">{{ $row['sku'] }}</p>
                            </td>
                            <td class="px-6 py-3 font-mono text-body-sm text-text-muted">{{ $row['rack'] }}</td>
                            <td class="px-6 py-3 text-center">
                                @if ($row['age'] > 75)
                                    <x-ui.badge-status type="error">{{ $row['age'] }} hari</x-ui.badge-status>
                                @elseif ($row['age'] > 60)
                                    <x-ui.badge-status type="warning">{{ $row['age'] }} hari</x-ui.badge-status>
                                @else
                                    <x-ui.badge-status type="success">{{ $row['age'] }} hari</x-ui.badge-status>
                                @endif
                            </td>
                            <td class="px-6 py-3 text-center text-body-md font-semibold tabular-nums">{{ $row['qty'] }}</td>
                            <td class="px-6 py-3 text-right text-body-md font-semibold tabular-nums">{{ \App\Support\MockData::rupiah($row['price']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-5 flex justify-end">
                <button type="button" class="btn-primary" @click="step = 2">Lanjut ke Verifikasi</button>
            </div>
        </x-ui.section-card>
    </div>

    <!-- Step 2: Verify -->
    <div x-show="step === 2" x-cloak>
        <x-ui.section-card title="Langkah 2 · Verifikasi Fisik (scan barcode ke rak RTV_STAGING)">
            <x-ui.banner tone="info">
                <span class="font-semibold">Instruksi:</span>
                Pindai barcode internal setiap unit ke rak <b>R-00-02</b>. Nilai QR ditulis otomatis.
            </x-ui.banner>

            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                <div class="rounded-lg border border-border-subtle bg-canvas p-5">
                    <p class="text-label-md text-text-muted">Scan Box Parcels (scan setiap unit)</p>
                    <input type="text" class="scan-input mt-3" placeholder="AWB-BOX-001..." x-scan="$store.toast.push('Box AWB ' + $el.value + ' diterima (mock)', 'success')" autofocus>
                </div>
                <div class="rounded-lg border border-border-subtle bg-canvas p-5">
                    <p class="text-label-md text-text-muted">Scan Barcode per Unit</p>
                    <input type="text" class="scan-input mt-3" placeholder="CN01-HW-001..." x-scan="$store.toast.push('Unit ' + $el.value + ' dipindai (mock)', 'success')">
                </div>
            </div>

            <ul class="mt-5 divide-y divide-border-subtle rounded-lg border border-border-subtle bg-surface-lowest">
                <li class="flex items-center justify-between px-4 py-3">
                    <div>
                        <p class="text-body-md font-medium text-text-strong">Nissan Skyline GT-R R34</p>
                        <p class="font-mono text-label-sm text-text-subtle">CN01-HW-001</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-body-sm text-text-muted tabular-nums">2 / 2 dipindai</span>
                        <x-ui.badge-status type="success">Selesai</x-ui.badge-status>
                    </div>
                </li>
                <li class="flex items-center justify-between px-4 py-3">
                    <div>
                        <p class="text-body-md font-medium text-text-strong">Toyota Supra MK4</p>
                        <p class="font-mono text-label-sm text-text-subtle">CN01-HW-002</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-body-sm text-text-muted tabular-nums">1 / 3 dipindai</span>
                        <x-ui.badge-status type="warning">Proses</x-ui.badge-status>
                    </div>
                </li>
            </ul>

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="step = 1">Kembali</button>
                <button type="button" class="btn-primary" @click="step = 3">Lanjut ke Approval</button>
            </div>
        </x-ui.section-card>
    </div>

    <!-- Step 3: Approval & shipment -->
    <div x-show="step === 3" x-cloak>
        <x-ui.section-card title="Langkah 3 · Approval Owner">
            <div class="grid gap-6 lg:grid-cols-2">
                <x-ui.pin-overlay context="Approval disetujui Owner diperlukan sebelum unit keluar racking." />
                <div class="space-y-4">
                    <x-ui.field label="Nomor Pengiriman (Courier)" name="rtv_awb" hint="Kosongkan utk terima AWB dari penitip">
                        <input id="rtv_awb" class="input-base font-mono" placeholder="JNE/POS/DMP...">
                    </x-ui.field>
                    <div class="rounded-lg border border-border-subtle bg-canvas p-4 text-body-sm">
                        <p class="text-label-md text-text-muted">Ringkasan</p>
                        <p class="mt-2">3 SKU · 6 unit · Estimasi nilai Rp970.000</p>
                        <p class="text-text-muted">Rak RTV_STAGING: R-00-02</p>
                    </div>
                    <button type="button" class="btn-primary w-full"
                            @click="$store.toast.push('RTV selesai · unit keluar stok (mock)', 'success'); step = 1">
                        Selesaikan RTV &amp; Kirim Surat Jalan
                    </button>
                </div>
            </div>
        </x-ui.section-card>
    </div>
</div>