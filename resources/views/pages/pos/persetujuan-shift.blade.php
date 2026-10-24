@use('App\Support\Format')

<x-ui.page-header
    title="Persetujuan Shift"
    subtitle="Hitungan laci kasir yang selisihnya melewati ambang menunggu keputusanmu."
    :crumbs="['POS / Kasir', 'Persetujuan Shift']"
>
</x-ui.page-header>

<div class="space-y-6">
    @forelse ($pending as $row)
        @php
            $shift = $row['shift'];
            $summary = $row['summary'];
            $overThreshold = abs($shift->cash_diff ?? 0) > $cashThreshold;
        @endphp

        <x-ui.section-card :title="'Shift #'.$shift->id.' · '.($shift->user?->name ?? 'Kasir tidak diketahui')">
            <div class="space-y-5">
                @if ($overThreshold)
                    <x-ui.banner tone="warning">
                        Selisih {{ Format::rupiah(abs($shift->cash_diff ?? 0)) }} melewati ambang
                        {{ Format::rupiah($cashThreshold) }}. Setujui hanya kalau penjelasannya masuk akal.
                    </x-ui.banner>
                @endif

                <dl class="grid gap-3 rounded border border-border-subtle bg-canvas px-4 py-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-label-md text-text-muted">Dibuka</dt>
                        <dd class="text-body-md font-medium text-text-strong">{{ Format::datetime($shift->opened_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-label-md text-text-muted">Diajukan</dt>
                        <dd class="text-body-md font-medium text-text-strong">{{ Format::datetime($shift->closing_requested_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-label-md text-text-muted">Perangkat</dt>
                        <dd class="text-body-md font-medium text-text-strong">{{ $shift->device_id ?? 'Tidak terdeteksi' }}</dd>
                    </div>
                    <div>
                        <dt class="text-label-md text-text-muted">Transaksi</dt>
                        <dd class="text-body-md font-medium text-text-strong">{{ $summary->saleCount }} nota · {{ Format::rupiah($summary->saleTotal) }}</dd>
                    </div>
                    <div>
                        <dt class="text-label-md text-text-muted">Uang pembuka</dt>
                        <dd class="text-body-md font-medium text-text-strong tabular-nums">{{ Format::rupiah($summary->openingCash) }}</dd>
                    </div>
                    <div>
                        <dt class="text-label-md text-text-muted">Seharusnya di laci</dt>
                        <dd class="text-body-md font-medium text-text-strong tabular-nums">{{ Format::rupiah($summary->expectedCash) }}</dd>
                    </div>
                    <div>
                        <dt class="text-label-md text-text-muted">Dihitung kasir</dt>
                        <dd class="text-body-md font-medium text-text-strong tabular-nums">{{ Format::rupiah($shift->closing_cash ?? 0) }}</dd>
                    </div>
                    <div>
                        <dt class="text-label-md text-text-muted">Selisih</dt>
                        <dd class="text-body-md font-semibold tabular-nums {{ ($shift->cash_diff ?? 0) === 0 ? 'text-success-text' : 'text-warning-text' }}">
                            {{ Format::rupiah($shift->cash_diff ?? 0) }}
                        </dd>
                    </div>
                </dl>

                @if ($shift->closing_notes)
                    <div>
                        <p class="text-label-md text-text-muted">Catatan kasir</p>
                        <p class="mt-1 whitespace-pre-line text-body-md text-text-strong">{{ $shift->closing_notes }}</p>
                    </div>
                @endif

                <div class="flex flex-wrap items-start gap-3 border-t border-border-subtle pt-5">
                    <form method="POST" action="{{ route('pos.persetujuan-shift.approve', $shift) }}">
                        @csrf
                        <button type="submit" class="btn-primary">Setujui &amp; Tutup Shift</button>
                    </form>

                    <details class="w-full max-w-xl">
                        <summary class="btn-secondary inline-flex cursor-pointer">Tolak Hitungan</summary>

                        <form method="POST" action="{{ route('pos.persetujuan-shift.reject', $shift) }}"
                              class="mt-3 space-y-3">
                            @csrf

                            <x-ui.field label="Alasan penolakan" name="reason" required
                                        hint="Kasir membaca ini saat menghitung ulang, jadi sebut apa yang perlu diperiksa.">
                                <textarea name="reason" rows="2" required maxlength="500"
                                          class="input-base @error('reason') border-error-border @enderror"
                                          placeholder="Contoh: uang kembalian Rp5.000 belum dikeluarkan dari laci.">{{ old('reason') }}</textarea>
                                @error('reason')<p class="mt-1 text-label-sm text-error-text">{{ $message }}</p>@enderror
                            </x-ui.field>

                            <button type="submit" class="btn-danger">Tolak &amp; Kembalikan ke Kasir</button>
                        </form>
                    </details>
                </div>
            </div>
        </x-ui.section-card>
    @empty
        <x-ui.section-card title="Tidak Ada yang Menunggu">
            <p class="text-body-md text-text-muted">
                Semua hitungan laci sudah diputuskan. Halaman ini terisi sendiri saat kasir mengirim
                tutup shift dengan selisih di luar ambang.
            </p>
        </x-ui.section-card>
    @endforelse
</div>
