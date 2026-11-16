<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Tenant;
use Illuminate\Http\Request;

class KeyController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('tenant');
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);

        return view('portal.keys', [
            'secrets' => $tenant->apiKeys()->where('type', 'secret')->whereNull('revoked_at')->latest()->get(),
            'public' => $tenant->apiKeys()->where('type', 'public')->whereNull('revoked_at')->latest()->first(),
        ]);
    }

    public function storeSecret(Request $request)
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:100']]);
        [, $plain] = ApiKey::issueSecret($this->tenant($request), $data['name'] ?? null);

        // Shown once, on the next page only
        return back()->with('new_secret', $plain);
    }

    public function rotatePublic(Request $request)
    {
        $tenant = $this->tenant($request);
        $old = $tenant->apiKeys()->where('type', 'public')->whereNull('revoked_at')->latest()->first();
        $domains = $old?->allowed_domains ?? ($tenant->website ? ApiKey::parseDomains($tenant->website) : []);
        $tenant->apiKeys()->where('type', 'public')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        ApiKey::issuePublic($tenant, $domains);

        return back()->with('status', __('A new widget key was made. Update the script on your website.'));
    }

    public function domains(Request $request)
    {
        $data = $request->validate(['domains' => ['nullable', 'string', 'max:2000']]);
        $tenant = $this->tenant($request);
        $public = $tenant->apiKeys()->where('type', 'public')->whereNull('revoked_at')->latest()->first()
            ?? ApiKey::issuePublic($tenant);
        $public->update(['allowed_domains' => ApiKey::parseDomains($data['domains'] ?? '')]);

        return back()->with('status', __('Saved.'));
    }

    public function revoke(Request $request, ApiKey $key)
    {
        abort_unless($key->tenant_id === $this->tenant($request)->id, 404);
        $key->forceFill(['revoked_at' => now()])->save();

        return back()->with('status', __('The key was revoked.'));
    }
}
