@extends('layouts.app')
@section('title', __('Overview'))
@section('content')
<h1>{{ __('Overview') }}</h1>

@php
    $pct = $allowed > 0 ? min(100, round($used / $allowed * 100)) : 0;
    $max = max(1, collect($days)->max('messages'));
@endphp

<div class="grid">
    <div class="stat">
        <div class="l">{{ __('Plan') }}</div>
        <div class="n">{{ $subscription?->plan?->name ?? '—' }}</div>
        @if ($active)
            <span class="badge active">{{ __('Active') }}</span>
            <div class="small muted">{{ __('Until') }} {{ $active->ends_at->toDateString() }}</div>
        @elseif ($subscription)
            <span class="badge expired">{{ __('Expired') }}</span>
            <div class="small muted">{{ __('Ended on') }} {{ $subscription->ends_at->toDateString() }}</div>
        @else
            <span class="badge">{{ __('No subscription') }}</span>
        @endif
    </div>
    <div class="stat">
        <div class="l">{{ __('Messages this month') }}</div>
        <div class="n">{{ number_format($used) }} <span class="small muted">/ {{ number_format($allowed) }}</span></div>
        <div class="bar {{ $pct >= 100 ? 'full' : ($pct >= 80 ? 'warn' : '') }}"><span style="width: {{ $pct }}%"></span></div>
        @if ($periodEnd)<div class="small muted">{{ __('Resets after') }} {{ $periodEnd->toDateString() }}</div>@endif
    </div>
    <div class="stat">
        <div class="l">{{ __('Conversations') }}</div>
        <div class="n">{{ number_format($conversations) }}</div>
    </div>
    <div class="stat">
        <div class="l">{{ __('Knowledge') }}</div>
        <div class="n">{{ number_format($passages) }}</div>
        <div class="small muted">{{ __(':n sources', ['n' => $sources]) }}</div>
    </div>
</div>

<div class="card">
    <h2>{{ __('Messages, last 30 days') }}</h2>
    <div class="chart">
        @foreach ($days as $day)
            <span style="height: {{ round($day['messages'] / $max * 100) }}%" title="{{ $day['date'] }}: {{ $day['messages'] }}"></span>
        @endforeach
    </div>
    <div class="row small muted chart-dates"><span class="grow">{{ $days[0]['date'] }}</span><span>{{ end($days)['date'] }}</span></div>
</div>

<div class="card">
    <h2>{{ __('Try your assistant') }}</h2>
    <p class="small muted">{{ __('Messages here are real and count in your plan.') }}</p>
    <div id="try-log"></div>
    <form id="try-form" class="row">
        <input type="text" id="try-input" class="grow" placeholder="{{ __('Ask something a customer would ask...') }}" maxlength="{{ config('chat.max_message_length') }}" required>
        <button class="btn">{{ __('Send') }}</button>
        <button type="button" class="btn light" id="try-reset">{{ __('New chat') }}</button>
    </form>
</div>

<div class="card">
    <h2>{{ __('Latest conversations') }}</h2>
    @forelse ($recent as $c)
        <div class="row" style="border-bottom:1px solid var(--line);padding:8px 0">
            <a class="grow" href="{{ route('portal.conversations.show', $c->uuid) }}">{{ \Illuminate\Support\Str::limit(collect($c->transcript())->firstWhere('role', 'user')['text'] ?? '…', 90) }}</a>
            <span class="badge">{{ $c->channel }}</span>
            <span class="small muted">{{ $c->updated_at->diffForHumans() }}</span>
        </div>
    @empty
        <p class="muted">{{ __('No conversations yet.') }}</p>
    @endforelse
</div>
@endsection

@push('scripts')
<script>
(function () {
    const log = document.getElementById('try-log');
    const form = document.getElementById('try-form');
    const input = document.getElementById('try-input');
    let reset = false;
    function add(role, text) {
        const div = document.createElement('div');
        div.className = 'msg ' + role;
        div.textContent = text;
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
        return div;
    }
    document.getElementById('try-reset').onclick = () => { log.innerHTML = ''; reset = true; };
    form.onsubmit = async (e) => {
        e.preventDefault();
        const text = input.value.trim();
        if (!text) return;
        add('user', text);
        input.value = '';
        const wait = add('assistant', '…');
        try {
            const res = await fetch(@json(route('portal.try')), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify({ message: text, reset: reset }),
            });
            reset = false;
            const data = await res.json();
            wait.textContent = data.reply || data.error || data.message || 'Error';
        } catch (err) {
            wait.textContent = 'Error';
        }
    };
})();
</script>
@endpush
