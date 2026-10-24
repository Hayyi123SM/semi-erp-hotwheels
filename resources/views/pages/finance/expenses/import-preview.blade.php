@use('App\Support\Format')

<x-ui.page-header
    title="Preview Import Pengeluaran"
    subtitle="Periksa data sebelum disimpan."
    :crumbs="['Keuangan', 'Pengeluaran', 'Import', 'Preview']"
>
</x-ui.page-header>

<div class="space-y-6">
    <x-ui.section-card title="Data Preview (max 100 baris)" :pad="false">
        <div class="table-scroll">
            <table class="w-full min-w-[1100px] text-left">
                <thead class="thead-dense">
                    <tr>
                        <th class="px-6 py-3 font-semibold">Tanggal</th>
                        <th class="px-6 py-3 font-semibold">Kategori</th>
                        <th class="px-6 py-3 font-semibold">Jumlah</th>
                        <th class="px-6 py-3 font-semibold">Keterangan</th>
                        <th class="px-6 py-3 font-semibold">Vendor</th>
                        <th class="px-6 py-3 font-semibold">Metode</th>
                        <th class="px-6 py-3 font-semibold">Referensi</th>
                        <th class="px-6 py-3 font-semibold">Catatan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-subtle">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-6 py-3 text-body-sm text-text-muted">{{ $row['expense_date'] ?? '' }}</td>
                            <td class="px-6 py-3 text-body-sm text-text-strong">{{ $row['expense_category'] ?? '—' }}</td>
                            <td class="px-6 py-3 text-right tabular-nums text-body-sm">{{ $row['amount'] ?? '' }}</td>
                            <td class="px-6 py-3 text-body-sm text-text-strong">{{ Str::limit($row['description'] ?? '', 60) }}</td>
                            <td class="px-6 py-3 text-body-sm text-text-muted">{{ $row['vendor_name'] ?? '—' }}</td>
                            <td class="px-6 py-3 text-body-sm text-text-muted">{{ $row['payment_method'] ?? '—' }}</td>
                            <td class="px-6 py-3 text-body-sm text-text-muted">{{ $row['reference_no'] ?? '—' }}</td>
                            <td class="px-6 py-3 text-body-sm text-text-muted">{{ Str::limit($row['notes'] ?? '', 40) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.section-card>

    <x-ui.section-card title="Konfirmasi Import">
        <form method="POST" action="{{ route('finance.expenses.import.store') }}" class="space-y-4">
            @csrf
            @foreach ($rows as $i => $row)
                @php
                    $catId = null;
                    if (!empty($row['expense_category'])) {
                        $catId = array_search($row['expense_category'], $categories, true);
                    }
                @endphp
                <input type="hidden" name="rows[{{ $i }}][expense_date]" value="{{ $row['expense_date'] ?? '' }}">
                <input type="hidden" name="rows[{{ $i }}][expense_category_id]" value="{{ $catId ?: '' }}">
                <input type="hidden" name="rows[{{ $i }}][amount]" value="{{ preg_replace('/\D/', '', (string) ($row['amount'] ?? 0)) }}">
                <input type="hidden" name="rows[{{ $i }}][description]" value="{{ $row['description'] ?? '' }}">
                <input type="hidden" name="rows[{{ $i }}][vendor_name]" value="{{ $row['vendor_name'] ?? '' }}">
                <input type="hidden" name="rows[{{ $i }}][payment_method]" value="{{ strtoupper($row['payment_method'] ?? '') }}">
                <input type="hidden" name="rows[{{ $i }}][reference_no]" value="{{ $row['reference_no'] ?? '' }}">
                <input type="hidden" name="rows[{{ $i }}][notes]" value="{{ $row['notes'] ?? '' }}">
            @endforeach
            <p class="text-body-sm text-text-muted">Total baris yang akan diimport: <strong>{{ count($rows) }}</strong>. Kategori akan dicocokkan berdasarkan nama persis.</p>
            <div class="flex items-center gap-3">
                <button class="btn-primary">Import Sekarang</button>
                <a href="{{ route('finance.expenses.import') }}" class="btn-secondary">Kembali</a>
            </div>
        </form>
    </x-ui.section-card>
</div>
