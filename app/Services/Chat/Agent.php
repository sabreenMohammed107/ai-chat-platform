<?php

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\Tenant;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * One customer message → one answer, for one client:
 *
 *   send the conversation + the search_knowledge tool to Claude
 *   while Claude answers with tool_use: search this client's knowledge, send the passages back
 *   the final text is the answer
 *
 * The whole exchange (tool calls included) is saved on the conversation, so the next message has the context.
 */
class Agent
{
    public const FALLBACK = "Sorry, I can't answer that right now. Please try again in a moment.";

    public function __construct(
        private ClaudeClient $claude,
        private SystemPrompt $prompt,
        private KnowledgeSearch $search,
        private Usage $usage,
    ) {}

    public function reply(Tenant $tenant, Conversation $conversation, string $message): string
    {
        $model = $tenant->activeSubscription()?->plan?->modelId() ?? (string) config('chat.default_model');
        $messages = $conversation->messages ?? [];
        $messages[] = ['role' => 'user', 'content' => $message, 'at' => now()->toIso8601String()];
        $turnStart = count($messages);
        // Where the history sent to Claude starts: fixed for the whole turn (moving it between tool rounds would
        // change what came before this turn's thinking, and the API rejects that)
        $from = $this->windowStart($messages);

        $system = $this->prompt->blocks($tenant);
        $tools = [$this->toolDefinition()];
        $input = 0;
        $output = 0;
        $answer = null;

        for ($round = 0; $round <= (int) config('chat.max_tool_rounds'); $round++) {
            $response = $this->ask($model, $system, $tools, $this->window($messages, $turnStart, $from), $conversation);
            $usage = (array) ($response['usage'] ?? []);
            $input += (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0);
            $output += (int) ($usage['output_tokens'] ?? 0);

            $content = array_values((array) ($response['content'] ?? []));
            $calls = array_filter($content, fn ($b) => ($b['type'] ?? null) === 'tool_use');
            if (($response['stop_reason'] ?? null) !== 'tool_use' || $calls === []) {
                // The answer (or a cut-off one). A tool call that won't be run is not kept: a tool call with no
                // result after it would make the next request invalid.
                $content = array_values(array_filter($content, fn ($b) => ($b['type'] ?? null) !== 'tool_use'));
                if ($content !== [] && ($response['stop_reason'] ?? null) !== 'refusal') {
                    $messages[] = ['role' => 'assistant', 'content' => $content, 'at' => now()->toIso8601String()];
                    $answer = $this->text($content);
                }
                break;
            }
            $messages[] = ['role' => 'assistant', 'content' => $content, 'at' => now()->toIso8601String()];

            $results = [];
            foreach ($calls as $call) {
                $results[] = $this->runTool($tenant, $call);
            }
            $messages[] = ['role' => 'user', 'content' => $results, 'at' => now()->toIso8601String()];
        }

        if ($answer === null || trim($answer) === '') {
            // Out of rounds, refused or empty: close the turn so the history stays valid for the next message
            $answer = self::FALLBACK;
            if (end($messages)['role'] === 'assistant') {
                array_pop($messages);
            }
            $messages[] = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $answer]], 'at' => now()->toIso8601String()];
        }

        $conversation->messages = $messages;
        $this->usage->record($tenant, $conversation, $model, $input, $output);

        return trim($answer);
    }

    private function toolDefinition(): array
    {
        return [
            'name' => 'search_knowledge',
            'description' => "Searches the business's knowledge (its web pages, documents and FAQs) and returns the "
                .'passages that best match, each with its page title and URL. Use it before answering any question '
                .'about the business. Query with a few key words, e.g. "delivery time Cairo" or "سعر الاشتراك".',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Key words to search for'],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
            'strict' => true,
            'cache_control' => ['type' => 'ephemeral'],
        ];
    }

    /** @return array<string, mixed> the tool_result block */
    private function runTool(Tenant $tenant, array $call): array
    {
        $result = ['type' => 'tool_result', 'tool_use_id' => $call['id']];
        if (($call['name'] ?? null) !== 'search_knowledge') {
            return $result + ['content' => 'Unknown tool.', 'is_error' => true];
        }

        $query = is_string($call['input']['query'] ?? null) ? $call['input']['query'] : '';
        $passages = $this->search->search($tenant, $query)->map(fn ($chunk) => [
            'title' => $chunk->title,
            'url' => $chunk->url,
            'text' => $chunk->content,
        ])->values()->all();

        $content = $passages === []
            ? ['passages' => [], 'note' => 'Nothing found for this query.']
            : ['passages' => $passages];

        return $result + ['content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    /**
     * Sends to Claude. When Claude says the request is invalid (400: a thinking block it no longer accepts, or a
     * history that went wrong some other way), tries again without thinking blocks, then with the last message alone,
     * so the customer still gets an answer. Network errors, overload and rate limits are retried by ClaudeClient.
     */
    private function ask(string $model, array $system, array $tools, array $sent, Conversation $conversation): array
    {
        try {
            return $this->claude->send($model, $system, $tools, $sent);
        } catch (RequestException $e) {
            if ($e->response->status() !== 400) {
                throw $e;
            }
            Log::warning('Chat request refused, trying without thinking', ['conversation' => $conversation->id, 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        try {
            return $this->claude->send($model, $system, $tools, $this->withoutThinking($sent));
        } catch (RequestException $e) {
            if ($e->response->status() !== 400) {
                throw $e;
            }
            Log::warning('Chat request refused again, sending the last message alone', ['conversation' => $conversation->id, 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        $text = '';
        foreach (array_reverse($sent) as $m) {
            if ($m['role'] === 'user' && is_string($m['content'])) {
                $text = $m['content'];
                break;
            }
        }

        return $this->claude->send($model, $system, $tools, [['role' => 'user', 'content' => $text !== '' ? $text : '…']]);
    }

    private function withoutThinking(array $messages): array
    {
        return array_map(function (array $m) {
            if ($m['role'] === 'assistant' && is_array($m['content'])) {
                $kept = array_values(array_filter($m['content'], fn ($b) => ! in_array($b['type'] ?? null, ['thinking', 'redacted_thinking'], true)));
                $m['content'] = $kept !== [] ? $kept : [['type' => 'text', 'text' => '…']];
            }

            return $m;
        }, $messages);
    }

    /** Index of the first message to send: the latest history_messages, moved on to a customer's own words. */
    private function windowStart(array $messages): int
    {
        $from = max(0, count($messages) - max(2, (int) config('chat.history_messages')));
        for ($i = $from; $i < count($messages); $i++) {
            if ($messages[$i]['role'] === 'user' && is_string($messages[$i]['content'])) {
                return $i;
            }
        }

        return count($messages) - 1;
    }

    /**
     * The messages sent to Claude, in the API's shape (our "at" times left out). Thinking blocks go back only for this
     * turn's tool rounds: older ones are tied to what came before them, and leaving them all out is allowed.
     */
    private function window(array $messages, int $turnStart, int $from): array
    {
        $sent = [];
        foreach (array_slice($messages, $from, null, true) as $i => $m) {
            unset($m['at']);
            if ($i < $turnStart && $m['role'] === 'assistant' && is_array($m['content'])) {
                $m = $this->withoutThinking([$m])[0];
            }
            if (is_array($m['content'])) {
                // Saved as JSON, an empty tool input {} comes back as []; the API wants an object
                $m['content'] = array_map(fn ($b) => ($b['type'] ?? null) === 'tool_use' && ($b['input'] ?? []) === [] ? ['input' => (object) []] + $b : $b, $m['content']);
            }
            $sent[] = $m;
        }

        return $sent;
    }

    private function text(array $content): string
    {
        return implode("\n\n", array_map(
            fn ($b) => (string) $b['text'],
            array_filter($content, fn ($b) => ($b['type'] ?? null) === 'text' && isset($b['text'])),
        ));
    }
}
