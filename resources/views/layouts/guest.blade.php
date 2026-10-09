<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen grid grid-cols-1 lg:grid-cols-2">
            <!-- Left Panel (Branding) -->
            <div class="relative hidden lg:flex flex-col justify-center items-center bg-gradient-to-br from-slate-900 via-blue-900 to-indigo-900 text-white p-8">
                <div class="absolute inset-0 opacity-10 bg-[url('data:image/svg+xml,%3Csvg width=%2260%22 height=%2260%22 viewBox=%220 0 60 60%22 xmlns=%22http://www.w3.org/2000/svg%22%3E%3Cg fill=%22none%22 fill-rule=%22evenodd%22%3E%3Cg fill=%22%23ffffff%22 fill-opacity=%220.1%22%3E%3Ccircle cx=%2230%22 cy=%2230%22 r=%222%22/%3E%3C/g%3E%3C/g%3E%3C/svg%3E')]"></div>
                <div class="relative z-10 text-center space-y-6">
                    <x-application-logo class="h-24 w-24 mx-auto object-contain" />
                    <div class="space-y-2">
                        <h1 class="text-3xl font-bold tracking-tight">167 DIECAST SHOP</h1>
                        <p class="text-blue-200/80 text-lg">Aplikasi ERP Diecast</p>
                    </div>
                    <p class="text-sm text-blue-200/60 max-w-md">Sistem manajemen inventory, penjualan, dan operasional untuk mendukung bisnis diecast Anda</p>
                </div>
            </div>

            <!-- Right Panel (Form) -->
            <div class="flex flex-col justify-center items-center p-6 sm:p-8 bg-gray-50 lg:bg-white">
                <div class="w-full max-w-md space-y-6">
                    <!-- Mobile Branding -->
                    <div class="lg:hidden text-center space-y-4">
                        <x-application-logo class="h-24 w-24 mx-auto object-contain" />
                        <div class="space-y-1">
                            <h2 class="text-xl font-semibold text-gray-900">167 DIECAST SHOP</h2>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow-sm border border-gray-200 lg:border-0 lg:shadow-none p-6 lg:p-0">
                        <div class="mb-6">
                            <h2 class="text-2xl font-semibold text-gray-900">{{ __('Masuk') }}</h2>
                            <p class="mt-2 text-sm text-gray-600">{{ __('Masuk untuk melanjutkan ke sistem') }}</p>
                        </div>

                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>
