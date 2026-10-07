@props(['allowImport' => false, 'manual' => null, 'import' => null])

<div x-data="{ tab: 'manual' }">
    <div class="mb-5 inline-flex items-center gap-0.5 rounded-lg bg-canvas p-1" role="tablist" aria-label="Metode input data">
        <button type="button" role="tab" @click="tab = 'manual'"
                class="rounded-md px-4 py-2 text-label-md transition"
                :class="tab === 'manual' ? 'bg-text-strong text-white shadow-sm' : 'text-text-muted hover:text-text-strong'">
            Input Manual
        </button>
        @if ($allowImport)
            <button type="button" role="tab" @click="tab = 'import'"
                    class="rounded-md px-4 py-2 text-label-md transition"
                    :class="tab === 'import' ? 'bg-text-strong text-white shadow-sm' : 'text-text-muted hover:text-text-strong'">
                Import Excel
            </button>
        @endif
    </div>

    <div x-show="tab === 'manual'">
        {{ $manual }}
    </div>

    @if ($allowImport)
        <div x-show="tab === 'import'" x-cloak>
            {{ $import }}
        </div>
    @endif
</div>