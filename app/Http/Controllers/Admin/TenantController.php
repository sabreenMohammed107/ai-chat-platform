<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncKnowledgeSource;
use App\Models\ApiKey;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Knowledge\SafeUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    public function index()
    {
        $since = today()->subDays(29)->toDateString();
        $tenants = Tenant::with('subscription.plan')
            ->withSum(['usage as cost_30' => fn ($q) => $q->where('date', '>=', $since)], 'cost')
            ->withSum(['usage as messages_30' => fn ($q) => $q->where('date', '>=', $since)], 'messages')
            ->orderBy('name')
            ->get();

        $active = $tenants->filter(fn (Tenant $t) => $t->activeSubscription());

        return view('admin.dashboard', [
            'tenants' => $tenants,
            'totals' => [
                'clients' => $tenants->count(),
                'active' => $active->count(),
                'revenue' => $active->sum(fn (Tenant $t) => (float) $t->subscription->plan->price),
                'cost' => (float) $tenants->sum('cost_30'),
                'messages' => (int) $tenants->sum('messages_30'),
            ],
        ]);
    }

    public function create()
    {
        return view('admin.tenant-create', ['plans' => Plan::orderBy('price')->get()]);
    }

    public function store(Request $request)
    {
        $request->merge(['website' => Tenant::normalizeWebsite($request->input('website'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'plan_id' => ['required', 'exists:plans,id'],
            'months' => ['required', 'integer', 'min:1', 'max:36'],
            'user_name' => ['required', 'string', 'max:100'],
            'user_email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'read_website' => ['nullable', 'boolean'],
        ]);
        $password = Str::password(14, symbols: false);

        $tenant = DB::transaction(function () use ($data, $password) {
            $tenant = Tenant::create(['name' => $data['name'], 'website' => $data['website'] ?? null, 'status' => 'active']);
            $this->addSubscription($tenant, (int) $data['plan_id'], today()->toImmutable(), (int) $data['months']);
            User::create(['name' => $data['user_name'], 'email' => $data['user_email'], 'password' => $password, 'tenant_id' => $tenant->id]);
            $tenant->allowWebsiteInWidget();

            return $tenant;
        });

        if (($data['read_website'] ?? false) && $tenant->website && SafeUrl::isAllowed($tenant->website)) {
            $source = $tenant->sources()->create(['type' => 'website', 'title' => parse_url($tenant->website, PHP_URL_HOST), 'url' => $tenant->website]);
            SyncKnowledgeSource::dispatch($source);
        }

        return redirect()->route('admin.tenants.edit', $tenant)
            ->with('credentials', ['email' => $data['user_email'], 'password' => $password]);
    }

    public function edit(Tenant $tenant)
    {
        return view('admin.tenant-edit', [
            'client' => $tenant->load(['subscriptions' => fn ($q) => $q->with('plan')->latest('ends_at'), 'users']),
            'plans' => Plan::orderBy('price')->get(),
            'used' => $tenant->messagesUsed(),
            'allowed' => $tenant->messagesAllowed(),
            'cost30' => (float) $tenant->usage()->where('date', '>=', today()->subDays(29)->toDateString())->sum('cost'),
        ]);
    }

    public function update(Request $request, Tenant $tenant)
    {
        $request->merge(['website' => Tenant::normalizeWebsite($request->input('website'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'status' => ['required', 'in:active,suspended'],
            'assistant_name' => ['nullable', 'string', 'max:100'],
            'contact_info' => ['nullable', 'string', 'max:1000'],
            'instructions' => ['nullable', 'string', 'max:4000'],
        ]);
        $tenant->update($data);
        if ($tenant->wasChanged('website')) {
            $tenant->allowWebsiteInWidget();
        }

        return back()->with('status', __('Saved.'));
    }

    /** Renew or change the plan: starts the day after the current subscription ends (or today), for N months. */
    public function subscribe(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'plan_id' => ['required', 'exists:plans,id'],
            'months' => ['required', 'integer', 'min:1', 'max:36'],
            'starts_at' => ['nullable', 'date'],
        ]);
        $current = $tenant->subscription;
        $start = isset($data['starts_at'])
            ? CarbonImmutable::parse($data['starts_at'])
            : ($current && $current->ends_at->gte(today()) ? CarbonImmutable::parse($current->ends_at)->addDay() : today()->toImmutable());
        $this->addSubscription($tenant, (int) $data['plan_id'], $start, (int) $data['months']);

        return back()->with('status', __('Subscription added.'));
    }

    public function addUser(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
        ]);
        $password = Str::password(14, symbols: false);
        User::create($data + ['password' => $password, 'tenant_id' => $tenant->id]);

        return back()->with('credentials', ['email' => $data['email'], 'password' => $password]);
    }

    /**
     * Opens the client's portal (in its own session, usually a new tab) to manage their knowledge, keys and settings:
     * a one-time link, valid for two minutes, that the portal turns into an admin view of this client.
     */
    public function open(Request $request, Tenant $tenant)
    {
        $token = Str::random(48);
        Cache::put('portal-enter:'.$token, ['admin_id' => $request->user()->id, 'tenant_id' => $tenant->id], now()->addMinutes(2));

        return redirect()->route('portal.enter', $token);
    }

    private function addSubscription(Tenant $tenant, int $planId, CarbonImmutable $start, int $months): Subscription
    {
        return $tenant->subscriptions()->create([
            'plan_id' => $planId,
            'starts_at' => $start->toDateString(),
            'ends_at' => $start->addMonthsNoOverflow($months)->subDay()->toDateString(),
        ]);
    }
}
