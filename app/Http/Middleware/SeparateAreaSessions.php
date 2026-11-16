<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel (/admin) and the client portal (everything else) keep separate sessions, under separate cookies:
 * signing in to one never signs in to, or out of, the other, so both can be open in the same browser.
 * Runs before the session starts.
 */
class SeparateAreaSessions
{
    public function handle(Request $request, Closure $next): Response
    {
        $area = self::isAdmin($request) ? 'admin' : 'portal';
        $cookie = Str::slug((string) config('app.name'), '_').'_'.$area.'_session';
        config(['session.cookie' => $cookie]);
        // The session store may already exist (long-running workers, tests): rename it too
        app('session')->driver()->setName($cookie);

        return $next($request);
    }

    public static function isAdmin(Request $request): bool
    {
        return $request->is('admin', 'admin/*');
    }
}
