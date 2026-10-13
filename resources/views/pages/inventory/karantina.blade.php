@use('App\Support\Format')
@use('App\Enums\QuarantineStatus')

<x-ui.page-header
    title="Karantina"
    subtitle="Barang tak teridentifikasi / rusak / barcode tidak terbaca. SLA: assign dalam {{ $reviewDays }} hari, eskalasi {{ $escalateDays }} hari."
    :crumbs="['Inventory', 'Karantina']"
>
    <x-slot:actions>
        <x-ui.drawer
            id="registrasi-kasus"
            title="Registrasi Kasus Karantina"
            subtitle="Unit tak teridentifikasi / label tidak terbaca. Identitas dicatat sebagai atribut; stok tidak tersentuh karena SKU belum dikenal."
            :open="$storeInvalid ?? false"
        >
            <x-slot:trigger>
                <button type="button" class="btn-primary whitespace-nowrap inline-flex items-center gap-2"
                        @click="$dispatch('drawer:open', { id: 'registrasi-kasus' })">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    Registrasi Kasus
                </button>
            </x-slot:trigger>

            <x-slot:panel>
                <form method="POST" action="{{ route('inventory.karantina.store') }}" class="space-y-5">
                    @csrf
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.field label="Nama / kata kunci produk" name="keyword" required>
                            <input type="text" id="keyword" name="keyword" value="{{ old('keyword') }}" class="input-base" placeholder="cth: Ford Mustang GT / nama produk" required>
                        </x-ui.field>

                        <x-ui.field label="Seri" name="series">
                            <input type="text" id="series" name="series" value="{{ old('series') }}" class="input-base" placeholder="cth: American Scene">
                        </x-ui.field>

                        <x-ui.field label="Warna" name="color">
                            <input type="text" id="color" name="color" value="{{ old('color') }}" class="input-base" placeholder="cth: Oxford White">
                        </x-ui.field>

                        <x-ui.field label="Jumlah unit" name="qty" required>
                            <input type="number" id="qty" name="qty" min="1" step="1" value="{{ old('qty', 1) }}" class="input-base" required>
                        </x-ui.field>

                        <x-ui.field label="Kondisi kartu" name="condition">
                            <select id="condition" name="condition" class="select-base">
                                <option value="">Tidak tahu / kosong</option>
                                @foreach ($conditions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('condition') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>

                        <x-ui.field label="Alasan karantina" name="reason">
                            <select id="reason" name="reason" class="select-base">
                                <option value="">Tidak diisi</option>
                                @foreach ($reasons as $value => $label)
                                    <option value="{{ $value }}" @selected(old('reason') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>

                        <div class="sm:col-span-2">
                            <x-ui.field label="Rak karantina" name="rack_id" required hint="Rak yang menyimpan fisik unit ini selama diselidiki.">
                                <select id="rack_id" name="rack_id" class="select-base" required>
                                    <option value="">Pilih rak…</option>
                                    @foreach ($racks as $rack)
                                        <option value="{{ $rack->id }}" @selected((string) old('rack_id') === (string) $rack->id)>{{ $rack->code }}</option>
                                    @endforeach
                                </select>
                            </x-ui.field>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 border-t border-border-subtle pt-4">
                        <button type="submit" class="btn-primary">Buka Kasus</button>
                    </div>
                </form>
            </x-slot:panel>
        </x-ui.drawer>
    </x-slot:actions>
</x-ui.page-header>

@if ($errors->any())
    <x-ui.banner tone="error">{{ $errors->first() }}</x-ui.banner>
@endif

@php
    $storeInvalid = $errors->hasAny(['qty', 'rack_id', 'keyword', 'series', 'color', 'condition', 'reason']);
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="grid flex-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat-card label="Kasus Terbuka" :value="$openCount" delta="SLA {{ $reviewDays }} hari" delta-tone="info" />
            <x-ui.stat-card label="Aging > {{ $reviewDays }} Hari" :value="$agingReview" delta="Perlu review" delta-tone="warning" icon="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
            <x-ui.stat-card label="Aging > {{ $escalateDays }} Hari" :value="$agingEscalate" delta="Eskalasi Owner" delta-tone="error" />
            <x-ui.stat-card label="Unit KARANTINA" :value="$unitTotal" delta="Rak karantina" delta-tone="warning" />
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-12"
         x-data="{
             cases: @js($cases),
             selectedCase: null,
             current() {
                 return this.cases.find((c) => c.caseId === this.selectedCase) ?? null;
             },
             statusTone() {
                 if (! this.current()) {
                     return 'neutral';
                 }
                 if (this.current().status === 'ESCALATED') {
                     return 'error';
                 }
                 if (this.current().status === 'WAITING_EVIDENCE') {
                     return 'warning';
                 }
                 return 'info';
             },
         }"
         x-init="selectedCase = @js($selectedCase ?? ($cases[0]['caseId'] ?? null))">

        {{-- Panel 1: Antrean kasus terbuka --}}
        <div class="lg:col-span-4 card">
            <div class="flex items-center justify-between border-b border-border-subtle px-5 py-4">
                <h2 class="text-headline-sm text-text-strong">Antrean</h2>
                <span class="rounded-full bg-karantina-bg px-2 py-0.5 text-label-sm text-karantina-text tabular-nums">{{ Format::number($openCount) }}</span>
            </div>

            <ul class="divide-y divide-border-subtle">
                @forelse ($cases as $case)
                    <li class="flex items-center gap-2 px-5 py-4">
                        <button type="button"
                                class="min-w-0 flex-1 text-left"
                                :class="selectedCase === @js($case['caseId']) ? 'text-primary' : ''"
                                @click="selectedCase = @js($case['caseId'])">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-mono text-sku text-text-strong">{{ $case['caseId'] }}</span>
                                <x-ui.badge-status :type="$case['severity']">
                                    {{ $case['age'] }} hari
                                </x-ui.badge-status>
                            </div>
                            <p class="mt-1.5 text-body-sm font-medium text-text-strong">{{ $case['model'] }}</p>
                            <p class="mt-0.5 text-label-sm text-text-muted">{{ $case['qty'] }} unit · tiba {{ $case['arrivedAt'] }}</p>
                        </button>

                        @if ($openCount > 0)
                            <a href="{{ route('inventory.karantina', ['case' => $case['caseId']]) }}"
                               class="btn-ghost h-9 shrink-0 whitespace-nowrap px-3">
                                Resolusi
                            </a>
                        @endif
                    </li>
                @empty
                    <li class="px-5 py-10 text-center">
                        <p class="text-body-md font-medium text-text-strong">Tidak ada kasus terbuka</p>
                        <p class="mt-1 text-label-sm text-text-muted">Barang tanpa label yang masuk akan muncul di sini.</p>
                    </li>
                @endforelse
            </ul>
        </div>

        {{-- Panel 2: Detail + resolusi --}}
        <div class="lg:col-span-8 card">
            <div class="flex items-center justify-between border-b border-border-subtle px-5 py-4">
                <h2 class="text-headline-sm text-text-strong">
                    Kasus terpilih:
                    <span class="font-mono text-sku" x-text="current() ? current().caseId : '—'">—</span>
                </h2>
                <span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-label-md"
                      :class="{
                          'bg-error-bg text-error-text border-error-border': statusTone() === 'error',
                          'bg-warning-bg text-warning-text border-warning-border': statusTone() === 'warning',
                          'bg-info-bg text-info-text border-info-border': statusTone() === 'info',
                          'bg-canvas text-text-muted border-border-subtle': statusTone() === 'neutral',
                      }">
                    <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span x-text="current() ? current().statusLabel : ''"></span>
                </span>
            </div>

            <div class="space-y-5 px-5 py-5">
                <x-ui.banner tone="info" class="hidden" x-show="! current()">Tidak ada kasus yang dipilih.</x-ui.banner>

                <template x-if="current()">
                    <div class="space-y-5">
                        <div>
                            <p class="mb-2 text-label-md text-text-muted">Atribut Fisik</p>
                            <div class="grid grid-cols-2 gap-3 text-body-sm">
                                <div class="rounded-lg border border-border-subtle bg-canvas p-3">
                                    <p class="text-label-sm text-text-subtle">Produk</p>
                                    <p class="mt-0.5 font-medium text-text-strong" x-text="current().model"></p>
                                </div>
                                <div class="rounded-lg border border-border-subtle bg-canvas p-3">
                                    <p class="text-label-sm text-text-subtle">Seri</p>
                                    <p class="mt-0.5 font-medium text-text-strong" x-text="current().series || '—'"></p>
                                </div>
                                <div class="rounded-lg border border-border-subtle bg-canvas p-3">
                                    <p class="text-label-sm text-text-subtle">Warna</p>
                                    <p class="mt-0.5 font-medium text-text-strong" x-text="current().color || '—'"></p>
                                </div>
                                <div class="rounded-lg border border-border-subtle bg-canvas p-3">
                                    <p class="text-label-sm text-text-subtle">Kondisi</p>
                                    <p class="mt-0.5 font-medium text-text-strong" x-text="current().condition || '—'"></p>
                                </div>
                                <div class="rounded-lg border border-border-subtle bg-canvas p-3">
                                    <p class="text-label-sm text-text-subtle">Alasan</p>
                                    <p class="mt-0.5 font-medium text-text-strong" x-text="current().reason"></p>
                                </div>
                                <div class="rounded-lg border border-border-subtle bg-canvas p-3">
                                    <p class="text-label-sm text-text-subtle">Jumlah unit</p>
                                    <p class="mt-0.5 font-medium text-text-strong tabular-nums" x-text="current().qty"></p>
                                </div>
                                <div class="rounded-lg border border-border-subtle bg-canvas p-3">
                                    <p class="text-label-sm text-text-subtle">Aging</p>
                                    <p class="mt-0.5 font-medium text-text-strong tabular-nums"
                                       :class="current().severity === 'error' ? 'text-error-text' : (current().severity === 'warning' ? 'text-warning-text' : '')"
                                       x-text="current().age + ' hari'"></p>
                                </div>
                                <div class="rounded-lg border border-border-subtle bg-canvas p-3">
                                    <p class="text-label-sm text-text-subtle">Rak</p>
                                    <p class="mt-0.5 font-mono font-medium text-text-strong" x-text="current().rack || '—'"></p>
                                </div>
                                <div class="rounded-lg border border-border-subtle bg-canvas p-3">
                                    <p class="text-label-sm text-text-subtle">Dibuka oleh</p>
                                    <p class="mt-0.5 font-medium text-text-strong" x-text="current().openedBy || '—'"></p>
                                </div>
                                <div class="rounded-lg border border-border-subtle bg-canvas p-3">
                                    <p class="text-label-sm text-text-subtle">Tiba di karantina</p>
                                    <p class="mt-0.5 font-medium text-text-strong" x-text="current().arrivedAt"></p>
                                </div>
                            </div>
                        </div>

                        @if ($activeCase !== null)
                            <div x-show="current().caseId === @js($activeCase->case_no)" class="space-y-5">
                                <x-ui.section-card title="Resolusi Kasus">
                                    @if ($activeCase->status === QuarantineStatus::Escalated)
                                        <x-ui.banner tone="warning">Kasus ini sudah dieskalasi dan menunggu keputusan Owner.</x-ui.banner>
                                    @endif

                                    <div class="flex flex-wrap gap-3">
                                        @if ($activeCase->status !== QuarantineStatus::Escalated)
                                            <form method="POST" action="{{ route('inventory.karantina.eskalasi', $activeCase) }}" class="inline-flex items-center gap-2">
                                                @csrf
                                                <input type="text" name="note" maxlength="200" placeholder="Catatan (opsional)" class="input-base h-10 w-48">
                                                <button type="submit" class="btn-secondary">Eskalasi</button>
                                            </form>
                                        @endif

                                        <form method="POST" action="{{ route('inventory.karantina.write-off', $activeCase) }}" class="inline-flex items-center gap-2">
                                            @csrf
                                            <input type="text" name="note" maxlength="200" placeholder="Catatan (opsional)" class="input-base h-10 w-48">
                                            <button type="submit" class="btn-destructive">Write-off</button>
                                        </form>
                                    </div>

                                    <hr class="my-5 border-border-subtle">

                                    <form method="GET" action="{{ route('inventory.karantina') }}" class="space-y-3 rounded-lg border border-border-subtle bg-canvas p-4">
                                        <input type="hidden" name="case" value="{{ $activeCase->case_no }}">
                                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                            <x-ui.field label="Kata kunci" name="q">
                                                <input type="text" id="q" name="q" value="{{ $candidates['form']['q'] ?? '' }}" class="input-base" placeholder="nama produk">
                                            </x-ui.field>
                                            <x-ui.field label="Seri" name="seri">
                                                <input type="text" id="seri" name="seri" value="{{ $candidates['form']['seri'] ?? '' }}" class="input-base" placeholder="cth: American Scene">
                                            </x-ui.field>
                                            <x-ui.field label="Warna" name="warna">
                                                <input type="text" id="warna" name="warna" value="{{ $candidates['form']['warna'] ?? '' }}" class="input-base" placeholder="cth: Oxford White">
                                            </x-ui.field>
                                            <x-ui.field label="Kondisi" name="kondisi">
                                                <select id="kondisi" name="kondisi" class="select-base">
                                                    <option value="">Semua</option>
                                                    @foreach ($conditions as $value => $label)
                                                        <option value="{{ $value }}" @selected(($candidates['form']['kondisi'] ?? '') === $value)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </x-ui.field>
                                            <div class="flex items-end">
                                                <button type="submit" class="btn-secondary w-full">Cari Kandidat</button>
                                            </div>
                                        </div>
                                        <p class="text-label-sm text-text-subtle">Kandidat terurut berdasarkan skor kecocokan. Assign hanya sah bila rekonsiliasi <span class="font-mono">V = S − (C + Q) = 0</span>.</p>
                                    </form>

                                    @if (($candidates['ambiguous'] ?? false) === true)
                                        <x-ui.banner tone="warning">Beberapa pemilik punya produk &amp; kondisi yang sama. Pastikan bukti sebelum assign; kandidat ambigu ditandai dan butuh konfirmasi.</x-ui.banner>
                                    @endif

                                    <div class="space-y-3">
                                        @forelse ($candidates['lots'] as $candidate)
                                            <div class="rounded-lg border border-border-subtle p-4">
                                                <div class="flex items-start justify-between gap-3">
                                                    <div class="min-w-0">
                                                        <p class="text-body-md font-medium text-text-strong">{{ $candidate['product'] }}</p>
                                                        <p class="font-mono text-sku text-text-subtle">{{ $candidate['sku'] }}</p>
                                                        <p class="mt-1 text-label-sm text-text-muted">
                                                            {{ $candidate['series'] ?? '—' }}
                                                            @if ($candidate['color'])
                                                                · {{ $candidate['color'] }}
                                                            @endif
                                                            @if ($candidate['year'])
                                                                · {{ $candidate['year'] }}
                                                            @endif
                                                        </p>
                                                        <p class="mt-0.5 text-label-sm text-text-muted">{{ $candidate['condition'] }} · {{ $candidate['owner'] }}</p>
                                                    </div>
                                                    <div class="grid shrink-0 gap-1 text-right">
                                                        <x-ui.badge-status :type="$candidate['ambiguous'] ? 'warning' : 'success'">skor {{ $candidate['score'] }}</x-ui.badge-status>
                                                        <span class="text-label-sm text-text-subtle">rak {{ $candidate['rack'] }}</span>
                                                    </div>
                                                </div>

                                                <div class="mt-3 flex flex-wrap items-end justify-between gap-3 border-t border-border-subtle pt-3">
                                                    <div class="flex flex-wrap gap-5">
                                                        <div>
                                                            <p class="text-label-sm text-text-subtle">Tersedia (S)</p>
                                                            <p class="text-body-md font-semibold tabular-nums">{{ Format::number($candidate['available']) }}</p>
                                                        </div>
                                                        <div>
                                                            <p class="text-label-sm text-text-subtle">Harga jual</p>
                                                            <p class="text-body-md font-semibold tabular-nums">{{ Format::rupiah($candidate['price']) }}</p>
                                                        </div>
                                                    </div>

                                                    <form method="POST" action="{{ route('inventory.karantina.assign', $activeCase) }}" class="flex flex-wrap items-center gap-3">
                                                        @csrf
                                                        <input type="hidden" name="lot_id" value="{{ $candidate['id'] }}">
                                                        <label class="flex items-center gap-1.5 text-label-sm text-text-muted">
                                                            C (fisik berlabel)
                                                            <input type="number" name="counted_c" min="0" step="1" value="{{ old('counted_c', '') }}" class="input-base h-10 w-24" placeholder="0">
                                                        </label>
                                                        @if ($candidate['ambiguous'])
                                                            <label class="flex items-center gap-1.5 text-label-sm text-warning-text">
                                                                <input type="checkbox" name="confirm" value="1"> Konfirmasi pemilik
                                                            </label>
                                                        @endif
                                                        <button type="submit" class="btn-primary">Assign</button>
                                                    </form>
                                                </div>
                                            </div>
                                        @empty
                                            <x-ui.empty-state
                                                title="Tidak ada kandidat cocok"
                                                description="Sesuaikan kata kunci atau filter di atas, atau eskalasi kasus ini."
                                            />
                                        @endforelse
                                    </div>
                                </x-ui.section-card>
                            </div>

                            <div x-show="current().caseId !== @js($activeCase->case_no)">
                                <x-ui.banner tone="info">
                                    Panel resolusi terbuka untuk kasus
                                    <span class="font-mono">{{ $activeCase->case_no }}</span> di pilihan saat ini.
                                    <a href="{{ route('inventory.karantina', ['case' => $activeCase->case_no]) }}" class="font-semibold underline">Buka resolusi →</a>
                                </x-ui.banner>
                            </div>
                        @endif
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>