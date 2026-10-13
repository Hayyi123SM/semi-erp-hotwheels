<?php

namespace App\Http\Middleware;

use App\Support\Visibility\MenuVisibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureVisible
{
    public function __construct(private readonly MenuVisibility $visibility) {}

    public function handle(Request $request, Closure $next, ?string $key = null): Response
    {
        $user = $request->user();
        $resolvedKey = $key ?? $request->route()?->getName();

        abort_unless($resolvedKey !== null, 500, 'Route tanpa nama tidak bisa dipetakan ke visibilitas.');

        abort_unless($this->visibility->canSee($user, (string) $resolvedKey), 403, 'Menu ini sedang disembunyikan untuk peran Anda.');

        return $next($request);
    }
}
