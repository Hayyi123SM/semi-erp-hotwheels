<x-ui.page-header
    title="Audit Log"
    subtitle="Jejak append-only: setiap mutasi stok, pembayaran, dan perubahan data tercatat sekali dan tidak bisa diubah."
    :crumbs="['Reports & Analisis', 'Audit Log']"
>
    <x-slot:actions>
        <x-ui.badge-status type="neutral">APPEND-ONLY</x-ui.badge-status>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <x-ui.section-card>
        <x-ui.data-table :table="$table">
            <x-slot:filters>
                {{--
                    Opsi aksinya dari enum, opsi entitasnya dari database.

                    Urutan dropdown mengikuti label yang dibaca, dan baris tanpa
                    aksi yang dikenal tetap bisa difilter lewat kotak pencarian
                    -- tidak lewat sini, karena dropdown-nya berisi kode.
                --}}
                <select name="action" class="select-base filter-select" aria-label="Filter aksi">
                    <option value="">Semua aksi</option>
                    @foreach ($actionOptions as $value => $label)
                        <option value="{{ $value }}" @selected(request()->query('action') === (string) $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>

                <select name="entity" class="select-base filter-select" aria-label="Filter entitas">
                    <option value="">Semua entitas</option>
                    @foreach ($entityOptions as $entity)
                        <option value="{{ $entity }}" @selected(request()->query('entity') === $entity)>
                            {{ $entity }}
                        </option>
                    @endforeach
                </select>

                <label class="filter-chip">
                    <input type="checkbox" name="has_changes" value="1"
                           class="h-4 w-4 rounded border-border-strong text-primary focus:ring-primary/30"
                           @checked(request()->boolean('has_changes'))>
                    Hanya yang punya perubahan
                </label>
            </x-slot:filters>
        </x-ui.data-table>
    </x-ui.section-card>
</div>