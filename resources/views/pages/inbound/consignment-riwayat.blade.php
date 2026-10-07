<x-ui.page-header
    title="Riwayat Konsinyasi"
    subtitle="Dokumen penerimaan barang titipan yang sudah di-commit."
    :crumbs="['Inbound', 'Consignment In', 'Riwayat']"
>
    <x-slot:actions>
        <a href="{{ route('inbound.consignment-in') }}" class="btn-primary">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 4v16m8-8H4"/></svg>
            Consignment In
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="space-y-6">
    <x-ui.section-card>
        <x-ui.data-table :table="$table" />
    </x-ui.section-card>
</div>