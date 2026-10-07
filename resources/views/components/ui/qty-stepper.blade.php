@props(['min' => 1, 'max' => 999, 'value' => 1, 'disabled' => false, 'field' => null])

{{--
     Tanpa `field`, angka ini hanya milik Alpine: ia naik-turun di layar tetapi
     tidak pernah ikut terkirim saat form disubmit. Dulu pemanggil mengatasinya
     dengan `x-model` pada elemen pembungkus, dan `x-model` pada `div` tidak
     menghasilkan apa pun -- angkanya tampak berubah sementara server menerima
     angka bawaan kolom. Dengan `field`, angka yang sama ikut serta dalam form.
--}}
<div {{ $attributes->merge(['class' => 'inline-flex items-center rounded-lg border border-border-strong bg-surface-lowest']) }} x-data="{ qty: {{ $value }}, min: {{ $min }}, max: {{ $max }} }">
    <button type="button"
            @click="qty = Math.max(min, qty - 1)"
            @disabled($disabled)
            class="flex h-9 w-9 items-center justify-center rounded-l-lg text-text-muted transition hover:bg-canvas hover:text-text-strong disabled:opacity-40"
            aria-label="Kurangi">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12H4"/></svg>
    </button>
    <input type="number"
           x-model.number="qty"
           @disabled($disabled)
           @if($field) name="{{ $field }}" @endif
           min="{{ $min }}"
           max="{{ $max }}"
           inputmode="numeric"
           class="h-9 w-12 border-x border-border-subtle bg-transparent text-center text-body-md font-semibold text-text-strong outline-none focus:bg-canvas">
    <button type="button"
            @click="qty = Math.min(max, qty + 1)"
            @disabled($disabled)
            class="flex h-9 w-9 items-center justify-center rounded-r-lg text-text-muted transition hover:bg-canvas hover:text-text-strong disabled:opacity-40"
            aria-label="Tambah">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v16m8-8H4"/></svg>
    </button>
</div>
