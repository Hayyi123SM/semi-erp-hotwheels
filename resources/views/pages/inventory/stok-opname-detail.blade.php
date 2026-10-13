@use('App\Support\Format')

@php
    $facts = [
        'Cakupan' => $cakupan,
        'Dimulai' => Format::datetime($opname->started_at),
        'Diajukan' => $opname->submitted_at === null ? Format::EMPTY : Format::datetime($opname->submitted_at),
        'Ditutup' => $opname->closed_at === null ? Format::EMPTY : Format::datetime($opname->closed_at),
        'Dimulai oleh' => $opname->creator?->name ?? 'Sistem',
    ];
@endphp

<x-ui.page-header
    title="Detail Sesi"
    :subtitle="$opname->opname_no"
    :crumbs="[
        ['label' => 'Inventory', 'href' => route('inventory.live-stock')],
        ['label' => 'Stok Opname', 'href' => route('inventory.stok-opname')],
        'Detail Sesi',
    ]"
>
    <x-slot:actions>
        <a href="{{ route('inventory.stok-opname') }}" class="btn-secondary">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5m7-7l-7 7 7 7"/></svg>
            Kembali ke Stok Opname
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <x-ui.section-card>
        <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
            <x-ui.badge-status :type="Format::statusType($opname->status->value)">
                {{ Format::statusLabel($opname->status->value) }}
            </x-ui.badge-status>

            <dl class="flex flex-wrap gap-x-8 gap-y-4">
                @foreach ($facts as $label => $value)
                    <div>
                        <dt class="text-label-sm uppercase tracking-wide text-text-muted">{{ $label }}</dt>
                        <dd class="mt-0.5 text-body-md font-medium text-text-strong tabular-nums">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </x-ui.section-card>

    @if ($blind)
        {{-- Sesi ini bisa dibuka lewat URL langsung walau masih menghitung.
             Angka sistem dan selisih tidak pernah dipilih controller, jadi di
             sini pun tidak ada yang bisa ditampilkan -- hanya progres hitung. --}}
        <x-ui.banner tone="warning">
            <strong>Sesi masih menghitung.</strong>
            Angka sistem dan selisih belum dikirim ke layar ini; keduanya baru muncul setelah sesi diajukan untuk direview.
        </x-ui.banner>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Total Lot" :value="$summary['total']" delta="baris dalam cakupan" delta-tone="info" />
        @if ($blind)
            <x-ui.stat-card label="Sudah Dihitung" :value="$summary['terhitung']" delta="lot terisi" delta-tone="info" />
        @else
            <x-ui.stat-card label="Lot Berselisih" :value="$summary['selisih']" delta="selisih menunggu/terputus" :delta-tone="$summary['selisih'] > 0 ? 'warning' : 'success'" />
            <x-ui.stat-card label="Disetujui" :value="$summary['approved']" delta="selisih diterapkan" delta-tone="success" />
            <x-ui.stat-card label="Ditolak" :value="$summary['rejected']" delta="stok dibiarkan" delta-tone="error" />
        @endif
    </div>

    <x-ui.section-card title="Baris Sesi" :pad="false">
        <x-ui.data-table :table="$linesTable" />
    </x-ui.section-card>

    <x-ui.section-card title="Jejak Audit">
        @if ($timeline->isEmpty())
            <p class="text-body-sm text-text-muted">Belum ada jejak audit untuk sesi ini.</p>
        @else
            <ol class="space-y-4">
                @foreach ($timeline as $log)
                    <li class="flex gap-4">
                        <div class="flex flex-col items-center">
                            <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-primary"></span>
                            @unless ($loop->last)
                                <span class="mt-1 w-px flex-1 bg-border-subtle"></span>
                            @endunless
                        </div>
                        <div class="min-w-0 flex-1 pb-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <x-ui.audit-action-badge :row="$log" />
                                <span class="text-body-sm text-text-strong">{{ $log->user?->name ?? 'Sistem' }}</span>
                                <span class="text-label-sm text-text-subtle">{{ Format::datetime($log->created_at) }}</span>
                            </div>
                            <div class="mt-1">
                                <x-ui.audit-diff :row="$log" />
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-ui.section-card>
</div>
