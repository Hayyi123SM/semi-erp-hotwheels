<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\OwnerOnly;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Traefik di depan Dokploy mengakhiri TLS di proxy, jadi request yang
        // sampai ke container selalu terlihat `http`. Tanpa ini Laravel salah
        // membangun URL (APP_URL https) dan cookie secure dianggap tidak aman.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'owner' => OwnerOnly::class,
        ]);

        // Appending rather than aliasing: an account can be switched off while
        // its holder is already signed in, and an alias would leave that session
        // running. Appending puts the check after the session is started, which
        // is when there is an account to look at.
        $middleware->web(append: [
            EnsureAccountIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
