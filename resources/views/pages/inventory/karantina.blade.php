<x-ui.page-header
    title="Karantina"
    subtitle="Barang tak teridentifikasi / rusak / barcode tidak terbaca. SLA: assign dalam 3 hari, eskalasi 7 hari."
    :crumbs="['Inventory', 'Karantina']"
>
    <x-slot:actions>
        <button type="button" class="btn-secondary" @click="$store.toast.push('Antrean diurutkan SLA', 'info')">Urutkan SLA</button>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Kasus Terbuka" value="{{ count($cases) }}" delta="SLA 3 hari" delta-tone="info" />
        <x-ui.stat-card label="Aging > 3 Hari" value="{{ collect($cases)->where('age', '>', 3)->count() }}" delta="⚠ Perlu review" delta-tone="warning" icon="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        <x-ui.stat-card label="Aging > 7 Hari" value="1" delta="🔴 Eskalasi Owner" delta-tone="error" />
        <x-ui.stat-card label="Unit KARANTINA" value="7" delta="Rak Q-00-01" delta-tone="warning" />
    </div>

    <div class="grid gap-6 lg:grid-cols-12" x-data="quarantineCalc">
        <!-- Panel 1: Queue -->
        <div class="lg:col-span-3 card">
            <div class="flex items-center justify-between border-b border-border-subtle px-5 py-4">
                <h2 class="text-headline-sm text-text-strong">Antrean</h2>
                <span class="rounded-full bg-karantina-bg px-2 py-0.5 text-label-sm text-karantina-text tabular-nums">{{ count($cases) }}</span>
            </div>
            <ul class="divide-y divide-border-subtle">
                @foreach ($cases as $case)
                    <li>
                        <button type="button"
                                class="w-full px-5 py-4 text-left transition hover:bg-canvas"
                                :class="selectedCase === @js($case['caseId']) ? 'bg-primary-soft' : ''"
                                @click="selectedCase = @js($case['caseId']); resetCase({{ $loop->iteration }})">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-mono text-sku text-text-strong">{{ $case['caseId'] }}</span>
                                <x-ui.badge-status :type="$case['severity'] === 'error' ? 'error' : 'warning'">
                                    {{ $case['age'] }} hari
                                </x-ui.badge-status>
                            </div>
                            <p class="mt-1.5 text-body-sm font-medium text-text-strong">{{ $case['model'] }}</p>
                            <p class="mt-0.5 text-label-sm text-text-muted">{{ $case['reason'] }} · tiba {{ $case['arrivedAt'] }}</p>
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>

        <!-- Panel 2: Attributes & Candidates -->
        <div class="lg:col-span-4 card" @keydown.window.f9.prevent="assign()">
            <div class="border-b border-border-subtle px-5 py-4">
                <h2 class="text-headline-sm text-text-strong" x-text="'Kasus terpilih: ' + selectedCase">Kasus terpilih</h2>
                <p class="text-label-sm text-text-muted">Tinjau bukti fisik & kandidat SKU sebelum assign.</p>
            </div>

            <div class="space-y-5 px-5 py-5">
                <x-ui.banner tone="warning">
                    <span class="font-semibold">Ambiguitas (BR-11):</span>
                    Barcode tidak terbaca pada unit ini. Verifikasi tekstur & casting sebelum assignment.
                </x-ui.banner>

                <div>
                    <p class="mb-2 text-label-md text-text-muted">Atribut Fisik</p>
                    <div class="grid grid-cols-2 gap-3 text-body-sm">
                        <div class="rounded-lg border border-border-subtle bg-canvas p-3">
                            <p class="text-label-sm text-text-subtle">Kasta / Label</p>
                            <p class="mt-0.5 font-medium text-text-strong">TIDAK ADA</p>
                        </div>
                        <div class="rounded-lg border border-border-subtle bg-canvas p-3">
                            <p class="text-label-sm text-text-subtle">Blister</p>
                            <p class="mt-0.5 font-medium text-text-strong">SEGEL RUSAK</p>
                        </div>
                        <div class="rounded-lg border border-border-subtle bg-canvas p-3">
                            <p class="text-label-sm text-text-subtle">Kemasan Datang</p>
                            <p class="mt-0.5 font-medium text-text-strong">Dos (1 pcs)</p>
                        </div>
                        <div class="rounded-lg border border-border-subtle bg-canvas p-3">
                            <p class="text-label-sm text-text-subtle">Aging</p>
                            <p class="mt-0.5 font-medium text-text-strong text-warning-text">6 HARI ⚠</p>
                        </div>
                    </div>
                </div>

                <div>
                    <p class="mb-2 text-label-md text-text-muted">Kandidat SKU (match score)</p>
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-border-subtle bg-canvas p-3">
                        <input type="radio" name="candidate" value="a" x-model="selectedCandidate" checked class="h-4 w-4 border-border-strong text-primary focus:ring-primary/30">
                        <span class="font-mono text-body-sm text-text-strong">CN02-HW-002</span>
                        <span class="text-label-sm text-text-muted">Ford Mustang GT · American Scene</span>
                        <span class="ml-auto rounded bg-primary-soft px-1.5 py-0.5 text-label-sm text-primary">93%</span>
                    </label>
                    <label class="mt-2 flex cursor-pointer items-center gap-3 rounded-lg border border-border-subtle bg-canvas p-3">
                        <input type="radio" name="candidate" value="b" x-model="selectedCandidate" class="h-4 w-4 border-border-strong text-primary focus:ring-primary/30">
                        <span class="font-mono text-body-sm text-text-strong">OW00-HW-004</span>
                        <span class="text-label-sm text-text-muted">Mazda 787B · Car Culture</span>
                        <span class="ml-auto rounded bg-canvas px-1.5 py-0.5 text-label-sm text-text-subtle">71%</span>
                    </label>
                </div>

                <div>
                    <p class="mb-2 text-label-md text-text-muted">Bukti Verifikasi</p>
                    <div class="grid gap-2 sm:grid-cols-3">
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-border-subtle p-3 text-body-sm" :class="evidence.missingLabel ? 'border-primary bg-primary-soft' : ''">
                            <input type="checkbox" x-model="evidence.missingLabel" class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/30" @change>
                            <span class="text-label-md">Label hilang</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-border-subtle p-3 text-body-sm" :class="evidence.damagedBlister ? 'border-primary bg-primary-soft' : ''">
                            <input type="checkbox" x-model="evidence.damagedBlister" class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/30">
                            <span class="text-label-md">Blister rusak</span>
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-border-subtle p-3 text-body-sm" :class="evidence.unidentified ? 'border-primary bg-primary-soft' : ''">
                            <input type="checkbox" x-model="evidence.unidentified" class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/30">
                            <span class="text-label-md">Tidak teridentifikasi</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Panel 3: Variance calculator -->
        <div class="lg:col-span-5 card">
            <div class="flex items-center justify-between border-b border-border-subtle px-5 py-4">
                <h2 class="text-headline-sm text-text-strong">Kalkulator Selisih Stok</h2>
                <span class="rounded bg-canvas px-2 py-0.5 font-mono text-label-sm text-text-muted">V = S − (C + Q)</span>
            </div>

            <div class="space-y-5 px-5 py-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.field label="Sistem (S)" name="q_s">
                        <input id="q_s" type="number" x-model.number="system" class="input-base text-right tabular-nums" value="4">
                    </x-ui.field>
                    <x-ui.field label="Hitung (C)" name="q_c">
                        <input id="q_c" type="number" x-model.number="counted" class="input-base text-right tabular-nums" value="2">
                    </x-ui.field>
                    <x-ui.field label="Karantina (Q)" name="q_q">
                        <input id="q_q" type="number" x-model.number="quarantine" class="input-base text-right tabular-nums" value="1">
                    </x-ui.field>
                </div>

                <div class="rounded-lg border border-border-subtle bg-canvas p-4"
                     :class="tone === 'error' ? 'border-error-border bg-error-bg' : tone === 'warning' ? 'border-warning-border bg-warning-bg' : 'border-success-border bg-success-bg'">
                    <p class="text-label-md" :class="tone === 'error' ? 'text-error-text' : tone === 'warning' ? 'text-warning-text' : 'text-success-text'" x-text="tone === 'error' ? 'SELISIH NEGATIF' : tone === 'warning' ? 'SELISIH POSITIF' : 'SESUAI'"></p>
                    <p class="mt-1 text-display-total font-bold tabular-nums" :class="tone === 'error' ? 'text-error-text' : tone === 'warning' ? 'text-warning-text' : 'text-success-text'"
                       x-text="(variance >= 0 ? '+' : '') + variance"></p>
                    <p class="text-label-sm" :class="tone === 'error' ? 'text-error-text' : tone === 'warning' ? 'text-warning-text' : 'text-success-text'">
                        <span x-show="tone === 'error'">Disable tombol assign. Selidiki salah hitung.</span>
                        <span x-show="tone === 'warning'">Ditemukan unit tak tercatat. Lanjutkan dengan dokumen fisik.</span>
                        <span x-show="tone === 'success'">Tidak ada selisih. Aman untuk di-assign.</span>
                    </p>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="button" class="btn-primary flex-1 disabled:opacity-40" :disabled="!canAssign" @click="assign()">
                        Assign SKU
                        <span class="rounded bg-on-primary/20 px-1.5 py-0.5 font-mono text-label-sm">F9</span>
                    </button>
                    <button type="button" class="btn-secondary flex-1" @click="escalate()">Eskalasi Ke Owner</button>
                    <button type="button" class="btn-destructive flex-1" @click="$store.toast.push('Write-off diminta (mock)', 'warning')">Write-off</button>
                </div>

                <div class="border-t border-border-subtle pt-4">
                    <p class="mb-2 text-label-md text-text-muted">Timeline kasus</p>
                    <ol class="space-y-2 text-body-sm">
                        <li class="flex gap-2"><span class="text-text-subtle">18 Sep 10:12</span><span class="text-text-strong">Diterima di karantina (Q-00-01)</span></li>
                        <li class="flex gap-2"><span class="text-text-subtle">19 Sep 09:00</span><span class="text-text-strong">Foto bukti unggah</span></li>
                        <li class="flex gap-2"><span class="text-warning-text">24 Sep 14:05</span><span class="text-text-strong">SLA menipis · aksi hari ini</span></li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>