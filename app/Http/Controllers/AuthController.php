<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Two separate sign-ins: the admin panel (/admin/login, the platform owner only) and the client portal
 * (/portal/login, clients' team members only). Each lives in its own session (SeparateAreaSessions).
 */
class AuthController extends Controller
{
    public function showAdmin()
    {
        return view('auth.login', ['area' => 'admin']);
    }

    public function showPortal()
    {
        return view('auth.login', ['area' => 'portal']);
    }

    public function loginAdmin(Request $request)
    {
        return $this->login($request, 'admin');
    }

    public function loginPortal(Request $request)
    {
        return $this->login($request, 'portal');
    }

    public function logoutAdmin(Request $request)
    {
        return $this->logout($request, 'admin.login');
    }

    public function logoutPortal(Request $request)
    {
        return $this->logout($request, 'portal.login');
    }

    private function login(Request $request, string $area)
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => __('Wrong email or password.')])->onlyInput('email');
        }

        // An admin account signs in to the admin panel only, a client account to the portal only
        $user = Auth::user();
        $allowed = $area === 'admin' ? $user->is_admin : (bool) $user->tenant_id;
        if (! $allowed) {
            Auth::logout();

            return back()->withErrors(['email' => $area === 'admin'
                ? __('This account is not an admin account. Clients sign in at :url', ['url' => route('portal.login')])
                : __('This is the admin account. Sign in at :url', ['url' => route('admin.login')])])->onlyInput('email');
        }

        $request->session()->regenerate();
        $request->session()->forget('admin_view');

        return redirect()->intended($area === 'admin' ? route('admin.dashboard') : route('portal.dashboard'));
    }

    private function logout(Request $request, string $loginRoute)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route($loginRoute);
    }
}
