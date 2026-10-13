{{--
    The document head, shared by the admin layout (`layouts.app`) and the POS
    shell (`layouts.pos`). Kept in one partial so both shells load the same
    assets, seed the same table/grid preference, and hand a redirect's toast to
    the client identically -- two heads that drift are two applications.
--}}
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name', 'Semi ERP Hot Wheels') }}</title>

    {{-- Seed the saved table/grid preference before first paint, so a
         returning grid user never watches the table render and get replaced. --}}
    <script>
        try {
            var view = window.localStorage.getItem(@js(\App\Support\DataTable\ViewPreference::storageKey()));

            if (view === 'grid' || view === 'table') {
                document.documentElement.dataset.tableView = view;
            }
        } catch (e) {
            // Blocked storage: the CSS default of Table still applies.
        }
    </script>

    {{-- Whatever a redirect left in the session, handed to the client as data.
         A JSON script tag rather than an inline call: nothing in it is
         executable, and the app reads it before Alpine's first paint so the
         message is part of it. --}}
    @if (session()->has('toast'))
        <script type="application/json" id="flash-toast">@json(session('toast'))</script>
    @endif
    <link rel="icon" type="image/svg" href="/assets/images/167_LOGO.png" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    {{--
        `receipt.css` ikut dimuat di sini, bukan di-push dari halaman yang
        membutuhkannya. `page()` merender view halaman sebelum layout, dan
        stack di-flush begitu render inner selesai -- push dari halaman
        (kasir, misalnya) tidak pernah sampai ke `@stack('head')` ini, dan
        pratinjau struk di dialog kasir tampil sebagai teks tanpa format.
        Semua aturannya ber-namespace `.receipt-*`, jadi aman berdampingan
        dengan app.css di halaman mana pun yang menampilkan struk.
    --}}
    @vite(['resources/css/app.css', 'resources/css/receipt.css', 'resources/js/app.js'])
    @stack('head')
</head>
