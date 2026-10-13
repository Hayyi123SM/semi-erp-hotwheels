<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class DevOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Gate::forUser($request->user())->allows('dev-only'), 403, 'Hanya Developer yang dapat mengakses fitur ini.');

        return $next($request);
    }
}
