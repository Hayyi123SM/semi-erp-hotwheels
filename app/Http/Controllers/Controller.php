<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

abstract class Controller
{
    /**
     * Render a page view wrapped inside the application layout.
     */
    protected function page(string $view, array $data = [], ?string $title = null): View
    {
        return view('layouts.app', [
            'slot' => view($view, $data)->render(),
            'title' => $title,
        ]);
    }
}