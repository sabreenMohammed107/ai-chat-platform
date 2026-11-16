<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client's server: "Authorization: Bearer sk_live_...". Puts the key and its tenant on the request.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->bearerToken();
        $key = str_starts_with($plain, 'sk_live_') ? ApiKey::findSecret($plain) : null;
        if (! $key) {
            return response()->json(['error' => ['code' => 'invalid_api_key', 'message' => 'Invalid or missing API key.']], 401);
        }

        $key->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('api_key', $key);
        $request->attributes->set('tenant', $key->tenant);

        return $next($request);
    }
}
