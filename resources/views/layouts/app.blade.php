<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php($isAdminArea = request()->is('admin', 'admin/*'))
    <title>@yield('title') · {{ $isAdminArea ? __('Admin') : __('Client portal') }} · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="{{ $isAdminArea ? 'area-admin' : 'area-portal' }}">
<div class="shell">
    <aside class="side">
        <div class="logo">{{ config('app.name') }}</div>
        @if ($isAdminArea)
            <div class="area-tag admin">{{ __('Admin panel') }}</div>
            <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard', 'admin.tenants.*') ? 'on' : '' }}">{{ __('Clients') }}</a>
            <a href="{{ route('admin.plans') }}" class="{{ request()->routeIs('admin.plans*') ? 'on' : '' }}">{{ __('Plans') }}</a>
            <form method="post" action="{{ route('admin.logout') }}">@csrf<button class="btn sm light">{{ __('Sign out') }}</button></form>
        @else
            <div class="area-tag portal">{{ __('Client portal') }}</div>
            <div class="sub">{{ $tenant->name }}</div>
            <a href="{{ route('portal.dashboard') }}" class="{{ request()->routeIs('portal.dashboard') ? 'on' : '' }}">{{ __('Overview') }}</a>
            <a href="{{ route('portal.conversations') }}" class="{{ request()->routeIs('portal.conversations*') ? 'on' : '' }}">{{ __('Conversations') }}</a>
            <a href="{{ route('portal.knowledge') }}" class="{{ request()->routeIs('portal.knowledge*') ? 'on' : '' }}">{{ __('Knowledge') }}</a>
            <a href="{{ route('portal.settings') }}" class="{{ request()->routeIs('portal.settings*') ? 'on' : '' }}">{{ __('Assistant settings') }}</a>
            <a href="{{ route('portal.keys') }}" class="{{ request()->routeIs('portal.keys*') ? 'on' : '' }}">{{ __('API keys') }}</a>
            <a href="{{ route('portal.integration') }}" class="{{ request()->routeIs('portal.integration') ? 'on' : '' }}">{{ __('How to connect') }}</a>
            <form method="post" action="{{ route('portal.logout') }}">@csrf<button class="btn sm light">{{ ($adminView ?? false) ? __('Close client portal') : __('Sign out') }}</button></form>
        @endif
        <p class="lang"><a href="?lang=ar">العربية</a> · <a href="?lang=en">English</a></p>
    </aside>
    <main class="main">
        @if (! $isAdminArea && ($adminView ?? false))
            <div class="alert key">{{ __('You are viewing this client\'s portal as the admin. Changes here apply to the client.') }}</div>
        @endif
        @if (session('status'))<div class="alert">{{ session('status') }}</div>@endif
        @if ($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
        @yield('content')
    </main>
</div>
@stack('scripts')
</body>
</html>
