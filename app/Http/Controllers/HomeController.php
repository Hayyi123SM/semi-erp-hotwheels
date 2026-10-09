<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Halaman muka `/`.
 *
 * Tujuannya bergantung peran: Owner ke dashboard, Staff ke kasir. Disimpan
 * sebagai controller, bukan closure, supaya `route:cache` tetap bisa
 * menyerialkan daftar route.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return redirect()->route($request->user()->homeRoute());
    }
}
