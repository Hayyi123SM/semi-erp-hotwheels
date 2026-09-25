@props(['context' => 'Aksi ini memerlukan verifikasi Owner.', 'confirmText' => 'Konfirmasi'])
@php
    $pinId = 'pin-' . \Illuminate\Support\Str::random(6);
@endphp

<div {{ $attributes->merge(['class' => 'card p-6']) }} x-data="{ pin: '', pinError: '' }"
     x-on:pin-confirm.window="if ($event.detail === 'manual') pin = ''">
    <div class="flex items-start gap-4">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-error-bg text-error-text">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
        </span>
        <div class="flex-1">
            <h3 class="text-headline-sm text-text-strong">Verifikasi PIN Owner</h3>
            <p class="mt-1 text-body-sm text-text-muted">{{ $context }}</p>
        </div>
    </div>

    <div class="mt-5">
        <label for="{{ $pinId }}" class="mb-2 block text-label-md text-text-muted">PIN 6 digit</label>
        <div class="flex items-center gap-2">
            <input id="{{ $pinId }}" type="password" inputmode="numeric" maxlength="6" autocomplete="off"
                   x-model="pin" :class="pinError ? 'input-base! border-error-text' : 'input-base'"
                   @input="pinError = ''; $el.value = $el.value.replace(/[^0-9]/g, '')"
                   placeholder="••••••" data-allow-focus>
            <button type="button" class="btn-secondary" @click="pin.length ? ($store.toast.push('PIN diterima · aksi diotorisasi', 'success'), pin='') : pinError = 'PIN wajib diisi'">
                {{ $confirmText }}
            </button>
        </div>
        <p x-show="pinError" x-cloak class="mt-1.5 text-label-sm text-error-text" x-text="pinError"></p>
        <p class="mt-2 text-label-sm text-text-subtle">Owner &amp; Manager dapat memasukkan PIN. Kasir tidak berwenang.</p>
    </div>
</div>