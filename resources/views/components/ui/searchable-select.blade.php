@props(['placeholder' => 'Pilih…', 'invalid' => false])

<div
    x-data="searchableSelect({ placeholder: @js($placeholder), invalid: @js($invalid) })"
    @keydown.esc.window="closePanel()"
    class="{{ $attributes->get('class') }}"
>
    <!-- Tombol tampilan; select native (sr-only) tetap jadi sumber nilai & submit. -->
    <button type="button" x-ref="button" @click="toggle()" @keydown="onTriggerKeydown($event)"
            :aria-expanded="open" aria-haspopup="listbox"
            class="flex h-11 w-full items-center justify-between gap-2 rounded-lg border border-border-subtle bg-surface-lowest px-3 text-left transition select-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
            :class="triggerClass">
        <span class="truncate" :class="displayLabel === placeholder ? 'text-text-muted' : 'text-text-strong'" x-text="displayLabel"></span>
        <svg class="h-4 w-4 shrink-0 text-text-muted transition" :class="open && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
    </button>

    {{ $slot }}

    {{--
        Panel ikut pindah ke body (lihat `portal()` di JS) dan diposisikan dengan
        `position: fixed`.

        Satu alasan, soal ancestor: `position: absolute` hanya hidup di kotak yang
        di-clip oleh `overflow` ancestor — di grid batch entry itu
        `div.table-scroll` (`overflow-x: auto`, yang berarti `overflow-y` ikut
        jadi `auto`), sehingga panel ikut terpotong di batas bawah tabel dan
        wheel di atas grid justru menggerakkan container itu, bukan halaman.
        Berada di luar subtree itu memastikan tidak ada clip ancestor yang bisa
        menyentuhnya.

        `z-50` sama dengan modal/drawer, dan panel menjadi anak terakhir body
        sehingga urutan DOM membuatnya menang atas keduanya. Toast (`z-[60]`)
        tetap di atas.

        `flex flex-col` + `flex-1 min-h-0` pada daftar membuat tinggi daftar
        mengikuti ruang yang tersisa tanpa perlu angka untuk tinggi input.

        Posisi dan tinggi ditulis `reposition()` langsung ke `style` panel, bukan
        lewat `:style`: `x-bind:style` menulis ulang seluruh atribut `style`
        saat dievaluasi ulang, yang akan menghapus hasil pengukuran kita.

        Panel adalah anak terakhir komponen ini, jadi `unportal()` bisa
        mengembalikannya hanya dengan `appendChild` ke root.
    --}}
    <div x-ref="panel" x-show="open" x-cloak x-transition.opacity
         class="fixed z-50 flex max-h-80 flex-col overflow-hidden rounded-lg border border-border-subtle bg-surface-lowest shadow-lg">
        <input x-ref="search" type="text" role="combobox" :aria-expanded="open"
               placeholder="Ketik untuk mencari…"
               :value="query"
               @input="query = $el.value; settleActiveIndex(); reposition()"
               @keydown="onSearchKeydown($event)"
               class="m-2 w-[calc(100%-1rem)] shrink-0 rounded-lg border border-border-subtle bg-canvas px-3 py-2 text-body-sm outline-none placeholder:text-text-subtle focus:border-primary">
        <ul class="min-h-0 flex-1 divide-y divide-border-subtle overflow-auto text-body-sm">
            <template x-for="(option, index) in options" :key="option.value">
                <li role="option" :aria-selected="activeIndex === index"
                    @mousedown.prevent="choose(index)" @mouseenter="activeIndex = index"
                    class="cursor-pointer px-3 py-2"
                    :class="activeIndex === index ? 'bg-primary-soft text-primary' : 'text-text-strong'"
                    x-text="option.label"></li>
            </template>
            <li x-show="options.length === 0" class="px-3 py-2 text-text-subtle">Tidak ditemukan</li>
        </ul>
    </div>
</div>