@php
    $isCreate = ! $rack->exists;
    $allowImport = $isCreate && $canManage;
    $action = $isCreate ? 'Tambah' : 'Edit';
    // Only an edit has a record to name, and naming it is what tells
    // two of the rows apart once the page is scrolled down or bookmarked.
    $subject = $isCreate ? '' : $rack->code;
@endphp

<x-ui.page-header
    :title="$action.' Rak'.($subject !== '' ? ' — '.$subject : '')"
    subtitle="Kode otomatis diubah huruf kapital, contoh A-S1-L1."
    :crumbs="[
        'Master Data',
        ['label' => 'Lokasi Rak', 'href' => route('master.lokasi-rak')],
        $subject !== '' ? $action.' · '.$subject : $action,
    ]"
>
    <x-slot:actions>
        <a href="{{ route('master.lokasi-rak') }}" class="btn-secondary">Kembali ke Daftar</a>
    </x-slot:actions>
</x-ui.page-header>

<x-forms.form-tabs :allow-import="$allowImport">
    <x-slot:manual>
        @include('pages.master.partials.rak-form', [
            'rack' => $rack,
            'isCreate' => $isCreate,
        ])
    </x-slot:manual>

    @if ($allowImport)
        <x-slot:import>
            <x-forms.import-step :action="route('master.import.upload', 'rak')" :template-url="route('master.import.template', 'rak')" :list-url="route('master.lokasi-rak')" label="Pilih berkas rak (.xlsx / .xls / .csv)" hint="Aktif diisi YA/TIDAK, kapasitas berupa angka." />
        </x-slot:import>
    @endif
</x-forms.form-tabs>