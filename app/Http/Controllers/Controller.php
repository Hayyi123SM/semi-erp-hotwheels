<?php

namespace App\Http\Controllers;

use App\Support\DataTable\Fragment;
use Illuminate\View\View;

abstract class Controller
{
    /**
     * Render a page view wrapped inside the application layout.
     */
    protected function page(string $view, array $data = [], ?string $title = null): View
    {
        // A table refreshing itself already has the shell on screen. Wrapping
        // the fragment in the layout would make the layout, the sidebar and the
        // topbar the bulk of a response whose reader is only waiting for rows,
        // and would hand the page a full re-render of itself for the privilege.
        if (Fragment::requested(request())) {
            return view($view, $data);
        }

        return view('layouts.app', [
            'slot' => view($view, $data)->render(),
            'title' => $title,
        ]);
    }
}
