<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Akun Developer
    |--------------------------------------------------------------------------
    |
    | Developer adalah peran teknis yang tidak dibuat lewat UI oleh Owner.
    | Akun pertama lahir dari `DevSeeder` bila `DEV_PASSWORD` diisi di
    | environment; tanpa password, seeder tidak melakukan apa pun sehingga
    | tidak ada kredensial bawaan yang bocor ke repositori.
    */
    'username' => env('DEV_USERNAME', 'dev'),
    'name' => env('DEV_NAME', 'Developer'),
    'email' => env('DEV_EMAIL'),
    'password' => env('DEV_PASSWORD'),
];
