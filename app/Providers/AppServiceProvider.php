<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Per secret key
        RateLimiter::for('chat-api', fn (Request $request) => Limit::perMinute((int) config('chat.rate_limit'))
            ->by('key:'.optional($request->attributes->get('api_key'))->id));

        // Per widget key and per visitor IP, so one visitor cannot use up a site's limit
        RateLimiter::for('chat-widget', fn (Request $request) => [
            Limit::perMinute((int) config('chat.rate_limit') * 4)->by('widget:'.$request->input('key')),
            Limit::perMinute(10)->by('widget-ip:'.$request->ip()),
        ]);
    }
}
