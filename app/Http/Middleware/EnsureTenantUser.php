<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The portal shows one tenant: the signed-in client's own, or, for the admin, the client they opened from the admin
 * panel ("Open portal").
 */
class EnsureTenantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = $user?->tenant;
        if (! $tenant && $user?->is_admin && $request->session()->has('as_tenant')) {
            $tenant = Tenant::find($request->session()->get('as_tenant'));
        }
        if (! $tenant) {
            return $user?->is_admin ? redirect()->route('admin.dashboard') : abort(403);
        }
        $request->attributes->set('tenant', $tenant);
        view()->share('tenant', $tenant);

        return $next($request);
    }
}
