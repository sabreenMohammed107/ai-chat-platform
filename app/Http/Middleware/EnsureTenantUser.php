<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The portal shows one tenant: the signed-in client's own, or the client the admin opened from the admin panel
 * ("Open portal": the portal session then holds admin_view, with no signed-in user).
 */
class EnsureTenantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->user()?->tenant;
        $adminView = false;

        if (! $tenant && ($view = $request->session()->get('admin_view'))) {
            $admin = User::find($view['admin_id'] ?? null);
            $tenant = $admin?->is_admin ? Tenant::find($view['tenant_id'] ?? null) : null;
            $adminView = (bool) $tenant;
            if (! $tenant) {
                $request->session()->forget('admin_view');
            }
        }

        if (! $tenant) {
            return redirect()->guest(route('portal.login'));
        }

        $request->attributes->set('tenant', $tenant);
        view()->share(['tenant' => $tenant, 'adminView' => $adminView]);

        return $next($request);
    }
}
