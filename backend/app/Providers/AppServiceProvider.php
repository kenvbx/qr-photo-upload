<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('collection-create', fn (Request $r) => Limit::perMinute(20)->by($r->user()->id));
        RateLimiter::for('guest-view', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
        RateLimiter::for('guest-upload', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
    }
}
