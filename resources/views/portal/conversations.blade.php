@extends('layouts.app')
@section('title', __('Conversations'))
@section('content')
<h1>{{ __('Conversations') }}</h1>
<div class="tabs">
    <a class="btn sm {{ request('channel') ? 'light' : '' }}" href="{{ route('portal.conversations') }}">{{ __('All') }}</a>
    @foreach (['api' => __('Your app (API)'), 'widget' => __('Website widget'), 'test' => __('Tests')] as $key => $label)
        <a class="btn sm {{ request('channel') === $key ? '' : 'light' }}" href="{{ route('portal.conversations', ['channel' => $key]) }}">{{ $label }}</a>
    @endforeach
</div>
<div class="card">
    <table>
        <thead><tr><th>{{ __('First message') }}</th><th>{{ __('Channel') }}</th><th>{{ __('User') }}</th><th>{{ __('Messages') }}</th><th>{{ __('Last activity') }}</th></tr></thead>
        <tbody>
        @forelse ($conversations as $c)
            <tr>
                <td><a href="{{ route('portal.conversations.show', $c->uuid) }}">{{ \Illuminate\Support\Str::limit(collect($c->transcript())->firstWhere('role', 'user')['text'] ?? '…', 80) }}</a></td>
                <td><span class="badge">{{ $c->channel }}</span></td>
                <td class="small">{{ $c->user_ref ?? '—' }}</td>
                <td>{{ $c->user_messages }}</td>
                <td class="small muted">{{ $c->updated_at->format('Y-m-d H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">{{ __('No conversations yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $conversations->links() }}
</div>
@endsection
