<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    @include('layouts.partials.head')
    <body class="h-full bg-canvas font-sans text-text-strong antialiased">
        @php
            // Identitas yang dipakai sidebar & topbar, dihitung sekali per
            // request supaya kedua partial membaca nilai yang sama dan tidak
            // menjalankan query shift dua kali. `currentFor` adalah definisi
            // tunggal "shift yang sedang berjalan", sama dengan yang dipakai
            // halaman Shift Kasir.
            $currentUser = auth()->user();
            $currentShift = $currentUser ? app(\App\Services\Pos\ShiftService::class)->currentFor($currentUser) : null;
            $currentShiftLabel = $currentShift === null
                ? 'Belum buka shift'
                : ($currentShift->device_id ?? 'Shift #'.$currentShift->id);

            // Identitas toko: `Setting::many` menjamin semua kunci ada, dengan
            // fallback yang sama untuk instalasi yang membiarkan setelan kosong.
            $store = \App\Models\Setting::many([
                'store.name' => '167 Diecast Shop',
                'store.branch' => 'Cassiopeia Plaza',
            ]);

            // Kasus karantina terbuka, dipakai badge di sidebar & topbar.
            // Memakai sumber yang sama dengan dashboard supaya angkanya sinkron.
            $quarantine = app(\App\Services\Report\DashboardService::class)->quarantineSummary();

            // Item pusat notifikasi topbar, sekali per request. Sumbernya live
            // (tanpa tabel sendiri); item kosong berarti badge tidak tampil.
            $notifications = $currentUser === null
                ? ['count' => 0, 'items' => []]
                : app(\App\Services\NotificationCenterService::class)->items($currentUser);
        @endphp
        <div class="min-h-full" x-data="sidebarLayout">
            @include('layouts.sidebar')

            <div class="md:pl-[var(--sidebar-current)]">
                @include('layouts.topbar')

                @include('layouts.partials.overlays')

                <!-- Page Content -->
                <main class="px-4 py-6 sm:px-6 lg:px-8">
                    <div class="mx-auto max-w-7xl">
                        {!! $slot !!}
                    </div>
                </main>

                <footer class="px-4 pb-6 text-label-sm text-text-subtle sm:px-6 lg:px-8">
                    <div class="mx-auto max-w-7xl text-center">
                        Semi-WMS &amp; POS Konsinyasi Hot Wheels · v0.1 (UI Blueprint)
                    </div>
                </footer>
            </div>
        </div>
    </body>
</html>