<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $tenant = $request->attributes->get('tenant');
        $conversations = $tenant->conversations()
            ->when($request->query('channel'), fn ($q, $c) => $q->where('channel', $c))
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString();

        return view('portal.conversations', ['conversations' => $conversations]);
    }

    public function show(Request $request, string $uuid)
    {
        $conversation = $request->attributes->get('tenant')->conversations()->where('uuid', $uuid)->firstOrFail();

        return view('portal.conversation', ['conversation' => $conversation]);
    }
}
