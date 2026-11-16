<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\Chat\Agent;
use App\Services\Chat\Usage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class DashboardController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('tenant');
    }

    /**
     * The admin's one-time link from "Open portal": this portal session becomes an admin view of that client. A client
     * signed in to the portal in this browser is signed out of it first.
     */
    public function enter(Request $request, string $token)
    {
        $view = Cache::pull('portal-enter:'.$token);
        if (! $view) {
            return redirect()->route('portal.login')->withErrors(['email' => __('This link has expired. Open the portal again from the admin panel.')]);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('admin_view', $view);

        return redirect()->route('portal.dashboard');
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $subscription = $tenant->subscription()->with('plan')->first();

        // Last 30 days, a bar per day
        $from = today()->subDays(29);
        $rows = $tenant->usage()->where('date', '>=', $from->toDateString())->get()->keyBy(fn ($r) => $r->date->toDateString());
        $days = [];
        for ($d = $from->copy(); $d->lte(today()); $d->addDay()) {
            $days[] = ['date' => $d->toDateString(), 'messages' => (int) ($rows[$d->toDateString()]->messages ?? 0)];
        }

        return view('portal.dashboard', [
            'subscription' => $subscription,
            'active' => $tenant->activeSubscription(),
            'used' => $tenant->messagesUsed(),
            'allowed' => $tenant->messagesAllowed(),
            'periodEnd' => $tenant->periodEnd(),
            'days' => $days,
            'conversations' => $tenant->conversations()->count(),
            'sources' => $tenant->sources()->count(),
            'passages' => $tenant->chunks()->count(),
            'recent' => $tenant->conversations()->latest('updated_at')->limit(5)->get(),
        ]);
    }

    public function integration(Request $request)
    {
        $tenant = $this->tenant($request);
        $public = $tenant->apiKeys()->where('type', 'public')->whereNull('revoked_at')->latest()->first()
            ?? ApiKey::issuePublic($tenant, $tenant->website ? ApiKey::parseDomains($tenant->website) : []);

        return view('portal.integration', ['public' => $public]);
    }

    public function settings(Request $request)
    {
        return view('portal.settings');
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'assistant_name' => ['nullable', 'string', 'max:100'],
            'contact_info' => ['nullable', 'string', 'max:1000'],
            'instructions' => ['nullable', 'string', 'max:4000'],
        ]);
        $this->tenant($request)->update($data);

        return back()->with('status', __('Saved.'));
    }

    /** The "Try it" box: a real conversation with the agent, counted like any other. */
    public function try(Request $request, Agent $agent, Usage $usage)
    {
        $tenant = $this->tenant($request);
        $data = $request->validate([
            'message' => ['required', 'string', 'max:'.(int) config('chat.max_message_length')],
            'reset' => ['nullable', 'boolean'],
        ]);
        if ($blocked = $usage->blocked($tenant)) {
            return response()->json(['error' => $blocked['message']], $blocked['status']);
        }

        $key = 'try_conversation_'.$tenant->id;
        $conversation = $request->boolean('reset') ? null
            : Conversation::where('tenant_id', $tenant->id)->where('uuid', (string) $request->session()->get($key))->first();
        $conversation ??= Conversation::create(['tenant_id' => $tenant->id, 'channel' => 'test', 'user_ref' => $request->user()?->email ?? 'admin']);
        $request->session()->put($key, $conversation->uuid);

        try {
            return response()->json(['reply' => $agent->reply($tenant, $conversation, trim($data['message']))]);
        } catch (Throwable $e) {
            Log::error('Try chat failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => __('The assistant is not available right now.')], 503);
        }
    }
}
