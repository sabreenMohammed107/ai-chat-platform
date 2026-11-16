<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Sign in') }} · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="login">
    <div class="card">
        <h1>{{ config('app.name') }}</h1>
        @if ($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
        <form method="post" action="{{ url('/login') }}">
            @csrf
            <label for="email">{{ __('Email') }}</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus>
            <label for="password">{{ __('Password') }}</label>
            <input type="password" id="password" name="password" required>
            <p class="row"><label class="row" style="font-weight:400;margin:0"><input type="checkbox" name="remember" value="1"> {{ __('Remember me') }}</label></p>
            <button class="btn">{{ __('Sign in') }}</button>
        </form>
    </div>
    <p class="lang" style="text-align:center"><a href="?lang=ar">العربية</a> · <a href="?lang=en">English</a></p>
</div>
</body>
</html>
