<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Bukti Terima Titipan' }}</title>

    {{-- Hanya stylesheet struk. Halaman ini sengaja tidak memuat app.css:
         yang dicetak hanya struknya, dan seluruh layout aplikasi tidak boleh
         ikut terbawa ke kertas. --}}
    @vite(['resources/css/receipt.css'])

    {{--
        Ukuran kertas untuk dialog print.

        Tanpa ini, browser memakai ukuran yang terakhir dipilih pengguna
        (biasanya A4), dan struk 58 mm tetap tercetak di tengah kertas A4:
        pemotong thermal memotong seluruh A4 itu jadi satu gulungan.

        `margin: 0` wajib. Margin bawaan browser menggeser isi ke dalam, dan
        pemotong memotong tepat di tepi.

        Panjang `auto` untuk kertas thermal, bukan angka tetap, karena printer
        struk menarik gulungan sejauh isinya lalu memotong di ujungnya.
    --}}
    @isset($pageSize)
        <style>
            @page {
                size: {{ $pageSize }};
                margin: 0;
            }
        </style>
    @endisset
</head>
<body class="receipt-body">
    {{ $slot }}
</body>
</html>
