{{--
    The request-agnostic overlays every authenticated page needs: the toast
    container and the unsaved-changes guard. Shared by `layouts.app` and
    `layouts.pos` for the same reason the toast container was always kept in the
    layout and not a page -- the request is identical wherever it comes from,
    and a caller must be able to use one without first checking that its own
    page ships it.
--}}

<!-- Global toasts. Anchored to both edges below sm so a 320px
     viewport cannot push them off screen, and pushed below the
     topbar so the z-index no longer buries the header.
     aria-live because these are the only announcement of a
     change the reader made on another page, and the container
     holds the polite region so a new message reads rather than
     interrupts. -->
<div class="fixed inset-x-4 top-20 z-[60] flex flex-col gap-2 sm:inset-x-auto sm:right-4 sm:top-4 sm:w-80"
     x-data role="status" aria-live="polite" aria-atomic="false">
    <template x-for="t in $store.toast.items" :key="t.id">
        <div x-show="t" x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-x-4"
             x-transition:enter-end="opacity-100 translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             @mouseenter="$store.toast.pause(t.id)"
             @mouseleave="$store.toast.resume(t.id)"
             class="pointer-events-auto flex w-full items-start gap-3 rounded-lg border bg-surface-lowest p-4 shadow-lg sm:w-80"
             :class="{
                'border-success-border bg-success-bg': t.type === 'success',
                'border-error-border bg-error-bg': t.type === 'error',
                'border-warning-border bg-warning-bg': t.type === 'warning',
                'border-info-border bg-info-bg': t.type === 'info',
            }">
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-success-bg text-label-md text-success-text"
                  :class="{
                    'bg-success-bg text-success-text': t.type === 'success',
                    'bg-error-bg text-error-text': t.type === 'error',
                    'bg-warning-bg text-warning-text': t.type === 'warning',
                    'bg-info-bg text-info-text': t.type === 'info',
                  }"
                  x-text="t.icon"></span>
            <p class="min-w-0 flex-1 text-body-sm text-text-strong" x-text="t.message"></p>
            {{-- A message that says itself is the common case, but not
                 the only one: a failure worth reading twice, or one the
                 reader wants to keep while they go and do something
                 about it. The clock is stopped by `dismiss`, so
                 pressing this leaves no timer behind to fire against
                 an id that is already gone. --}}
            <button type="button"
                    class="-mr-1 -mt-1 ml-auto flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-text-subtle transition hover:bg-canvas hover:text-text-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                    @click="$store.toast.dismiss(t.id)"
                    :aria-label="'Tutup notifikasi: ' + t.message">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </template>
</div>

<!-- Unsaved changes. Its own island rather than a wrapper around
     the page, because the links it has to intercept — the sidebar,
     the breadcrumb, the form's own back button — sit outside any
     form, and the listeners belong to the document, not to one.
     The warning it raises is the shared dialog, so there is no
     dialog markup here to fall out of step with the script. -->
<div x-data="unsavedChanges"></div>
