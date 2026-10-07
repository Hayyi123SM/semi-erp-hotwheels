<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class OwnerOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        // The check goes through the Gate so middleware, controllers and views
        // all read the one definition of "is this an Owner" from
        // `AppServiceProvider::ownerGate()`, rather than each of them calling
        // `isOwner()` on its own and drifting.
        abort_unless(Gate::forUser($request->user())->allows('owner-only'), 403, 'Hanya Owner yang dapat mengakses fitur ini.');

        return $next($request);
    }
}
