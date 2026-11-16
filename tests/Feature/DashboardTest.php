<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Conversation;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@platform.test', 'password' => 'secret123']);
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    private function client(string $name = 'Shop'): User
    {
        $tenant = Tenant::create(['name' => $name, 'status' => 'active']);

        return User::create(['name' => $name.' owner', 'email' => strtolower($name).'@client.test', 'password' => 'secret123', 'tenant_id' => $tenant->id]);
    }

    public function test_guests_are_sent_to_the_login_of_their_area(): void
    {
        $this->get('/portal')->assertRedirect('/portal/login');
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/')->assertRedirect('/portal');
    }

    public function test_the_admin_panel_and_the_portal_use_different_session_cookies(): void
    {
        $admin = $this->get('/admin/login')->assertOk()->assertSee('Admin panel');
        $portal = $this->get('/portal/login')->assertOk()->assertSee('Client portal');

        $this->assertSame(['ai_chat_platform_admin_session'], array_values(array_filter(array_map(fn ($c) => $c->getName(), $admin->headers->getCookies()), fn ($n) => str_ends_with($n, '_session'))));
        $this->assertSame(['ai_chat_platform_portal_session'], array_values(array_filter(array_map(fn ($c) => $c->getName(), $portal->headers->getCookies()), fn ($n) => str_ends_with($n, '_session'))));
    }

    public function test_each_login_accepts_only_its_own_kind_of_account(): void
    {
        $this->admin();
        $this->client('Shop');

        $this->post('/admin/login', ['email' => 'shop@client.test', 'password' => 'secret123'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/portal/login', ['email' => 'admin@platform.test', 'password' => 'secret123'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/portal/login', ['email' => 'shop@client.test', 'password' => 'secret123'])->assertRedirect('/portal');
        $this->assertAuthenticated();
    }

    public function test_a_client_sees_only_their_own_conversations(): void
    {
        $mine = $this->client('Shop');
        $theirs = $this->client('Other');
        $own = Conversation::create(['tenant_id' => $mine->tenant_id]);
        $other = Conversation::create(['tenant_id' => $theirs->tenant_id]);

        $this->actingAs($mine)->get('/portal')->assertOk()->assertSee('Shop');
        $this->actingAs($mine)->get("/portal/conversations/{$own->uuid}")->assertOk();
        $this->actingAs($mine)->get("/portal/conversations/{$other->uuid}")->assertNotFound();
    }

    public function test_a_client_cannot_touch_another_clients_keys_or_sources(): void
    {
        $mine = $this->client('Shop');
        $theirs = $this->client('Other');
        [$key] = ApiKey::issueSecret($theirs->tenant);
        $source = $theirs->tenant->sources()->create(['type' => 'faq', 'title' => 'Q', 'content' => 'A']);

        $this->actingAs($mine)->delete("/portal/keys/{$key->id}")->assertNotFound();
        $this->actingAs($mine)->delete("/portal/knowledge/{$source->id}")->assertNotFound();
        $this->assertNull($key->fresh()->revoked_at);
        $this->assertNotNull($source->fresh());
    }

    public function test_the_knowledge_page_lists_the_pages_and_files_a_website_sync_read(): void
    {
        $user = $this->client();
        $source = $user->tenant->sources()->create(['type' => 'website', 'title' => 'shop.test (sitemap)', 'url' => 'https://shop.test/sitemap.xml', 'status' => 'ready']);
        foreach ([['https://shop.test/courses', 'Courses'], ['https://shop.test/courses', 'Courses'], ['https://shop.test/files/laravel-syllabus.pdf', 'laravel syllabus']] as [$url, $title]) {
            $source->chunks()->create(['tenant_id' => $user->tenant_id, 'url' => $url, 'title' => $title, 'content' => 'text']);
        }

        $this->actingAs($user)->get('/portal/knowledge')
            ->assertOk()
            ->assertSee('Pages and files read (2)')
            ->assertSee('https://shop.test/files/laravel-syllabus.pdf')
            ->assertSee('2 passages');
    }

    public function test_a_client_cannot_open_the_admin_panel(): void
    {
        $this->actingAs($this->client())->get('/admin')->assertForbidden();
    }

    public function test_every_portal_page_renders(): void
    {
        $user = $this->client();
        foreach (['/portal', '/portal/conversations', '/portal/knowledge', '/portal/keys', '/portal/integration', '/portal/settings'] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
            $this->actingAs($user)->withSession(['locale' => 'ar'])->get($url)->assertOk();
        }
    }

    public function test_the_secret_key_is_shown_once(): void
    {
        $user = $this->client();

        $this->actingAs($user)->post('/portal/keys/secret', ['name' => 'Server'])->assertSessionHas('new_secret');
        $this->actingAs($user)->get('/portal/keys')->assertDontSee('sk_live_'.str_repeat('x', 3));
        $key = $user->tenant->apiKeys()->where('type', 'secret')->firstOrFail();

        $this->actingAs($user)->delete("/portal/keys/{$key->id}")->assertRedirect();
        $this->assertNotNull($key->fresh()->revoked_at);
    }

    public function test_the_admin_creates_a_client_with_plan_login_and_widget_key(): void
    {
        Queue::fake();
        $plan = Plan::create(['name' => 'Starter', 'monthly_messages' => 500, 'price' => 15, 'max_pages' => 50]);
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/tenants', [
            'name' => 'Gift Shop',
            'website' => 'https://www.gifts.example',
            'plan_id' => $plan->id,
            'months' => 3,
            'user_name' => 'Mona',
            'user_email' => 'mona@gifts.example',
            'read_website' => 0,
        ])->assertRedirect()->assertSessionHas('credentials');

        $tenant = Tenant::where('name', 'Gift Shop')->firstOrFail();
        $this->assertSame(500, $tenant->messagesAllowed());
        $this->assertSame(today()->addMonthsNoOverflow(3)->subDay()->toDateString(), $tenant->subscription->ends_at->toDateString());
        $this->assertSame(['gifts.example'], $tenant->apiKeys()->where('type', 'public')->first()->allowed_domains);
        $this->assertNotNull(User::where('email', 'mona@gifts.example')->where('tenant_id', $tenant->id)->first());

        foreach (['/admin', '/admin/plans', '/admin/tenants/create', "/admin/tenants/{$tenant->id}"] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        // The admin opens the client's portal through a one-time link
        $location = $this->actingAs($admin)->post("/admin/tenants/{$tenant->id}/open")->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('/portal/enter/', $location);
        $this->get($location)->assertRedirect('/portal');
        $this->get('/portal')->assertOk()->assertSee('Gift Shop')->assertSee('as the admin');
        // The link works once
        $this->app['session.store']->flush();
        $this->get($location)->assertRedirect('/portal/login');
    }

    public function test_reset_knowledge_needs_reset_typed_and_deletes_only_this_clients_knowledge(): void
    {
        $user = $this->client('Shop');
        $other = $this->client('Other');
        foreach ([$user, $other] as $u) {
            $source = $u->tenant->sources()->create(['type' => 'website', 'title' => 'site', 'url' => 'https://x.test', 'status' => 'ready']);
            $source->chunks()->create(['tenant_id' => $u->tenant_id, 'content' => 'text']);
            $u->tenant->sources()->create(['type' => 'faq', 'title' => 'Q', 'content' => 'A', 'status' => 'ready']);
        }
        Conversation::create(['tenant_id' => $user->tenant_id]);

        $this->actingAs($user)->get('/portal/knowledge')->assertSee('Danger zone')->assertSee('2 sources and 1 passages will be deleted.');

        $this->actingAs($user)->delete('/portal/knowledge/reset', ['confirm' => 'yes'])->assertSessionHasErrors('confirm');
        $this->assertSame(2, $user->tenant->sources()->count());

        $this->actingAs($user)->delete('/portal/knowledge/reset', ['confirm' => 'reset'])->assertRedirect('/portal/knowledge');
        $this->assertSame(0, $user->tenant->sources()->count());
        $this->assertSame(0, $user->tenant->chunks()->count());
        $this->assertSame(1, $user->tenant->conversations()->count());
        $this->assertSame(2, $other->tenant->sources()->count());
        $this->assertSame(1, $other->tenant->chunks()->count());
    }

    public function test_editing_a_client_saves_a_website_typed_without_https_and_allows_it_in_the_widget(): void
    {
        $tenant = Tenant::create(['name' => 'Shop', 'status' => 'active', 'website' => 'https://demo-shop.example']);
        $tenant->allowWebsiteInWidget();

        $this->actingAs($this->admin())->from("/admin/tenants/{$tenant->id}")->put("/admin/tenants/{$tenant->id}", [
            'name' => 'Shop',
            'status' => 'active',
            'website' => 'www.safer.travel/',
            'contact_info' => 'WhatsApp: +20 111 222 3333',
        ])->assertSessionHasNoErrors();

        $tenant->refresh();
        $this->assertSame('https://www.safer.travel', $tenant->website);
        $this->assertSame('WhatsApp: +20 111 222 3333', $tenant->contact_info);
        $this->assertSame(['demo-shop.example', 'safer.travel'], $tenant->apiKeys()->where('type', 'public')->first()->allowed_domains);
    }

    public function test_a_renewal_starts_after_the_current_subscription(): void
    {
        $plan = Plan::create(['name' => 'Starter', 'monthly_messages' => 500, 'price' => 15, 'max_pages' => 50]);
        $tenant = Tenant::create(['name' => 'Shop', 'status' => 'active']);
        $tenant->subscriptions()->create(['plan_id' => $plan->id, 'starts_at' => today()->subDays(10), 'ends_at' => today()->addDays(5)]);

        $this->actingAs($this->admin())->post("/admin/tenants/{$tenant->id}/subscriptions", ['plan_id' => $plan->id, 'months' => 1])->assertRedirect();

        $latest = $tenant->fresh()->subscription;
        $this->assertSame(today()->addDays(6)->toDateString(), $latest->starts_at->toDateString());
    }
}
