{{--
    Sticky bottom settlement bar for mobile/tablet (< lg).
    Displays compact summary + primary pay button. Can be expanded to show full details.
--}}
<div class="sticky bottom-0 z-30 block lg:hidden border-t border-border-subtle bg-surface-lowest/98 backdrop-blur"
     x-data="{ openPayment: false }">
    <div class="px-3 py-2 sm:px-4">
        <div x-show="!openPayment" x-transition.opacity>
            <button type="button"
                    class="flex w-full items-center justify-between gap-2 rounded-lg bg-primary px-3 py-2 text-on-primary shadow-sm touch-manipulation"
                    @click="openPayment = true">
                <div class="flex flex-col items-start text-left">
                    <span class="text-label-sm opacity-90" x-text="'Total (' + cartCount + ' item)'"></span>
                    <span class="text-body-md font-semibold tabular-nums" x-text="format.rupiah(subtotal)"></span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="text-body-md font-semibold">Bayar</span>
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
                </div>
            </button>
        </div>

        <div x-show="openPayment" x-cloak x-transition>
            <div class="mb-2 flex items-center justify-between">
                <p class="text-body-sm font-semibold text-text-strong">Rincian Pembayaran</p>
                <button type="button" class="flex h-8 items-center gap-1 rounded-md px-2 text-label-md text-text-muted hover:bg-canvas touch-manipulation" @click="openPayment = false">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    Tutup
                </button>
            </div>

            <div class="space-y-1.5 rounded-lg border border-border-subtle bg-canvas p-2 text-label-md">
                <div class="flex items-center justify-between">
                    <span class="text-text-muted">Subtotal (<span x-text="cartCount"></span> item)</span>
                    <span class="tabular-nums" x-text="format.rupiah(subtotal)"></span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-text-muted">Diskon</span>
                    <span class="tabular-nums" x-text="format.rupiah(discountTotal)"></span>
                </div>
                <div class="flex items-center justify-between border-t border-border-subtle pt-1.5">
                    <span class="text-body-sm font-semibold text-text-strong">Total</span>
                    <span class="text-body-sm font-semibold tabular-nums" x-text="format.rupiah(total)"></span>
                </div>
            </div>

            <div class="mt-2 space-y-1.5">
                <div class="flex flex-wrap items-center gap-1.5">
                    <template x-for="amt in [50000,100000,200000,300000,500000]" :key="'qc-'+amt">
                        <button type="button" class="flex h-8 items-center rounded-md border border-border-subtle bg-surface-lowest px-2 text-label-sm hover:bg-canvas touch-manipulation" @click="quickTender(amt)" x-text="format.rupiah(amt)"></button>
                    </template>
                </div>

                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-2 flex items-center text-label-sm text-text-subtle">Rp</span>
                    <input type="text"
                           inputmode="numeric"
                           class="h-9 w-full rounded-md border border-border-subtle bg-surface-lowest pl-7 pr-2 text-body-sm tabular-nums text-text-strong outline-none focus:border-primary focus:ring-[3px] focus:ring-primary/15"
                           placeholder="Uang diterima"
                           x-model="tender"
                           @keyup.enter="pay()">
                </div>

                <div class="flex items-center justify-between text-label-md">
                    <span class="text-text-muted">Kembalian</span>
                    <span class="font-semibold tabular-nums" x-text="format.rupiah(change)"></span>
                </div>

                <button type="button"
                        class="flex h-10 w-full items-center justify-center gap-1.5 rounded-lg bg-primary text-body-sm font-semibold text-on-primary shadow-sm disabled:cursor-not-allowed disabled:opacity-50 touch-manipulation"
                        @click="pay()"
                        :disabled="total <= 0 || tenderAmount < total">
                    Bayar
                </button>
            </div>
        </div>
    </div>
</div>
