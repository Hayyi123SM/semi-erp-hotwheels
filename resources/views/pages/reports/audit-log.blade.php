<x-ui.page-header
    title="Audit Log"
    subtitle="Log immutable (append-only). Setiap mutasi stok, pembayaran & perubahan data tercatat."
    :crumbs="['Reports & Analisis', 'Audit Log']"
>
    <x-slot:actions>
        <x-ui.badge-status type="error">APPEND-ONLY</x-ui.badge-status>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <x-ui.section-card>
        <x-ui.toolbar search-placeholder="Cari aktor, entitas, aksi...">
            <x-slot:filters>
                <select class="input-base h-11 w-auto">
                    <option>Semua Aksi</option>
                    <option>CREATE</option>
                    <option>UPDATE</option>
                    <option>DELETE</option>
                    <option>COMMIT</option>
                    <option>APPROVE</option>
                </select>
                <select class="input-base h-11 w-auto">
                    <option>Semua Entitas</option>
                    <option>SKU</option>
                    <option>RACK</option>
                    <option>TRANSACTION</option>
                    <option>SETTLEMENT</option>
                    <option>QUARANTINE</option>
                </select>
            </x-slot:filters>
        </x-ui.toolbar>

        <div class="space-y-2 px-6 pb-6">
            @foreach ([
                ['at' => '24 Sep 14:05:22', 'actor' => 'Ahmad Fauzi', 'device' => 'POS-CP2', 'action' => 'CREATE', 'entity' => 'TRANSACTION', 'label' => 'POS-CP2-2026-08913 · Rp505.000', 'diff' => true],
                ['at' => '24 Sep 13:47:10', 'actor' => 'Dewi Lestari', 'device' => 'CP1 · WMS', 'action' => 'MOVE', 'entity' => 'QUARANTINE', 'label' => 'Q-2026-017 → Rack Q-00-01', 'diff' => false],
                ['at' => '24 Sep 13:26:00', 'actor' => 'System', 'device' => 'SYNC', 'action' => 'COMMIT', 'entity' => 'TRANSACTION', 'label' => 'Batch 2 nota offline', 'diff' => false],
                ['at' => '24 Sep 12:58:44', 'actor' => 'Owner', 'device' => 'CP1 · Web', 'action' => 'CREATE', 'entity' => 'SETTLEMENT', 'label' => 'SET-2026-09-CN01 · DRAFT', 'diff' => false],
                ['at' => '24 Sep 12:41:12', 'actor' => 'Dewi Lestari', 'device' => 'CP2 · WMS', 'action' => 'UPDATE', 'entity' => 'SKU', 'label' => 'OW00-HW-003 · price 95000→100000', 'diff' => true],
            ] as $log)
                <div class="rounded-lg border border-border-subtle" x-data="{ expanded: false }">
                    <button type="button" class="flex w-full items-center gap-4 px-4 py-3 text-left transition hover:bg-canvas" @click="expanded = !expanded">
                        <span class="w-36 shrink-0 text-label-sm text-text-subtle tabular-nums">{{ $log['at'] }}</span>
                        <span class="w-32 shrink-0 text-body-sm font-medium text-text-strong">{{ $log['actor'] }}</span>
                        <span class="w-24 shrink-0 font-mono text-label-sm text-text-subtle">{{ $log['device'] }}</span>
                        <span class="w-24 shrink-0">
                            <x-ui.badge-status :type="$log['action'] === 'DELETE' ? 'error' : ($log['action'] === 'APPROVE' || $log['action'] === 'COMMIT' ? 'success' : 'info')">{{ $log['action'] }}</x-ui.badge-status>
                        </span>
                        <span class="w-28 shrink-0 text-label-sm text-text-muted">{{ $log['entity'] }}</span>
                        <span class="flex-1 truncate text-body-sm text-text-strong">{{ $log['label'] }}</span>
                        <svg class="h-4 w-4 shrink-0 text-text-subtle transition-transform" :class="expanded ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    @if ($log['diff'])
                        <div x-show="expanded" x-transition x-cloak class="border-t border-border-subtle bg-canvas px-12 py-4">
                            <div class="grid gap-2 text-label-sm sm:grid-cols-2">
                                <div class="rounded-lg border border-error-border bg-error-bg p-3">
                                    <p class="font-semibold text-error-text">Sebelum</p>
                                    <p class="mt-0.5 font-mono text-body-sm text-error-text">{{ $log['entity'] === 'SETTLEMENT' || $log['entity'] === 'TRANSACTION' ? 'total: Rp1.760.000' : 'price: 95.000 · rack: A-02-01' }}</p>
                                </div>
                                <div class="rounded-lg border border-success-border bg-success-bg p-3">
                                    <p class="font-semibold text-success-text">Sesudah</p>
                                    <p class="mt-0.5 font-mono text-body-sm text-success-text">{{ $log['entity'] === 'SETTLEMENT' || $log['entity'] === 'TRANSACTION' ? 'total: Rp1.840.000' : 'price: 100.000 · rack: A-02-01' }}</p>
                                </div>
                            </div>
                        </div>
                    @else
                        <div x-show="expanded" x-transition x-cloak class="border-t border-border-subtle bg-canvas px-12 py-4">
                            <p class="text-label-sm text-text-subtle">Tidak ada perubahan field kompleks. Metadata tersimpan di tabel audit (actor, device, IP, fingerprint).</p>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-ui.section-card>
</div>