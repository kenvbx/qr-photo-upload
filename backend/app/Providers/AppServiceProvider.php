<?php

namespace App\Providers;

use App\Services\WriterSafeDatabaseStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Cache::extend('database', function ($app, array $config) {
            $store = new WriterSafeDatabaseStore(
                $app['db']->connection($config['connection'] ?? null),
                $config['table'],
                $config['prefix'] ?? $app['config']['cache.prefix'],
                $config['lock_table'] ?? 'cache_locks',
                $config['lock_lottery'] ?? [2, 100],
                $config['lock_timeout'] ?? 86400,
                $config['serializable_classes'] ?? $app['config']['cache.serializable_classes'] ?? null,
            );
            $store->setLockConnection($app['db']->connection($config['lock_connection'] ?? $config['connection'] ?? null));

            return Cache::repository($store, $config);
        });
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        RateLimiter::for('collection-create', fn (Request $r) => Limit::perMinute(20)->by($r->user()->id));
        RateLimiter::for('photo-selection', fn (Request $r) => Limit::perMinute(20)->by($r->user()->id));
        RateLimiter::for('guest-chunks', fn (Request $r) => Limit::perMinute(240)->by($r->ip()));
        RateLimiter::for('album-view', fn (Request $r) => Limit::perMinute(300)->by($r->ip()));
        RateLimiter::for('guest-view', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
        RateLimiter::for('guest-upload', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
    }
}
