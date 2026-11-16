@extends('layouts.app')
@section('title', $client->name)
@section('content')
<p><a href="{{ route('admin.dashboard') }}">← {{ __('Clients') }}</a></p>
<div class="row" style="margin-bottom:14px">
    <h1 class="grow" style="margin:0">{{ $client->name }}</h1>
    <form method="post" action="{{ route('admin.tenants.open', $client) }}" target="_blank">@csrf<button class="btn light">{{ __('Open portal') }}</button></form>
</div>

@if (session('credentials'))
    <div class="alert key">
        <strong>{{ __('Login for the client (shown once):') }}</strong>
        <pre>{{ route('portal.login') }}
{{ session('credentials')['email'] }}
{{ session('credentials')['password'] }}</pre>
    </div>
@endif

<div class="grid">
    <div class="stat"><div class="l">{{ __('Messages this month') }}</div><div class="n">{{ number_format($used) }} <span class="small muted">/ {{ number_format($allowed) }}</span></div></div>
    <div class="stat"><div class="l">{{ __('Claude cost, last 30 days (USD)') }}</div><div class="n">${{ number_format($cost30, 2) }}</div></div>
    <div class="stat"><div class="l">{{ __('Status') }}</div><div class="n"><span class="badge {{ $client->status }}">{{ __(ucfirst($client->status)) }}</span></div></div>
</div>

<div class="card">
    <h2>{{ __('Subscriptions') }}</h2>
    <table>
        <thead><tr><th>{{ __('Plan') }}</th><th>{{ __('From') }}</th><th>{{ __('Until') }}</th><th></th></tr></thead>
        <tbody>
        @forelse ($client->subscriptions as $s)
            <tr><td>{{ $s->plan->name }}</td><td>{{ $s->starts_at->toDateString() }}</td><td>{{ $s->ends_at->toDateString() }}</td>
                <td>@if ($s->isActive())<span class="badge active">{{ __('Active') }}</span>@elseif ($s->starts_at->isFuture())<span class="badge pending">{{ __('Upcoming') }}</span>@endif</td></tr>
        @empty
            <tr><td colspan="4" class="muted">—</td></tr>
        @endforelse
        </tbody>
    </table>
    <form method="post" action="{{ route('admin.tenants.subscribe', $client) }}" class="row" style="margin-top:12px;align-items:flex-end">
        @csrf
        <div class="grow"><label for="plan_id">{{ __('Plan') }}</label>
            <select id="plan_id" name="plan_id">@foreach ($plans as $p)<option value="{{ $p->id }}" @selected($client->subscription?->plan_id === $p->id)>{{ $p->name }}</option>@endforeach</select></div>
        <div><label for="months">{{ __('Months') }}</label><input type="number" id="months" name="months" value="1" min="1" max="36"></div>
        <div><label for="starts_at">{{ __('Starts (empty: after the current one)') }}</label><input type="date" id="starts_at" name="starts_at"></div>
        <button class="btn">{{ __('Add subscription') }}</button>
    </form>
</div>

<div class="card">
    <h2>{{ __('Client details') }}</h2>
    <form method="post" action="{{ route('admin.tenants.update', $client) }}">
        @csrf @method('put')
        <div class="row">
            <div class="grow"><label for="name">{{ __('Name') }}</label><input type="text" id="name" name="name" value="{{ old('name', $client->name) }}" required></div>
            <div><label for="status">{{ __('Status') }}</label>
                <select id="status" name="status"><option value="active" @selected($client->status === 'active')>{{ __('Active') }}</option><option value="suspended" @selected($client->status === 'suspended')>{{ __('Suspended') }}</option></select></div>
        </div>
        <label for="website">{{ __('Website') }}</label>
        <input type="text" inputmode="url" id="website" name="website" value="{{ old('website', $client->website) }}" class="copy" placeholder="safer.travel">
        @error('website')<div class="field-error">{{ $message }}</div>@enderror
        <p class="small muted">{{ __('Its domain is added to the widget\'s allowed domains.') }}</p>
        <label for="assistant_name">{{ __('Assistant name') }}</label>
        <input type="text" id="assistant_name" name="assistant_name" value="{{ old('assistant_name', $client->assistant_name) }}">
        <label for="contact_info">{{ __('How customers reach them when the assistant cannot help') }}</label>
        <textarea id="contact_info" name="contact_info">{{ old('contact_info', $client->contact_info) }}</textarea>
        @error('contact_info')<div class="field-error">{{ $message }}</div>@enderror
        <label for="instructions">{{ __('Instructions for the assistant') }}</label>
        <textarea id="instructions" name="instructions" style="min-height:140px">{{ old('instructions', $client->instructions) }}</textarea>
        @error('instructions')<div class="field-error">{{ $message }}</div>@enderror
        @if ($errors->any())<div class="alert error" style="margin:10px 0 0">{{ __('Not saved:') }} {{ $errors->first() }}</div>@endif
        <p><button class="btn">{{ __('Save') }}</button></p>
    </form>
</div>

<div class="card">
    <h2>{{ __('Dashboard users') }}</h2>
    <table>
        @foreach ($client->users as $u)<tr><td>{{ $u->name }}</td><td class="copy">{{ $u->email }}</td></tr>@endforeach
    </table>
    <form method="post" action="{{ route('admin.tenants.users', $client) }}" class="row" style="margin-top:12px">
        @csrf
        <input type="text" name="name" placeholder="{{ __('Name') }}" required class="grow">
        <input type="email" name="email" placeholder="{{ __('Email') }}" required class="grow">
        <button class="btn">{{ __('Add user') }}</button>
    </form>
</div>
@endsection
