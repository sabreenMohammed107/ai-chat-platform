<?php

namespace App\Jobs;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Services\Knowledge\Chunker;
use App\Services\Knowledge\WebsiteCrawler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a knowledge source into searchable passages: crawls a website, or splits a file's text or an FAQ. The old
 * passages are replaced only when the new ones are ready, so the agent keeps answering during a re-sync.
 */
class SyncKnowledgeSource implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public KnowledgeSource $source) {}

    public function uniqueId(): string
    {
        return (string) $this->source->id;
    }

    public function handle(WebsiteCrawler $crawler, Chunker $chunker): void
    {
        $source = $this->source->fresh();
        if (! $source) {
            return;
        }
        $source->update(['status' => 'syncing', 'error' => null]);

        try {
            $passages = [];
            $pages = 0;
            if ($source->type === 'website') {
                $plan = $source->tenant->activeSubscription()?->plan;
                $seen = [];
                foreach ($crawler->crawl((string) $source->url, $plan?->max_pages ?? (int) config('chat.crawl.default_pages')) as $page) {
                    $pages++;
                    foreach ($chunker->split($page['text']) as $text) {
                        // Text repeated on every page (a banner, a sidebar) is kept once
                        $hash = md5($text);
                        if (isset($seen[$hash])) {
                            continue;
                        }
                        $seen[$hash] = true;
                        $passages[] = ['url' => $page['url'], 'title' => $page['title'], 'content' => $text];
                    }
                }
            } elseif ($source->type === 'faq') {
                $pages = 1;
                $passages[] = ['url' => null, 'title' => $source->title, 'content' => "Q: {$source->title}\nA: {$source->content}"];
            } else {
                $pages = 1;
                foreach ($chunker->split((string) $source->content) as $text) {
                    $passages[] = ['url' => null, 'title' => $source->title, 'content' => $text];
                }
            }

            DB::transaction(function () use ($source, $passages, $pages) {
                $source->chunks()->delete();
                $now = now();
                foreach (array_chunk($passages, 200) as $rows) {
                    KnowledgeChunk::insert(array_map(fn ($p) => $p + [
                        'tenant_id' => $source->tenant_id,
                        'knowledge_source_id' => $source->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $rows));
                }
                $source->update(['status' => 'ready', 'pages' => $pages, 'synced_at' => now(), 'error' => null]);
            });
        } catch (Throwable $e) {
            Log::warning('Knowledge sync failed', ['source' => $source->id, 'error' => $e->getMessage()]);
            $source->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }
}
