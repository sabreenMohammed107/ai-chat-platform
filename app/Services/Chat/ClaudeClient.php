<?php

namespace App\Services\Chat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Claude Messages API (POST /v1/messages), through Laravel's HTTP client so tests can Http::fake() it.
 */
class ClaudeClient
{
    /**
     * @param  list<array<string, mixed>>  $system
     * @param  list<array<string, mixed>>  $tools
     * @param  list<array<string, mixed>>  $messages
     * @return array<string, mixed> the API response: content blocks, stop_reason, usage
     *
     * @throws RequestException
     */
    public function send(string $model, array $system, array $tools, array $messages): array
    {
        $payload = [
            'model' => $model,
            'max_tokens' => (int) config('chat.max_tokens'),
            'system' => $system,
            'messages' => $messages,
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
        }
        if (filled(config('chat.effort'))) {
            $payload['output_config'] = ['effort' => (string) config('chat.effort')];
        }

        $headers = [
            'x-api-key' => (string) config('chat.api_key'),
            'anthropic-version' => (string) config('chat.api_version'),
        ];
        // When the model declines a request, the API answers with a fallback model in the same call
        if (in_array($model, (array) config('chat.fallback_models'), true)) {
            $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
            $payload['fallbacks'] = 'default';
        }

        return Http::baseUrl(rtrim((string) config('chat.base_url'), '/'))
            ->withHeaders($headers)
            ->acceptJson()
            ->timeout((int) config('chat.timeout'))
            ->retry(2, 1000, fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 529], true)), throw: true)
            ->post('/v1/messages', $payload)
            ->throw()
            ->json();
    }
}
