<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ManageUsers
{
    public function handle(Request $request, Closure $next): Response
    {
        // The check goes through the Gate so middleware, controllers and views
        // all read the one definition of "may this person manage accounts" from
        // `AppServiceProvider::ownerGate()`. Owner and Developer both pass;
        // Staff does not.
        abort_unless(Gate::forUser($request->user())->allows('manage-users'), 403, 'Hanya Owner atau Developer yang dapat mengelola pengguna.');

        return $next($request);
    }
}
