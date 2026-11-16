@extends('layouts.app')
@section('title', __('Conversation'))
@section('content')
<p><a href="{{ route('portal.conversations') }}">← {{ __('Conversations') }}</a></p>
<h1>{{ __('Conversation') }}</h1>
<p class="small muted">
    <span class="badge">{{ $conversation->channel }}</span>
    {{ $conversation->user_ref }} · {{ $conversation->created_at->format('Y-m-d H:i') }} · {{ __(':n messages', ['n' => $conversation->user_messages]) }}
    · <code class="copy">{{ $conversation->uuid }}</code>
</p>
<div class="card">
    @foreach ($conversation->transcript() as $line)
        <div class="msg {{ $line['role'] }}">{{ $line['text'] }}@if ($line['at'])<div class="t">{{ \Illuminate\Support\Carbon::parse($line['at'])->format('Y-m-d H:i') }}</div>@endif</div>
    @endforeach
</div>
@endsection
