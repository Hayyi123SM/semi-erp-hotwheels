@props([
    'action' => 'global',
    'context' => 'Aksi ini memerlukan verifikasi Owner.',
    'confirmText' => 'Konfirmasi',
    'name' => 'pin_token',
])

{{-- A field to authorize, not a dialog to type into.

     The PIN used to be typed into a card right here, and a click on the button
     pushed a toast saying the PIN had been accepted. Nothing was checked, and
     the caller was left holding an empty input, so a page could look authorized
     while having authorized nothing.

     Now the PIN goes to the server through the shared dialog and comes back as
     a token in the hidden field, which is what the form actually submits. A
     cancelled dialog leaves the field empty, so the form is refused rather than
     quietly going through without authorization.

     Two separate props, because the sentence a person reads and the scope the
     token is checked against are not the same thing. Binding the token to the
     sentence would mean the scope changes whenever the wording does, and a token
     issued against the old wording would stop working on an unrelated change --
     or, worse, two different actions whose sentences happen to match would share
     a scope. `action` is the stable name the request rule checks; `context` is
     only ever shown. --}}
<div {{ $attributes->merge(['class' => 'card p-6']) }}
     x-data="{
         token: '',
         error: '',
         asking: false,

         async ask() {
             if (this.asking) {
                 return;
             }

             this.asking = true;
             this.error = '';

             try {
                 const grant = await window.pin.request({
                      context: @js($action),
                     title: 'Verifikasi PIN Owner',
                     description: @js($context),
                     confirmText: @js($confirmText),
                 });

                 this.token = grant?.token ?? '';
             } catch (e) {
                 this.token = '';
                 this.error = 'Gagal membuka verifikasi PIN. Muat ulang halaman lalu ulangi.';
             } finally {
                 this.asking = false;
             }
         },
     }">
    <input type="hidden" name="{{ $name }}" :value="token">

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
        <button type="button" class="btn-secondary" :disabled="asking" @click="ask()">
            <span x-show="!asking">{{ $confirmText }}</span>
            <span x-show="asking" x-cloak>Meminta PIN…</span>
        </button>

        <p x-show="token !== ''" x-cloak class="mt-2 text-label-sm text-success-text">
            Terotorisasi. Token berlaku 5 menit.
        </p>

        <p x-show="error !== ''" x-cloak x-text="error" class="mt-2 text-label-sm text-error-text" role="alert"></p>
    </div>
</div>
