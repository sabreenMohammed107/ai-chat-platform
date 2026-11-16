@extends('layouts.app')
@section('title', __('New client'))
@section('content')
<p><a href="{{ route('admin.dashboard') }}">← {{ __('Clients') }}</a></p>
<h1>{{ __('New client') }}</h1>
@if ($plans->isEmpty())
    <div class="alert error">{{ __('Create a plan first.') }} <a href="{{ route('admin.plans') }}">{{ __('Plans') }}</a></div>
@else
<div class="card">
    <form method="post" action="{{ route('admin.tenants.store') }}">
        @csrf
        <h2>{{ __('Business') }}</h2>
        <label for="name">{{ __('Name') }}</label>
        <input type="text" id="name" name="name" value="{{ old('name') }}" required>
        <label for="website">{{ __('Website') }}</label>
        <input type="text" inputmode="url" id="website" name="website" value="{{ old('website') }}" placeholder="example.com" class="copy">
        @error('website')<div class="field-error">{{ $message }}</div>@enderror
        <label class="row" style="font-weight:400"><input type="checkbox" name="read_website" value="1" checked> {{ __('Read the website into the knowledge now') }}</label>

        <h2 style="margin-top:18px">{{ __('Subscription') }}</h2>
        <div class="row">
            <div class="grow">
                <label for="plan_id">{{ __('Plan') }}</label>
                <select id="plan_id" name="plan_id">
                    @foreach ($plans as $p)<option value="{{ $p->id }}" @selected(old('plan_id') == $p->id)>{{ $p->name }} — {{ number_format($p->monthly_messages) }} {{ __('messages') }} — {{ number_format((float) $p->price, 2) }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="months">{{ __('Months') }}</label>
                <input type="number" id="months" name="months" value="{{ old('months', 1) }}" min="1" max="36" required>
            </div>
        </div>

        <h2 style="margin-top:18px">{{ __('Their dashboard login') }}</h2>
        <label for="user_name">{{ __('Contact name') }}</label>
        <input type="text" id="user_name" name="user_name" value="{{ old('user_name') }}" required>
        <label for="user_email">{{ __('Email') }}</label>
        <input type="email" id="user_email" name="user_email" value="{{ old('user_email') }}" required>
        @error('user_email')<div class="field-error">{{ $message }}</div>@enderror
        <p class="small muted">{{ __('A password is made for them and shown once on the next page.') }}</p>

        <button class="btn">{{ __('Create client') }}</button>
    </form>
</div>
@endif
@endsection
