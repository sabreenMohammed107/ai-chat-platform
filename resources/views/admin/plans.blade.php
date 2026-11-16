@extends('layouts.app')
@section('title', __('Plans'))
@section('content')
<h1>{{ __('Plans') }}</h1>
<p class="muted">{{ __('Model: empty uses the default (:model). Cost per message depends on the model and how long the answers are.', ['model' => config('chat.default_model')]) }}</p>

@foreach ($plans as $plan)
<div class="card">
    <form method="post" action="{{ route('admin.plans.update', $plan) }}">
        @csrf @method('put')
        @include('admin.partials.plan-fields', ['plan' => $plan])
        <div class="row" style="margin-top:10px">
            <button class="btn">{{ __('Save') }}</button>
            <span class="small muted">{{ __(':n subscriptions', ['n' => $plan->subscriptions_count]) }}</span>
        </div>
    </form>
</div>
@endforeach

<div class="card">
    <h2>{{ __('New plan') }}</h2>
    <form method="post" action="{{ route('admin.plans.store') }}">
        @csrf
        @include('admin.partials.plan-fields', ['plan' => null])
        <button class="btn" style="margin-top:10px">{{ __('Create plan') }}</button>
    </form>
</div>
@endsection
