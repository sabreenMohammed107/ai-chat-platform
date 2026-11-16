@extends('layouts.app')
@section('title', __('How to connect'))
@section('content')
@php($api = url('/api/v1'))
<h1>{{ __('How to connect') }}</h1>
<p class="muted">{{ __('Send this page to your developer. There are two ways: the website widget (one line), or the API (for your app\'s own chat screen).') }}</p>

<div class="card">
    <h2>1. {{ __('Website widget') }}</h2>
    <p>{{ __('Paste this line before </body> on every page of your website. A chat bubble appears in the corner.') }}</p>
<pre>&lt;script src="{{ url('/widget.js') }}" data-key="{{ $public->token }}" data-lang="ar" defer&gt;&lt;/script&gt;</pre>
    <p class="small muted">{{ __('Optional: data-lang="en", data-color="#4f46e5", data-title="Chat with us". The website must be one of the allowed domains in API keys.') }}</p>
    @if (empty($public->allowed_domains))
        <div class="alert error">{{ __('No allowed domain yet: add your website domain in API keys first.') }}</div>
    @endif
</div>

<div class="card">
    <h2>2. {{ __('API (your app or server)') }}</h2>
    <p>{{ __('Your server sends the customer\'s message and gets the answer. Use a secret key from API keys. Call it from your server, never from the app itself.') }}</p>
    <p><strong>POST</strong> <code class="copy">{{ $api }}/chat</code></p>
<pre>curl -X POST {{ $api }}/chat \
  -H "Authorization: Bearer sk_live_YOUR_SECRET_KEY" \
  -H "Content-Type: application/json" \
  -d '{"message": "What are your opening hours?", "user_ref": "customer-123"}'</pre>
    <p>{{ __('Answer:') }}</p>
<pre>{
  "conversation_id": "9b1c6f1e-....",
  "reply": "We are open every day from 9 am to 10 pm."
}</pre>
    <p>{{ __('To continue the same conversation, send the conversation_id you got back:') }}</p>
<pre>{"message": "And on Fridays?", "conversation_id": "9b1c6f1e-...."}</pre>

    <h2 style="margin-top:20px">JavaScript (Node.js)</h2>
<pre>const res = await fetch("{{ $api }}/chat", {
  method: "POST",
  headers: {
    "Authorization": "Bearer " + process.env.CHAT_SECRET_KEY,
    "Content-Type": "application/json",
  },
  body: JSON.stringify({ message: text, conversation_id: conversationId }),
});
const data = await res.json();   // { conversation_id, reply }  or  { error: { code, message } }</pre>

    <h2 style="margin-top:20px">PHP</h2>
<pre>$res = Http::withToken(env('CHAT_SECRET_KEY'))
    ->post('{{ $api }}/chat', ['message' => $text, 'conversation_id' => $conversationId])
    ->json();</pre>

    <h2 style="margin-top:20px">{{ __('Other calls and errors') }}</h2>
    <table>
        <tr><td><code class="copy">GET {{ $api }}/conversations/{conversation_id}</code></td><td>{{ __('The messages of a conversation.') }}</td></tr>
        <tr><td><code>401 invalid_api_key</code></td><td>{{ __('The key is wrong or revoked.') }}</td></tr>
        <tr><td><code>402 subscription_inactive</code></td><td>{{ __('The subscription has ended.') }}</td></tr>
        <tr><td><code>429 quota_exceeded</code></td><td>{{ __('The monthly messages of the plan are used up.') }}</td></tr>
        <tr><td><code>429</code> ({{ __('no body code') }})</td><td>{{ __('Too many requests in one minute: wait and try again.') }}</td></tr>
        <tr><td><code>503 unavailable</code></td><td>{{ __('Temporary problem: try again shortly.') }}</td></tr>
    </table>
</div>
@endsection
