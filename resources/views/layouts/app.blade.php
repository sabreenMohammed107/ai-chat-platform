<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="shell">
    <aside class="side">
        <div class="logo">{{ config('app.name') }}</div>
        @if (auth()->user()->is_admin)
            <div class="sub">{{ __('Admin') }}</div>
            <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard', 'admin.tenants.*') ? 'on' : '' }}">{{ __('Clients') }}</a>
            <a href="{{ route('admin.plans') }}" class="{{ request()->routeIs('admin.plans*') ? 'on' : '' }}">{{ __('Plans') }}</a>
        @endif
        @isset($tenant)
            <div class="sub" style="margin-top:14px">{{ $tenant->name }}</div>
            <a href="{{ route('portal.dashboard') }}" class="{{ request()->routeIs('portal.dashboard') ? 'on' : '' }}">{{ __('Overview') }}</a>
            <a href="{{ route('portal.conversations') }}" class="{{ request()->routeIs('portal.conversations*') ? 'on' : '' }}">{{ __('Conversations') }}</a>
            <a href="{{ route('portal.knowledge') }}" class="{{ request()->routeIs('portal.knowledge*') ? 'on' : '' }}">{{ __('Knowledge') }}</a>
            <a href="{{ route('portal.settings') }}" class="{{ request()->routeIs('portal.settings*') ? 'on' : '' }}">{{ __('Assistant settings') }}</a>
            <a href="{{ route('portal.keys') }}" class="{{ request()->routeIs('portal.keys*') ? 'on' : '' }}">{{ __('API keys') }}</a>
            <a href="{{ route('portal.integration') }}" class="{{ request()->routeIs('portal.integration') ? 'on' : '' }}">{{ __('How to connect') }}</a>
            @if (auth()->user()->is_admin)
                <form method="post" action="{{ route('admin.close') }}">@csrf<button class="btn sm light">{{ __('Close client portal') }}</button></form>
            @endif
        @endisset
        <form method="post" action="{{ route('logout') }}">@csrf<button class="btn sm light">{{ __('Sign out') }}</button></form>
        <p class="lang"><a href="?lang=ar">العربية</a> · <a href="?lang=en">English</a></p>
    </aside>
    <main class="main">
        @if (session('status'))<div class="alert">{{ session('status') }}</div>@endif
        @if ($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
        @yield('content')
    </main>
</div>
@stack('scripts')
</body>
</html>
