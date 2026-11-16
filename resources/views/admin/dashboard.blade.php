@extends('layouts.app')
@section('title', __('Clients'))
@section('content')
<div class="row" style="margin-bottom:14px">
    <h1 class="grow" style="margin:0">{{ __('Clients') }}</h1>
    <a class="btn" href="{{ route('admin.tenants.create') }}">+ {{ __('New client') }}</a>
</div>

<div class="grid">
    <div class="stat"><div class="l">{{ __('Active clients') }}</div><div class="n">{{ $totals['active'] }} <span class="small muted">/ {{ $totals['clients'] }}</span></div></div>
    <div class="stat"><div class="l">{{ __('Monthly plan prices (active)') }}</div><div class="n">{{ number_format($totals['revenue'], 2) }}</div></div>
    <div class="stat"><div class="l">{{ __('Claude cost, last 30 days (USD)') }}</div><div class="n">${{ number_format($totals['cost'], 2) }}</div></div>
    <div class="stat"><div class="l">{{ __('Messages, last 30 days') }}</div><div class="n">{{ number_format($totals['messages']) }}</div></div>
</div>

<div class="card">
    <table>
        <thead><tr><th>{{ __('Client') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Until') }}</th><th>{{ __('Messages this month') }}</th><th>{{ __('Cost 30 days') }}</th><th>{{ __('Price') }}</th><th></th></tr></thead>
        <tbody>
        @forelse ($tenants as $t)
            @php($active = $t->activeSubscription())
            <tr>
                <td><a href="{{ route('admin.tenants.edit', $t) }}">{{ $t->name }}</a> @if (! $t->isActive())<span class="badge suspended">{{ __('Suspended') }}</span>@endif
                    @if ($t->website)<div class="small muted copy">{{ parse_url($t->website, PHP_URL_HOST) }}</div>@endif</td>
                <td>{{ $t->subscription?->plan?->name ?? '—' }}</td>
                <td>@if ($active){{ $active->ends_at->toDateString() }}@elseif ($t->subscription)<span class="badge expired">{{ __('Expired') }}</span>@else — @endif</td>
                <td>{{ number_format($t->messagesUsed()) }} / {{ number_format($t->messagesAllowed()) }}</td>
                <td>${{ number_format((float) $t->cost_30, 2) }}</td>
                <td>{{ $t->subscription?->plan ? number_format((float) $t->subscription->plan->price, 2) : '—' }}</td>
                <td><form method="post" action="{{ route('admin.tenants.open', $t) }}" target="_blank">@csrf<button class="btn sm light">{{ __('Open portal') }}</button></form></td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">{{ __('No clients yet.') }} @if (\App\Models\Plan::count() === 0)<a href="{{ route('admin.plans') }}">{{ __('Create a plan first.') }}</a>@endif</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
