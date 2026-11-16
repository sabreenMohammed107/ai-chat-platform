<?php

namespace Tests\Feature;

use App\Jobs\SyncKnowledgeSource;
use App\Models\KnowledgeSource;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Chat\KnowledgeSearch;
use App\Services\Knowledge\SafeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        $plan = Plan::create(['name' => 'Basic', 'monthly_messages' => 100, 'price' => 20, 'max_pages' => 5]);
        $tenant = Tenant::create(['name' => 'Shop', 'status' => 'active', 'website' => 'https://shop.test']);
        $tenant->subscriptions()->create(['plan_id' => $plan->id, 'starts_at' => today(), 'ends_at' => today()->addMonth()]);

        return $tenant;
    }

    public function test_a_website_is_crawled_into_passages(): void
    {
        config(['chat.crawl.block_private' => false]);
        $tenant = $this->tenant();
        Http::fake([
            'shop.test/sitemap.xml' => Http::response('<?xml version="1.0"?><urlset><url><loc>https://shop.test/shipping</loc></url></urlset>', 200, ['Content-Type' => 'application/xml']),
            'shop.test/shipping' => Http::response('<html><head><title>Shipping</title></head><body><nav>Menu Home About</nav><main><h1>Shipping</h1><p>Delivery to Cairo takes two days and costs 50 EGP.</p></main><footer>Copyright</footer></body></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']),
            'shop.test/about' => Http::response('<html><head><title>About</title></head><body><p>Shop was founded in 2010 in Alexandria, Egypt, by two friends.</p></body></html>', 200, ['Content-Type' => 'text/html']),
            'shop.test/' => Http::response('<html><head><title>Home</title></head><body><p>Welcome to Shop, the best place for gifts in Egypt.</p><a href="/about">About</a><a href="https://other.test/x">Out</a><a href="logo.png">img</a></body></html>', 200, ['Content-Type' => 'text/html']),
            'other.test/*' => Http::response('should not be read', 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);

        $source = $tenant->sources()->create(['type' => 'website', 'title' => 'shop.test', 'url' => 'https://shop.test']);
        SyncKnowledgeSource::dispatchSync($source);

        $source->refresh();
        $this->assertSame('ready', $source->status, (string) $source->error);
        $this->assertSame(3, $source->pages);
        $all = $source->chunks()->pluck('content')->implode("\n");
        $this->assertStringContainsString('two days', $all);
        $this->assertStringContainsString('founded in 2010', $all);
        $this->assertStringNotContainsString('Copyright', $all);
        $this->assertStringNotContainsString('Menu Home', $all);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'other.test') || str_contains($r->url(), 'logo.png'));

        $found = app(KnowledgeSearch::class)->search($tenant, 'delivery cairo');
        $this->assertStringContainsString('two days', $found->first()->content);
        $this->assertSame('https://shop.test/shipping', $found->first()->url);
    }

    public function test_a_failed_crawl_keeps_the_old_passages(): void
    {
        config(['chat.crawl.block_private' => false]);
        $tenant = $this->tenant();
        $source = $tenant->sources()->create(['type' => 'website', 'title' => 'shop.test', 'url' => 'https://shop.test']);
        $source->chunks()->create(['tenant_id' => $tenant->id, 'content' => 'Old text']);
        Http::fake(['*' => Http::response('', 500)]);

        SyncKnowledgeSource::dispatchSync($source);

        $this->assertSame('failed', $source->fresh()->status);
        $this->assertSame(1, $source->chunks()->count());
    }

    public function test_private_addresses_are_never_crawled(): void
    {
        config(['chat.crawl.block_private' => true]);

        $this->assertFalse(SafeUrl::isAllowed('http://127.0.0.1/admin'));
        $this->assertFalse(SafeUrl::isAllowed('http://169.254.169.254/latest/meta-data'));
        $this->assertFalse(SafeUrl::isAllowed('http://192.168.1.1'));
        $this->assertFalse(SafeUrl::isAllowed('file:///etc/passwd'));
        $this->assertFalse(SafeUrl::isAllowed('http://localhost:8000'));
        $this->assertTrue(SafeUrl::isAllowed('https://8.8.8.8/'));
    }

    public function test_arabic_questions_find_arabic_passages(): void
    {
        $tenant = $this->tenant();
        $source = KnowledgeSource::create(['tenant_id' => $tenant->id, 'type' => 'faq', 'title' => 'الشحن', 'status' => 'ready']);
        $source->chunks()->create(['tenant_id' => $tenant->id, 'title' => 'الشحن', 'content' => 'الشحن للقاهرة بياخد يومين وتكلفته 50 جنيه.']);
        $source->chunks()->create(['tenant_id' => $tenant->id, 'title' => 'المرتجعات', 'content' => 'تقدر ترجع المنتج خلال 14 يوم.']);

        $found = app(KnowledgeSearch::class)->search($tenant, 'كام يوم الشحن للقاهرة؟');

        $this->assertStringContainsString('يومين', $found->first()->content);
    }
}
