<?php

namespace App\Services\Chat;

use App\Models\KnowledgeChunk;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds the passages of one client's knowledge that match a question. MySQL: FULLTEXT (natural language). Elsewhere,
 * or when FULLTEXT finds nothing (short words, Arabic prefixes): the passages with the most matching words.
 * Always scoped to the one tenant: a client's agent never sees another client's knowledge.
 */
class KnowledgeSearch
{
    /** @return Collection<int, KnowledgeChunk> */
    public function search(Tenant $tenant, string $query, ?int $limit = null): Collection
    {
        $limit ??= (int) config('chat.search_results');
        $query = trim(mb_substr($query, 0, 300));
        if ($query === '') {
            return collect();
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $found = KnowledgeChunk::where('tenant_id', $tenant->id)
                ->whereFullText(['title', 'content'], $query)
                ->limit($limit)
                ->get();
            if ($found->isNotEmpty()) {
                return $found;
            }
        }

        return $this->byWords($tenant, $query, $limit);
    }

    private function byWords(Tenant $tenant, string $query, int $limit): Collection
    {
        $words = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)))
            ->map(fn ($w) => $this->stem($w))
            ->filter(fn ($w) => mb_strlen($w) >= 3)
            ->unique()
            ->take(8)
            ->values();
        if ($words->isEmpty()) {
            return collect();
        }

        $candidates = KnowledgeChunk::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->orWhere('content', 'like', '%'.addcslashes($word, '%_\\').'%')
                        ->orWhere('title', 'like', '%'.addcslashes($word, '%_\\').'%');
                }
            })
            ->limit(200)
            ->get();

        return $candidates
            ->map(function (KnowledgeChunk $chunk) use ($words) {
                $text = mb_strtolower($chunk->title.' '.$chunk->title.' '.$chunk->content);
                $chunk->score = $words->sum(fn ($w) => min(5, mb_substr_count($text, $w)));

                return $chunk;
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    /** Drops the Arabic article and common English plural endings, so "the cabins" finds "cabin". */
    private function stem(string $word): string
    {
        $word = preg_replace('/^(وال|بال|فال|كال|ال)(?=\p{L}{3,})/u', '', $word);

        return preg_replace('/(?<=\p{L}{3})(es|s)$/u', '', $word);
    }
}
