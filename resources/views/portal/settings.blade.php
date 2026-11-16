@extends('layouts.app')
@section('title', __('Assistant settings'))
@section('content')
<h1>{{ __('Assistant settings') }}</h1>
<div class="card">
    <form method="post" action="{{ route('portal.settings.update') }}">
        @csrf @method('put')
        <label for="assistant_name">{{ __('Assistant name') }}</label>
        <input type="text" id="assistant_name" name="assistant_name" value="{{ old('assistant_name', $tenant->assistant_name) }}" placeholder="{{ __('e.g. Sara from :name', ['name' => $tenant->name]) }}" maxlength="100">

        <label for="contact_info">{{ __('How customers reach you when the assistant cannot help') }}</label>
        <textarea id="contact_info" name="contact_info" maxlength="1000" placeholder="WhatsApp: +20 ...&#10;Email: support@...">{{ old('contact_info', $tenant->contact_info) }}</textarea>

        <label for="instructions">{{ __('Instructions for the assistant') }}</label>
        <textarea id="instructions" name="instructions" maxlength="4000" style="min-height:160px" placeholder="{{ __('e.g. Be warm and short. Always suggest our app for orders. Never promise delivery dates.') }}">{{ old('instructions', $tenant->instructions) }}</textarea>
        <p class="small muted">{{ __('Facts belong in Knowledge. Here: tone, what to suggest, what to avoid.') }}</p>

        <button class="btn">{{ __('Save') }}</button>
    </form>
</div>
@endsection
