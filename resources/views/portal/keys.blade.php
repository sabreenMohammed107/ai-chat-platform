@extends('layouts.app')
@section('title', __('API keys'))
@section('content')
<h1>{{ __('API keys') }}</h1>

@if (session('new_secret'))
    <div class="alert key">
        <strong>{{ __('Copy your new key now. It will not be shown again.') }}</strong>
        <pre>{{ session('new_secret') }}</pre>
    </div>
@endif

<div class="card">
    <h2>{{ __('Secret keys (for your server or app backend)') }}</h2>
    <p class="small muted">{{ __('Keep them on your server only. Never put a secret key in a website page or a mobile app.') }}</p>
    <table>
        <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Key') }}</th><th>{{ __('Created') }}</th><th>{{ __('Last used') }}</th><th></th></tr></thead>
        <tbody>
        @forelse ($secrets as $key)
            <tr>
                <td>{{ $key->name ?? '—' }}</td>
                <td><code class="copy">{{ $key->prefix }}…</code></td>
                <td class="small muted">{{ $key->created_at->format('Y-m-d') }}</td>
                <td class="small muted">{{ $key->last_used_at?->diffForHumans() ?? __('Never') }}</td>
                <td><form method="post" action="{{ route('portal.keys.revoke', $key) }}" onsubmit="return confirm(@js(__('Revoke this key? Apps using it will stop working.')))">@csrf @method('delete')<button class="btn sm danger">{{ __('Revoke') }}</button></form></td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">{{ __('No secret keys yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <form method="post" action="{{ route('portal.keys.secret') }}" class="row" style="margin-top:12px">
        @csrf
        <input type="text" name="name" class="grow" placeholder="{{ __('Name, e.g. Production server') }}" maxlength="100">
        <button class="btn">{{ __('Create secret key') }}</button>
    </form>
</div>

<div class="card">
    <h2>{{ __('Website widget key') }}</h2>
    <p class="small muted">{{ __('Public: it appears in your website code. It works only on the domains below.') }}</p>
    @if ($public)
        <p><code class="copy">{{ $public->token }}</code></p>
    @endif
    <form method="post" action="{{ route('portal.keys.domains') }}">
        @csrf @method('put')
        <label for="domains">{{ __('Allowed domains (one per line; subdomains included)') }}</label>
        <textarea id="domains" name="domains" class="copy" placeholder="example.com">{{ implode("\n", $public?->allowed_domains ?? []) }}</textarea>
        <button class="btn">{{ __('Save domains') }}</button>
    </form>
    <form method="post" action="{{ route('portal.keys.public') }}" style="margin-top:10px" onsubmit="return confirm(@js(__('Make a new widget key? The old one stops working.')))">
        @csrf<button class="btn sm light">{{ __('Make a new widget key') }}</button>
    </form>
</div>
@endsection
