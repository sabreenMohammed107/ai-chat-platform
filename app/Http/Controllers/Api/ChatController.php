<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\Chat\Agent;
use App\Services\Chat\Usage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The public chat API: the client's server (secret key) or the website widget (public key, allowed domains only).
 */
class ChatController extends Controller
{
    public function __construct(private Agent $agent, private Usage $usage) {}

    /** POST /api/v1/chat {message, conversation_id?, user_ref?} */
    public function store(Request $request): JsonResponse
    {
        return $this->answer($request, $request->attributes->get('tenant'), 'api');
    }

    /** GET /api/v1/conversations/{uuid} */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $conversation = Conversation::where('tenant_id', $request->attributes->get('tenant')->id)->where('uuid', $uuid)->first();
        if (! $conversation) {
            return $this->error(404, 'not_found', 'Conversation not found.');
        }

        return response()->json([
            'conversation_id' => $conversation->uuid,
            'user_ref' => $conversation->user_ref,
            'messages' => $conversation->transcript(),
        ]);
    }

    /** POST /api/v1/widget/chat {key, message, conversation_id?}: from the website widget */
    public function widget(Request $request): JsonResponse
    {
        $key = ApiKey::findPublic((string) $request->input('key'));
        if (! $key) {
            return $this->error(401, 'invalid_api_key', 'Invalid widget key.');
        }
        if (! $key->allowsOrigin($request->headers->get('Origin'))) {
            return $this->error(403, 'origin_not_allowed', 'This website is not allowed to use this chat.');
        }
        $key->forceFill(['last_used_at' => now()])->saveQuietly();

        return $this->answer($request, $key->tenant, 'widget');
    }

    private function answer(Request $request, Tenant $tenant, string $channel): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:'.(int) config('chat.max_message_length')],
            'conversation_id' => ['nullable', 'string', 'max:64'],
            'user_ref' => ['nullable', 'string', 'max:191'],
        ]);

        if ($blocked = $this->usage->blocked($tenant)) {
            return $this->error($blocked['status'], $blocked['code'], $blocked['message']);
        }

        $conversation = null;
        if (filled($data['conversation_id'] ?? null)) {
            $conversation = Conversation::where('tenant_id', $tenant->id)->where('uuid', $data['conversation_id'])->first();
            if (! $conversation) {
                return $this->error(404, 'conversation_not_found', 'Conversation not found. Leave conversation_id out to start a new one.');
            }
        }
        $conversation ??= Conversation::create([
            'tenant_id' => $tenant->id,
            'channel' => $channel,
            'user_ref' => $data['user_ref'] ?? null,
        ]);

        try {
            $reply = $this->agent->reply($tenant, $conversation, trim($data['message']));
        } catch (Throwable $e) {
            Log::error('Chat answer failed', ['tenant' => $tenant->id, 'conversation' => $conversation->id, 'error' => $e->getMessage()]);

            return $this->error(503, 'unavailable', 'The assistant is not available right now. Please try again shortly.');
        }

        return response()->json([
            'conversation_id' => $conversation->uuid,
            'reply' => $reply,
        ]);
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
