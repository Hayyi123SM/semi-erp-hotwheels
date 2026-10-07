<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a session the moment the account behind it is switched off.
 *
 * Refusing the next login is not the same as refusing the current one. An Owner
 * who deactivates a user is answering for a session that is already open, and
 * that session would otherwise keep working until it happened to expire, which
 * is exactly the length of time a person handed back a laptop still has. So the
 * check runs on every request rather than once at the door.
 *
 * The session is invalidated, not just logged out: `logout()` alone leaves the
 * old session id on the device, and rotating the token on top of it means the
 * previous request is not merely rejected but no longer replayable.
 */
class EnsureAccountIsActive
{
    private const string MESSAGE = 'Akun ini dinonaktifkan. Minta Owner mengaktifkannya kembali di Pengaturan · Pengguna.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->is_active) {
            return $next($request);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Client yang meminta JSON tidak sedang duduk di depan browser, jadi
        // menjawab dengan redirect ke halaman login memberinya 302 dan body HTML
        // yang tidak bisa dibaca. Statusnya mengatakan "kamu tidak masuk", dan
        // itu memang yang terjadi: sesinya benar-benar dibuang.
        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE], 401);
        }

        return redirect()
            ->route('login')
            ->withErrors(['username' => self::MESSAGE]);
    }
}
