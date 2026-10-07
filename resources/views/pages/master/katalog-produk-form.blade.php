@php
    $isCreate = ! $product->exists;
    $allowImport = $isCreate && $canManage;
    $action = $isCreate ? 'Tambah' : 'Edit';
    // Only an edit has a record to name, and naming it is what tells
    // two of the rows apart once the page is scrolled down or bookmarked.
    $subject = $isCreate ? '' : $product->name;
@endphp

<x-ui.page-header
    :title="$action.' Produk'.($subject !== '' ? ' — '.$subject : '')"
    subtitle="Definisi produk katalog. Detail lot & SKU dibuat saat proses stock-in."
    :crumbs="[
        'Master Data',
        ['label' => 'Katalog Produk', 'href' => route('master.katalog-produk')],
        $subject !== '' ? $action.' · '.$subject : $action,
    ]"
>
    <x-slot:actions>
        <a href="{{ route('master.katalog-produk') }}" class="btn-secondary">Kembali ke Daftar</a>
    </x-slot:actions>
</x-ui.page-header>

<x-forms.form-tabs :allow-import="$allowImport">
    <x-slot:manual>
        @include('pages.master.partials.katalog-form', [
            'product' => $product,
            'series' => $series,
            'isCreate' => $isCreate,
            'canManage' => $canManage,
        ])
    </x-slot:manual>

    @if ($allowImport)
        <x-slot:import>
            <x-forms.import-step :action="route('master.import.upload', 'katalog')" :template-url="route('master.import.template', 'katalog')" :list-url="route('master.katalog-produk')" label="Pilih berkas katalog (.xlsx / .xls / .csv)" hint="Nama produk bisa digabung dari 2 kolom. Harga minimal Rp0." />
        </x-slot:import>
    @endif
</x-forms.form-tabs>