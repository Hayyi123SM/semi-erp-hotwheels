<x-ui.page-header
    :title="$schema['title'].' — Impor Excel'"
    :subtitle="'Unggah berkas Excel untuk menghasilkan draft '.$schema['title'].' yang bisa disimpan.'"
    :crumbs="$crumbs"
>
</x-ui.page-header>

<x-forms.import-step
    :action="$action"
    :template-url="$templateUrl"
    :list-url="$listUrl"
/>
