<?php

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | Most templating systems load templates from disk. Here you may specify
    | an array of paths that should be checked for your views. Of course
    | the usual Laravel view path has already been registered for you.
    |
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    // `realpath()` sengaja tidak dipakai: saat volume storage masih kosong di
    // deploy pertama, direktori ini bisa belum ada dan realpath() mengembalikan
    // false — akibatnya `php artisan view:cache` gagal dengan "View path not found".
    | Kontainer (entrypoint) memastikan direktori ini dibuat sebelum view:cache.
    |
    */

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        storage_path('framework/views')
    ),

];
