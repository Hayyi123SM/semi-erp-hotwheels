<x-ui.page-header
    title="Riwayat Transaksi"
    subtitle="Nota per shift, kasir & metode. Void/refund memerlukan PIN Owner."
    :crumbs="['POS / Kasir', 'Riwayat Transaksi']"
>
    <x-slot:actions>
        {{--
            Jumlah nota yang sedang difilter, dari paginator yang sama dengan
            tabelnya. Angka hard-coded seperti "4.215.000" terlihat benar sampai
            kebetulan salah, dan yang salah di halaman ini hilang di antara nota
            yang benar.
        --}}
        <x-ui.badge-status :type="$table->rows()->total() > 0 ? 'info' : 'neutral'">
            {{ $table->rows()->total() }} nota
        </x-ui.badge-status>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <x-ui.section-card>
        <x-ui.data-table :table="$table">
            <x-slot:filters>
                {{--
                    Opsi shift dari database, dibatasi ke shift milik sendiri
                    kecuali yang membuka adalah Owner -- sama persis dengan batas
                    tabelnya. Selector yang menawarkan semua shift ke kasir adalah
                    filter yang selalu mengembalikan kosong, dan yang kosong di sini
                    terbaca sebagai "tidak ada transaksi".
                --}}
                <select name="shift" class="select-base filter-select" aria-label="Filter shift">
                    <option value="">Semua Shift</option>
                    @foreach ($shiftOptions as $shift)
                        <option value="{{ $shift->id }}" @selected(request()->query('shift') === (string) $shift->id)>
                            Shift {{ $shift->id }}
                            @if ($shift->opened_at)
                                &middot; {{ $shift->opened_at->translatedFormat('d M Y H:i') }}
                            @endif
                        </option>
                    @endforeach
                </select>

                <select name="method" class="select-base filter-select" aria-label="Filter metode pembayaran">
                    <option value="">Semua Metode</option>
                    @foreach ($methodOptions as $value => $label)
                        <option value="{{ $value }}" @selected(request()->query('method') === (string) $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>

                <select name="status" class="select-base filter-select" aria-label="Filter status nota">
                    <option value="">Semua Status</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}" @selected(request()->query('status') === (string) $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>

                <label class="filter-chip">
                    <input type="checkbox" name="pending_sync" value="1"
                           class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/30"
                           @checked(request()->boolean('pending_sync'))>
                    Belum sinkron
                </label>
            </x-slot:filters>
        </x-ui.data-table>
    </x-ui.section-card>
</div>
