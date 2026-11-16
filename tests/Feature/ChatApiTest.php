<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Conversation;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\UsageDaily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['chat.api_key' => 'test-key', 'chat.crawl.block_private' => false]);
    }

    private function tenant(array $attrs = [], int $messages = 100, bool $active = true): Tenant
    {
        $plan = Plan::create(['name' => 'Basic', 'monthly_messages' => $messages, 'price' => 20, 'model' => 'claude-haiku-5-5', 'max_pages' => 10]);
        $tenant = Tenant::create($attrs + ['name' => 'Shop', 'status' => 'active', 'website' => 'https://shop.test']);
        $tenant->subscriptions()->create([
            'plan_id' => $plan->id,
            'starts_at' => $active ? today()->subDays(3) : today()->subMonths(2),
            'ends_at' => $active ? today()->addMonth() : today()->subMonth(),
        ]);

        return $tenant;
    }

    private function knowledge(Tenant $tenant, string $text, string $title = 'Page'): void
    {
        $source = KnowledgeSource::create(['tenant_id' => $tenant->id, 'type' => 'faq', 'title' => $title, 'status' => 'ready']);
        KnowledgeChunk::create(['tenant_id' => $tenant->id, 'knowledge_source_id' => $source->id, 'title' => $title, 'content' => $text, 'url' => 'https://shop.test/x']);
    }

    private function claudeAnswers(string $text): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 100],
        ])]);
    }

    public function test_a_wrong_key_is_refused(): void
    {
        $this->postJson('/api/v1/chat', ['message' => 'hi'], ['Authorization' => 'Bearer sk_live_nope'])
            ->assertStatus(401)->assertJsonPath('error.code', 'invalid_api_key');
        $this->postJson('/api/v1/chat', ['message' => 'hi'])->assertStatus(401);
    }

    public function test_a_revoked_key_is_refused(): void
    {
        [$key, $plain] = ApiKey::issueSecret($this->tenant());
        $key->forceFill(['revoked_at' => now()])->save();

        $this->postJson('/api/v1/chat', ['message' => 'hi'], ['Authorization' => "Bearer {$plain}"])->assertStatus(401);
    }

    public function test_it_answers_and_counts_the_message(): void
    {
        $tenant = $this->tenant();
        [, $plain] = ApiKey::issueSecret($tenant);
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['content' => [['type' => 'text', 'text' => 'We open at 9.']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1000, 'output_tokens' => 100]])
            ->push(['content' => [['type' => 'text', 'text' => 'Also on Fridays.']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1000, 'output_tokens' => 100]]);

        $res =$this->postJson('/api/v1/chat', ['message' => 'When do you open?', 'user_ref' => 'c-1'], ['Authorization' => "Bearer {$plain}"])
            ->assertOk()
            ->assertJsonPath('reply', 'We open at 9.');

        $conversation = Conversation::where('uuid', $res->json('conversation_id'))->firstOrFail();
        $this->assertSame($tenant->id, $conversation->tenant_id);
        $this->assertSame('c-1', $conversation->user_ref);
        $this->assertSame(1, $conversation->user_messages);
        $this->assertSame(1, $tenant->fresh()->messagesUsed());
        // Haiku 5.5: 1000 × $0.10 + 100 × $0.50 per million
        $this->assertEqualsWithDelta(0.00015, (float) UsageDaily::first()->cost, 0.0000001);

        Http::assertSent(fn (Request $r) => $r['model'] === 'claude-haiku-5-5' && $r->hasHeader('x-api-key', 'test-key'));

        // The same conversation continues, with the earlier messages sent along
        $this->postJson('/api/v1/chat', ['message' => 'Fridays?', 'conversation_id' => $conversation->uuid], ['Authorization' => "Bearer {$plain}"])
            ->assertOk()->assertJsonPath('conversation_id', $conversation->uuid);
        $this->assertSame(2, $conversation->fresh()->user_messages);
        $this->assertCount(3, Http::recorded()[1][0]['messages']);

        $this->getJson("/api/v1/conversations/{$conversation->uuid}", ['Authorization' => "Bearer {$plain}"])
            ->assertOk()
            ->assertJsonPath('messages.0.text', 'When do you open?')
            ->assertJsonPath('messages.3.text', 'Also on Fridays.');
    }

    public function test_an_ended_subscription_stops_the_chat(): void
    {
        [, $plain] = ApiKey::issueSecret($this->tenant(active: false));
        Http::fake();

        $this->postJson('/api/v1/chat', ['message' => 'hi'], ['Authorization' => "Bearer {$plain}"])
            ->assertStatus(402)->assertJsonPath('error.code', 'subscription_inactive');
        Http::assertNothingSent();
    }

    public function test_a_suspended_client_is_stopped(): void
    {
        [, $plain] = ApiKey::issueSecret($this->tenant(['status' => 'suspended']));

        $this->postJson('/api/v1/chat', ['message' => 'hi'], ['Authorization' => "Bearer {$plain}"])->assertStatus(403);
    }

    public function test_the_monthly_quota_stops_the_chat_and_warns_once(): void
    {
        Mail::fake();
        $tenant = $this->tenant(messages: 2);
        User::create(['name' => 'Owner', 'email' => 'owner@shop.test', 'password' => 'secret123', 'tenant_id' => $tenant->id]);
        [, $plain] = ApiKey::issueSecret($tenant);
        $this->claudeAnswers('ok');

        $this->postJson('/api/v1/chat', ['message' => 'one'], ['Authorization' => "Bearer {$plain}"])->assertOk();
        $this->postJson('/api/v1/chat', ['message' => 'two'], ['Authorization' => "Bearer {$plain}"])->assertOk();
        $this->postJson('/api/v1/chat', ['message' => 'three'], ['Authorization' => "Bearer {$plain}"])
            ->assertStatus(429)->assertJsonPath('error.code', 'quota_exceeded');

        $this->assertNotNull($tenant->fresh()->warned_for);
    }

    public function test_the_agent_searches_only_its_own_clients_knowledge(): void
    {
        $tenant = $this->tenant();
        $other = Tenant::create(['name' => 'Other', 'status' => 'active']);
        $this->knowledge($tenant, 'Delivery to Cairo takes two days.', 'Delivery');
        $this->knowledge($other, 'Delivery secret of the other company.', 'Delivery');
        [, $plain] = ApiKey::issueSecret($tenant);

        Http::fakeSequence('api.anthropic.com/*')
            ->push([
                'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'search_knowledge', 'input' => ['query' => 'delivery Cairo']]],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 500, 'output_tokens' => 20],
            ])
            ->push([
                'content' => [['type' => 'text', 'text' => 'Two days to Cairo.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 600, 'output_tokens' => 30],
            ]);

        $this->postJson('/api/v1/chat', ['message' => 'How long is delivery to Cairo?'], ['Authorization' => "Bearer {$plain}"])
            ->assertOk()->assertJsonPath('reply', 'Two days to Cairo.');

        $recorded = Http::recorded();
        $second = $recorded[1][0];
        $result = collect($second['messages'])->last()['content'][0];
        $this->assertSame('tool_result', $result['type']);
        $this->assertStringContainsString('two days', $result['content']);
        $this->assertStringNotContainsString('other company', $result['content']);
        // Tool rounds are counted as one customer message, with all their tokens
        $this->assertSame(1, $tenant->fresh()->messagesUsed());
        $this->assertSame(1100, (int) UsageDaily::first()->input_tokens);
    }

    public function test_a_conversation_of_another_client_cannot_be_used(): void
    {
        $tenant = $this->tenant();
        $other = Tenant::create(['name' => 'Other', 'status' => 'active']);
        $theirs = Conversation::create(['tenant_id' => $other->id]);
        [, $plain] = ApiKey::issueSecret($tenant);
        Http::fake();

        $this->postJson('/api/v1/chat', ['message' => 'hi', 'conversation_id' => $theirs->uuid], ['Authorization' => "Bearer {$plain}"])->assertStatus(404);
        $this->getJson("/api/v1/conversations/{$theirs->uuid}", ['Authorization' => "Bearer {$plain}"])->assertStatus(404);
    }

    public function test_the_widget_works_only_from_allowed_domains(): void
    {
        $tenant = $this->tenant();
        $key = ApiKey::issuePublic($tenant, ['shop.test']);
        $this->claudeAnswers('Hello!');

        $this->postJson('/api/v1/widget/chat', ['key' => $key->token, 'message' => 'hi'], ['Origin' => 'https://evil.test'])
            ->assertStatus(403)->assertJsonPath('error.code', 'origin_not_allowed');
        $this->postJson('/api/v1/widget/chat', ['key' => $key->token, 'message' => 'hi'])->assertStatus(403);
        $this->postJson('/api/v1/widget/chat', ['key' => 'pk_live_wrong', 'message' => 'hi'], ['Origin' => 'https://shop.test'])->assertStatus(401);

        $this->postJson('/api/v1/widget/chat', ['key' => $key->token, 'message' => 'hi'], ['Origin' => 'https://www.shop.test'])
            ->assertOk()->assertJsonPath('reply', 'Hello!');
        $this->assertSame('widget', Conversation::first()->channel);
    }

    public function test_a_failed_claude_call_gives_a_clean_error(): void
    {
        [, $plain] = ApiKey::issueSecret($this->tenant());
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'authentication_error']], 401)]);

        $this->postJson('/api/v1/chat', ['message' => 'hi'], ['Authorization' => "Bearer {$plain}"])
            ->assertStatus(503)->assertJsonPath('error.code', 'unavailable');
    }

    public function test_the_secret_key_is_stored_hashed(): void
    {
        [$key, $plain] = ApiKey::issueSecret($this->tenant());

        $this->assertStringStartsWith('sk_live_', $plain);
        $this->assertNull($key->token);
        $this->assertSame(hash('sha256', $plain), $key->fresh()->hash);
    }
}
