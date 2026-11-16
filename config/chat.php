<?php

/*
|--------------------------------------------------------------------------
| AI chat platform (Claude)
|--------------------------------------------------------------------------
| Every client (tenant) gets a chat agent that answers from the knowledge collected from their website, files and
| FAQs. Their app calls POST /api/v1/chat with their secret key, or their website loads /widget.js with their public
| key. All calls go to Claude with ANTHROPIC_API_KEY (the platform's own Console workspace).
*/

return [

    'api_key' => env('ANTHROPIC_API_KEY'),
    'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
    'api_version' => '2023-06-01',

    // Used when a plan has no model of its own
    'default_model' => env('CHAT_MODEL', 'claude-opus-5-5'),
    'max_tokens' => (int) env('CHAT_MAX_TOKENS', 4096),
    // low | medium | high (empty: the model's default). Chat answers rarely need more than medium.
    'effort' => env('CHAT_EFFORT'),
    'timeout' => (int) env('CHAT_TIMEOUT', 90),

    // When Claude declines a request (stop_reason "refusal"), the API re-runs it on another model in the same call.
    // Only these models accept it.
    'fallback_models' => ['claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5', 'claude-fable-5-2', 'claude-fable-5-1'],

    // Claude ↔ tools round trips for one message
    'max_tool_rounds' => 5,
    // Latest messages of a conversation sent each time
    'history_messages' => 30,
    'max_message_length' => 2000,
    // Knowledge passages returned by one search
    'search_results' => 6,

    // Requests per minute per key
    'rate_limit' => (int) env('CHAT_RATE_LIMIT', 30),

    // Website crawling
    'crawl' => [
        'timeout' => 15,
        'chunk_chars' => 1500,
        'user_agent' => 'AIChatPlatformBot/1.0',
        // Never crawl private / internal addresses (turned off only in tests, whose fake hosts don't resolve)
        'block_private' => (bool) env('CHAT_CRAWL_BLOCK_PRIVATE', true),
        // Pages read when the client has no active plan (the admin syncing before the subscription starts)
        'default_pages' => 50,
    ],

    // Share of the monthly messages after which the client is emailed (once a period)
    'warn_at' => 0.8,

    // USD per million tokens, for the cost shown in the admin panel. Cache reads/writes are counted as input.
    'prices' => [
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00],
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50],
    ],

];
